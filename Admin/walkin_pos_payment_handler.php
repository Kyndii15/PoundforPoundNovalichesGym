<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

include '../config.php';
include '../includes/gcash_reference_validator.php';
include '../includes/activity_logger.php';

// Get current staff user ID from session
$staff_id = $_SESSION['user_id'] ?? null;

// Set content type to JSON
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log the start of payment processing
error_log("=== WALK-IN PAYMENT PROCESSING STARTED ===");

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("ERROR: Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Check if walk-in conversion data exists in session
if (!isset($_SESSION['temp_walkin_data'])) {
    error_log('No walk-in conversion data found in session');
    error_log('Session data: ' . print_r($_SESSION, true));
    echo json_encode(['success' => false, 'message' => 'No walk-in conversion data found. Please try the conversion process again.']);
    exit;
}

// Check if this is a payment processing request
if (!isset($_POST['process_walkin_payment'])) {
    error_log("ERROR: process_walkin_payment parameter not found");
    error_log("POST data: " . print_r($_POST, true));
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit;
}

$walkin_data = $_SESSION['temp_walkin_data'];
$full_name = $walkin_data['full_name'];
$email = $walkin_data['email'];
$phone = $walkin_data['phone'];
$address = $walkin_data['address'];
$password_plain = $walkin_data['password_plain'] ?? $walkin_data['password'];
$membership_plan = $walkin_data['membership_plan'];

// Get payment method and other form data
$payment_method = $_POST['payment_method'] ?? '';
$amount_paid = $_POST['amount_paid'] ?? 0;
$gcash_reference = trim($_POST['gcash_reference'] ?? '');
$gcash_screenshot_path = null;
$or_photo_path = null;

// Create directories if they don't exist
$gcash_screenshots_dir = '../assets/gcash_payments/';
if (!file_exists($gcash_screenshots_dir)) {
    mkdir($gcash_screenshots_dir, 0777, true);
    error_log("Created GCash screenshots directory: $gcash_screenshots_dir");
}

$or_photos_dir = '../uploads/or_photos/';
if (!file_exists($or_photos_dir)) {
    mkdir($or_photos_dir, 0777, true);
    error_log("Created OR photos directory: $or_photos_dir");
}

// Handle payment method specific uploads
if ($payment_method === 'cash') {
    // Handle cash receipt upload
    if (isset($_FILES['cash_receipt']) && $_FILES['cash_receipt']['error'] === UPLOAD_ERR_OK) {
        $receipt_file = $_FILES['cash_receipt'];
        $file_extension = strtolower(pathinfo($receipt_file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $receipt_filename = 'or_' . time() . '_' . uniqid() . '.' . $file_extension;
            $receipt_path = $or_photos_dir . $receipt_filename;
            
            if (move_uploaded_file($receipt_file['tmp_name'], $receipt_path)) {
                $or_photo_path = 'uploads/or_photos/' . $receipt_filename;
                error_log("Cash receipt uploaded successfully: $or_photo_path");
            } else {
                error_log("Failed to upload cash receipt");
                // Don't throw error, just log it - receipt is optional
            }
        } else {
            error_log("Invalid cash receipt file type: $file_extension");
            // Don't throw error, just log it - receipt is optional
        }
    } else {
        error_log("No cash receipt uploaded or upload error");
    }
} elseif ($payment_method === 'gcash') {
    if (empty($gcash_reference)) {
        echo json_encode([
            'success' => false,
            'message' => 'GCash reference number is required.'
        ]);
        exit;
    }
    
    // Check for duplicate GCash reference
    $validation_result = validateGCashReference($conn, $gcash_reference);
    if (!$validation_result['valid']) {
        echo json_encode([
            'success' => false,
            'message' => $validation_result['message']
        ]);
        exit;
    }
    
    // Handle GCash screenshot upload
    if (isset($_FILES['gcash_screenshot']) && $_FILES['gcash_screenshot']['error'] === UPLOAD_ERR_OK) {
        $screenshot_file = $_FILES['gcash_screenshot'];
        $file_extension = strtolower(pathinfo($screenshot_file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $screenshot_filename = 'gcash_' . time() . '_' . uniqid() . '.' . $file_extension;
            $screenshot_path = $gcash_screenshots_dir . $screenshot_filename;
            
            if (move_uploaded_file($screenshot_file['tmp_name'], $screenshot_path)) {
                $gcash_screenshot_path = $screenshot_filename;
                error_log("GCash screenshot uploaded successfully: $gcash_screenshot_path");
            } else {
                error_log("Failed to upload GCash screenshot");
                // Don't throw error, just log it - screenshot is optional
            }
        } else {
            error_log("Invalid GCash screenshot file type: $file_extension");
            // Don't throw error, just log it - screenshot is optional
        }
    } else {
        error_log("No GCash screenshot uploaded or upload error");
    }
}

// Calculate price from membership plan
$price = 0;
if (strpos($membership_plan, 'Boxing (1 Month)') !== false) $price = 2500;
elseif (strpos($membership_plan, 'Boxing (2 Months)') !== false) $price = 4000;
elseif (strpos($membership_plan, 'Boxing (3 Months)') !== false) $price = 6000;
elseif (strpos($membership_plan, 'Circuit Training (1 Month)') !== false) $price = 1700;
elseif (strpos($membership_plan, 'Circuit Training (3 Months)') !== false) $price = 2900;
elseif (strpos($membership_plan, 'Circuit Training (6 Months)') !== false) $price = 5500;
elseif (strpos($membership_plan, 'Circuit Training (1 Year)') !== false) $price = 9000;
elseif (strpos($membership_plan, 'Muay Thai (1 Month)') !== false) $price = 3000;
elseif (strpos($membership_plan, 'Muay Thai (2 Months)') !== false) $price = 5000;
elseif (strpos($membership_plan, 'Muay Thai (3 Months)') !== false) $price = 7000;

// Start transaction
mysqli_begin_transaction($conn);

try {
    // 1. Create user account (align with member payment handler)
    error_log("Creating user account for: $full_name, $email");
    $password_hash = password_hash($password_plain, PASSWORD_DEFAULT);
    $user_sql = "INSERT INTO users (full_name, email, phone, password_hash, password_plain, role, email_verified, created_at) VALUES (?, ?, ?, ?, ?, 'customer', 1, NOW())";
    if (!$user_stmt = mysqli_prepare($conn, $user_sql)) {
        throw new Exception('Failed to prepare user statement: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($user_stmt, 'sssss', $full_name, $email, $phone, $password_hash, $password_plain);
    if (!mysqli_stmt_execute($user_stmt)) {
        throw new Exception('Failed to create user account: ' . mysqli_error($conn));
    }
    $user_id = mysqli_insert_id($conn);
    mysqli_stmt_close($user_stmt);
    
    // 2. Create member record
    error_log("Creating member record for user_id: $user_id, phone: $phone, address: $address");
    // Use PHP's date function with Philippine timezone instead of MySQL NOW()
    date_default_timezone_set('Asia/Manila');
    $joined_at = date('Y-m-d H:i:s');
    $member_sql = "INSERT INTO members (user_id, phone, address, joined_at) VALUES (?, ?, ?, ?)";
    $member_stmt = mysqli_prepare($conn, $member_sql);
    
    if (!$member_stmt) {
        throw new Exception('Failed to prepare member statement: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($member_stmt, 'isss', $user_id, $phone, $address, $joined_at);
    
    if (!mysqli_stmt_execute($member_stmt)) {
        throw new Exception('Failed to create member record: ' . mysqli_error($conn));
    }
    
    $member_id = mysqli_insert_id($conn);
    mysqli_stmt_close($member_stmt);
    
    // 3. Create subscription record
    error_log("Creating subscription for member_id: $member_id, plan: $membership_plan");
    $subscription_sql = "INSERT INTO subscriptions (member_id, plan_name, start_date, expiry_date, status) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), 'active')";
    $subscription_stmt = mysqli_prepare($conn, $subscription_sql);
    
    if (!$subscription_stmt) {
        throw new Exception('Failed to prepare subscription statement: ' . mysqli_error($conn));
    }
    
    // Calculate duration based on plan (in days like member handler)
    $duration_days = 30; // Default to 1 month
    if (strpos($membership_plan, '2 Months') !== false) $duration_days = 60;
    elseif (strpos($membership_plan, '3 Months') !== false) $duration_days = 90;
    elseif (strpos($membership_plan, '6 Months') !== false) $duration_days = 180;
    elseif (strpos($membership_plan, '1 Year') !== false) $duration_days = 365;
    
    error_log("Subscription duration: $duration_days days");
    mysqli_stmt_bind_param($subscription_stmt, 'isi', $member_id, $membership_plan, $duration_days);
    
    if (!mysqli_stmt_execute($subscription_stmt)) {
        throw new Exception('Failed to create subscription: ' . mysqli_error($conn));
    }
    
    $subscription_id = mysqli_insert_id($conn);
    mysqli_stmt_close($subscription_stmt);
    
    // 4. Create payment record (if payments table exists)
    $payment_sql = "INSERT INTO payments (user_id, member_id, amount, payment_method, payment_date, status, receipt_path, reference_number) VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?)";
    $payment_stmt = mysqli_prepare($conn, $payment_sql);
    
    if ($payment_stmt) {
        $receipt_path = null; // No file upload in walk-in conversion
        $ref_number = $payment_method === 'gcash' ? $gcash_reference : 'CASH-' . date('YmdHis');
        
        mysqli_stmt_bind_param($payment_stmt, 'iidsss', $user_id, $member_id, $price, $payment_method, $receipt_path, $ref_number);
        
        if (!mysqli_stmt_execute($payment_stmt)) {
            // Payment table might not exist, continue without error
            error_log('Payment record creation failed: ' . mysqli_error($conn));
        } else {
            error_log('Payment record created successfully');
            
            // Record GCash reference in tracking table if payment method is GCash
            if ($payment_method === 'gcash' && !empty($gcash_reference)) {
                $payment_id = mysqli_insert_id($conn);
                $record_result = recordGCashReference($conn, $gcash_reference, 'walkin_payment', $payment_id, $user_id, $member_id, $price, 'completed');
                if (!$record_result['success']) {
                    error_log('Failed to record GCash reference: ' . $record_result['message']);
                    // Don't throw error, just log it
                }
            }
        }
        
        mysqli_stmt_close($payment_stmt);
    } else {
        error_log('Payment table does not exist or query failed: ' . mysqli_error($conn));
        
        // If payment table doesn't exist, still record GCash reference if applicable
        if ($payment_method === 'gcash' && !empty($gcash_reference)) {
            $record_result = recordGCashReference($conn, $gcash_reference, 'walkin_payment', null, $user_id, $member_id, $price, 'completed');
            if (!$record_result['success']) {
                error_log('Failed to record GCash reference: ' . $record_result['message']);
            }
        }
    }
    
    // 5. Create transaction record for revenue tracking
    // Ensure columns exist in transactions table
    $trans_column_check = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'or_photo_path'");
    if (mysqli_num_rows($trans_column_check) == 0) {
        mysqli_query($conn, "ALTER TABLE transactions ADD COLUMN or_photo_path VARCHAR(255) DEFAULT NULL");
    }
    $gcash_col_check = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'gcash_reference_number'");
    if (mysqli_num_rows($gcash_col_check) == 0) {
        mysqli_query($conn, "ALTER TABLE transactions ADD COLUMN gcash_reference_number VARCHAR(50) DEFAULT NULL");
    }
    
    // Determine or_photo_path: use cash receipt path for cash, or GCash screenshot path for GCash
    $transaction_or_path = null;
    if ($payment_method === 'cash' && $or_photo_path) {
        $transaction_or_path = $or_photo_path;
    } elseif ($payment_method === 'gcash' && $gcash_screenshot_path) {
        // Store GCash screenshot path in or_photo_path for revenue report
        $transaction_or_path = 'assets/gcash_payments/' . $gcash_screenshot_path;
    }
    
    $transaction_sql = "INSERT INTO transactions (user_id, type, amount, date, description, status, payment_method, plan_name, customer_name, gcash_reference_number, or_photo_path) VALUES (?, 'membership', ?, NOW(), ?, 'completed', ?, ?, ?, ?, ?)";
    $transaction_stmt = mysqli_prepare($conn, $transaction_sql);
    
    if ($transaction_stmt) {
        $transaction_desc = "Walk-in converted to member: {$membership_plan}";
        $gcash_ref = ($payment_method === 'gcash' && !empty($gcash_reference)) ? $gcash_reference : null;
        
        // Get user's full name
        $user_name_query = mysqli_query($conn, "SELECT full_name FROM users WHERE id = $user_id");
        $user_name_row = mysqli_fetch_assoc($user_name_query);
        $customer_name = $user_name_row['full_name'] ?? '';
        
        mysqli_stmt_bind_param($transaction_stmt, 'idssssss', $user_id, $price, $transaction_desc, $payment_method, $membership_plan, $customer_name, $gcash_ref, $transaction_or_path);
        
        if (!mysqli_stmt_execute($transaction_stmt)) {
            error_log('Transaction record creation failed: ' . mysqli_error($conn));
            // Don't throw error, just log it - revenue tracking is important but shouldn't break the flow
        } else {
            error_log('Transaction record created successfully for revenue tracking');
        }
        
        mysqli_stmt_close($transaction_stmt);
    } else {
        error_log('Failed to prepare transaction insert statement: ' . mysqli_error($conn));
    }
    
    // Commit transaction
    mysqli_commit($conn);
    error_log("Transaction committed successfully");
    
    // Log activity: Payment Transaction and New Member
    $transaction_id = mysqli_insert_id($conn);
    logActivity($conn, 'payment_transaction', "Payment Transaction: {$full_name} paid ₱{$price} for {$membership_plan} via {$payment_method} (Walk-in)", $staff_id, $transaction_id, 'transaction', ['amount' => $price, 'plan' => $membership_plan, 'method' => $payment_method, 'type' => 'walkin']);
    logActivity($conn, 'new_member', "New Member: {$full_name} ({$email}) registered with {$membership_plan} plan (Walk-in conversion)", $staff_id, $user_id, 'member', ['plan' => $membership_plan, 'email' => $email, 'type' => 'walkin']);
    
    // Record process history in pending_memberships table
    $current_datetime = date('Y-m-d H:i:s');
    $gcash_ref = ($payment_method === 'gcash' && !empty($gcash_reference)) ? $gcash_reference : null;
    $process_history_query = "INSERT INTO pending_memberships (user_id, plan_name, plan_price, payment_method, gcash_reference_number, gcash_screenshot_path, or_photo_path, status, requested_at, processed_at, processed_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, 'Walk-in converted to member')";
    $process_history_stmt = mysqli_prepare($conn, $process_history_query);
    if ($process_history_stmt) {
        // Parameters: user_id (i), plan_name (s), plan_price (d), payment_method (s), gcash_ref (s), gcash_screenshot_path (s), or_photo_path (s), requested_at (s), processed_at (s), processed_by (i)
        // 10 placeholders total, so type string should be 10 characters: "isdssssssi"
        mysqli_stmt_bind_param($process_history_stmt, "isdssssssi", $user_id, $membership_plan, $price, $payment_method, $gcash_ref, $gcash_screenshot_path, $or_photo_path, $current_datetime, $current_datetime, $staff_id);
        mysqli_stmt_execute($process_history_stmt);
        mysqli_stmt_close($process_history_stmt);
        error_log("Process history recorded in pending_memberships");
    }
    
    // Clear session data
    unset($_SESSION['temp_walkin_data']);
    unset($_SESSION['temp_walkin_id']);
    error_log("Session data cleared");
    
    // Return success response
    error_log("Returning JSON success response");
    echo json_encode([
        'success' => true,
        'message' => 'Walk-in conversion completed successfully! Member account has been created.',
        'account_details' => [
            'email' => $email,
            'password' => $password_plain
        ],
        'redirect' => 'walkin__log.php?success=1'
    ]);
    exit;
    
} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    
    // Log error
    $error_message = 'Walk-in conversion error: ' . $e->getMessage();
    error_log("ERROR: $error_message");
    
    // Return error response
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>