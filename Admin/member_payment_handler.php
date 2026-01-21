<?php
// Start output buffering to prevent any output before JSON
ob_start();

// Suppress error display but log them
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set error handler to catch fatal errors only (not warnings/notices)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Only handle fatal errors, let warnings/notices pass through
    if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR || $errno === E_COMPILE_ERROR) {
        error_log("PHP Fatal Error [$errno]: $errstr in $errfile on line $errline");
        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code(200); // Use 200 so JSON can be parsed
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'A fatal error occurred: ' . htmlspecialchars($errstr, ENT_QUOTES, 'UTF-8')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Log other errors but don't stop execution
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return false; // Let PHP handle it normally
}, E_ALL);

// Set exception handler to catch uncaught exceptions
set_exception_handler(function($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage());
    error_log("Stack trace: " . $exception->getTraceAsString());
    // Clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code(200); // Use 200 so JSON can be parsed
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

// Include required files before clearing buffer
require '../config.php';
@include '../includes/gcash_reference_validator.php';
// Activity logger is optional - don't fail if it doesn't exist
if (file_exists('../includes/activity_logger.php')) {
    @include '../includes/activity_logger.php';
}

// Clear any output that might have been generated
// Don't end the buffer, just clean it - we'll manage it properly
if (ob_get_level() > 0) {
    ob_clean();
}

// Check if database connection exists
if (!isset($conn) || !$conn) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]);
    ob_end_flush();
    exit;
}

// Get current staff user ID from session
$staff_id = $_SESSION['user_id'] ?? null;

// Log the start of payment processing
error_log("=== MEMBER PAYMENT PROCESSING STARTED ===");

// Handle payment processing
if (isset($_POST['process_payment'])) {
    error_log("POST data received: " . print_r($_POST, true));
    
    // Check if member_payment_data exists in session
    if (!isset($_SESSION['member_payment_data'])) {
        error_log("ERROR: member_payment_data not found in session");
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Session data not found. Please try again.'
        ]);
        ob_end_flush();
        exit;
    }
    
    $payment_method = $_POST['payment_method'] ?? '';
    $member_data = $_SESSION['member_payment_data'];
    
    error_log("Payment method: " . $payment_method);
    error_log("Member data: " . print_r($member_data, true));
    
    try {
        // Verify database connection is still valid
        if (!isset($conn) || !$conn || mysqli_ping($conn) === false) {
            throw new Exception('Database connection lost');
        }
        
        // Start transaction
        mysqli_begin_transaction($conn);
        error_log("Database transaction started");
        
        // Get member data
        $full_name = $member_data['full_name'] ?? '';
        $email = $member_data['email'] ?? '';
        $phone = $member_data['phone'] ?? '';
        $address = $member_data['address'] ?? '';
        $password_hash = $member_data['password'] ?? ''; // This is already hashed
        $password_plain = $member_data['password_plain'] ?? ''; // Get plain password
        $membership_plan = $member_data['membership_plan'] ?? '';
        
        error_log("Extracted data - Name: $full_name, Email: $email, Phone: $phone");
        error_log("Password hash present: " . (!empty($password_hash) ? 'Yes' : 'No'));
        error_log("Password plain present: " . (!empty($password_plain) ? 'Yes' : 'No'));
        error_log("Membership plan: $membership_plan");
        
        // Validate required fields with detailed error messages
        if (empty($full_name)) {
            throw new Exception('Full name is missing from session data');
        }
        if (empty($email)) {
            throw new Exception('Email is missing from session data');
        }
        if (empty($phone)) {
            throw new Exception('Phone number is missing from session data');
        }
        if (empty($password_hash)) {
            throw new Exception('Password hash is missing from session data');
        }
        if (empty($membership_plan)) {
            throw new Exception('Membership plan is missing from session data');
        }
        
        // Extract price from membership plan
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
        
        error_log("Calculated price: $price");
        
        // Create directories if they don't exist
        $or_photos_dir = '../uploads/or_photos/';
        if (!file_exists($or_photos_dir)) {
            mkdir($or_photos_dir, 0777, true);
            error_log("Created OR photos directory: $or_photos_dir");
        }
        
        // Handle file upload and GCash reference
        $uploaded_file = null;
        $or_photo_path = null;
        $gcash_reference = null;
        $gcash_screenshot_path = null;
        
        // Create GCash screenshots directory if it doesn't exist
        $gcash_screenshots_dir = '../assets/gcash_payments/';
        if (!file_exists($gcash_screenshots_dir)) {
            mkdir($gcash_screenshots_dir, 0777, true);
            error_log("Created GCash screenshots directory: $gcash_screenshots_dir");
        }
        
        if ($payment_method === 'cash' && isset($_FILES['cash_receipt'])) {
            $file = $_FILES['cash_receipt'];
            error_log("Processing cash receipt upload");
            if ($file['error'] === UPLOAD_ERR_OK) {
                $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $receipt_filename = 'or_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $receipt_path = $or_photos_dir . $receipt_filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $receipt_path)) {
                        $uploaded_file = $receipt_filename; // Keep for payments table
                        $or_photo_path = 'uploads/or_photos/' . $receipt_filename; // For pending_memberships table
                        error_log("Receipt uploaded successfully: $or_photo_path");
                    } else {
                        throw new Exception('Failed to upload receipt.');
                    }
                } else {
                    throw new Exception('Invalid file type. Only JPG, JPEG, PNG, GIF, and PDF files are allowed.');
                }
            }
        } elseif ($payment_method === 'gcash') {
            $gcash_reference = trim($_POST['gcash_reference'] ?? '');
            if (empty($gcash_reference)) {
                throw new Exception('GCash reference number is required.');
            }
            
            // Validate GCash reference for duplicates
            $validation_result = validateGCashReference($conn, $gcash_reference);
            if (!$validation_result['valid']) {
                throw new Exception($validation_result['message']);
            }
            
            error_log("GCash reference: $gcash_reference");
            
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
        
        // Create user account with all required fields
        error_log("Creating user account...");
        $user_sql = "INSERT INTO users (full_name, email, phone, password_hash, password_plain, role, email_verified, created_at) 
                     VALUES (?, ?, ?, ?, ?, 'customer', 1, NOW())";
        
        if ($user_stmt = mysqli_prepare($conn, $user_sql)) {
            mysqli_stmt_bind_param($user_stmt, 'sssss', $full_name, $email, $phone, $password_hash, $password_plain);
            
            if (!mysqli_stmt_execute($user_stmt)) {
                $error_msg = 'Failed to create user account: ' . mysqli_stmt_error($user_stmt);
                error_log("ERROR: $error_msg");
                throw new Exception($error_msg);
            }
            
            $user_id = mysqli_insert_id($conn);
            mysqli_stmt_close($user_stmt);
            error_log("User account created successfully with ID: $user_id");
        } else {
            $error_msg = 'Failed to prepare user insert statement: ' . mysqli_error($conn);
            error_log("ERROR: $error_msg");
            throw new Exception($error_msg);
        }
        
        // Insert into members table
        error_log("Creating member record...");
        // Use PHP's date function with Philippine timezone instead of MySQL NOW()
        date_default_timezone_set('Asia/Manila');
        $joined_at = date('Y-m-d H:i:s');
        // Ensure address is not null
        $address = $address ?? '';
        $member_sql = "INSERT INTO members (user_id, phone, address, joined_at) 
                       VALUES (?, ?, ?, ?)";
        
        if ($member_stmt = mysqli_prepare($conn, $member_sql)) {
            mysqli_stmt_bind_param($member_stmt, 'isss', $user_id, $phone, $address, $joined_at);
            
            if (!mysqli_stmt_execute($member_stmt)) {
                $error_msg = 'Failed to create member record: ' . mysqli_stmt_error($member_stmt);
                error_log("ERROR: $error_msg");
                throw new Exception($error_msg);
            }
            
            $member_id = mysqli_insert_id($conn);
            mysqli_stmt_close($member_stmt);
            error_log("Member record created successfully with ID: $member_id");
        } else {
            $error_msg = 'Failed to prepare member insert statement: ' . mysqli_error($conn);
            error_log("ERROR: $error_msg");
            throw new Exception($error_msg);
        }
        
        // Insert subscription record
        error_log("Creating subscription record...");
        $subscription_sql = "INSERT INTO subscriptions (member_id, plan_name, start_date, expiry_date, status) 
                             VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY), 'active')";
        
        // Calculate duration based on membership plan
        $duration_days = 30; // Default to 1 month
        if (strpos($membership_plan, '2 Months') !== false) $duration_days = 60;
        elseif (strpos($membership_plan, '3 Months') !== false) $duration_days = 90;
        elseif (strpos($membership_plan, '6 Months') !== false) $duration_days = 180;
        elseif (strpos($membership_plan, '1 Year') !== false) $duration_days = 365;
        
        if ($subscription_stmt = mysqli_prepare($conn, $subscription_sql)) {
            mysqli_stmt_bind_param($subscription_stmt, 'isi', $member_id, $membership_plan, $duration_days);
            
            if (!mysqli_stmt_execute($subscription_stmt)) {
                $error_msg = 'Failed to create subscription record: ' . mysqli_stmt_error($subscription_stmt);
                error_log("ERROR: $error_msg");
                throw new Exception($error_msg);
            }
            
            mysqli_stmt_close($subscription_stmt);
            error_log("Subscription record created successfully");
        } else {
            $error_msg = 'Failed to prepare subscription insert statement: ' . mysqli_error($conn);
            error_log("ERROR: $error_msg");
            throw new Exception($error_msg);
        }
        
        // Insert payment record
        error_log("Creating payment record...");
        $payment_sql = "INSERT INTO payments (user_id, member_id, amount, payment_method, payment_date, status, receipt_path, reference_number) 
                        VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?)";
        
        if ($payment_stmt = mysqli_prepare($conn, $payment_sql)) {
            // Ensure variables are properly defined for bind_param
            $receipt_path = $uploaded_file ?: null;
            $ref_number = $gcash_reference ?: null;
            mysqli_stmt_bind_param($payment_stmt, 'iidsss', $user_id, $member_id, $price, $payment_method, $receipt_path, $ref_number);
            
            if (!mysqli_stmt_execute($payment_stmt)) {
                $error_msg = 'Failed to create payment record: ' . mysqli_stmt_error($payment_stmt);
                error_log("ERROR: $error_msg");
                throw new Exception($error_msg);
            }
            
            // Record GCash reference in tracking table if payment method is GCash
            if ($payment_method === 'gcash' && !empty($gcash_reference)) {
                $payment_id = mysqli_insert_id($conn);
                $record_result = recordGCashReference($conn, $gcash_reference, 'member_payment', $payment_id, $user_id, $member_id, $price, 'completed');
                if (!$record_result['success']) {
                    error_log('Failed to record GCash reference: ' . $record_result['message']);
                    // Don't throw error, just log it
                }
            }
            
            mysqli_stmt_close($payment_stmt);
            error_log("Payment record created successfully");
        } else {
            $error_msg = 'Failed to prepare payment insert statement: ' . mysqli_error($conn);
            error_log("ERROR: $error_msg");
            throw new Exception($error_msg);
        }
        
        // Create transaction record for revenue tracking
        // Use only base columns that definitely exist - optional columns will be added if needed but won't break the process
        try {
            $transaction_desc = "New member account: {$membership_plan}";
            $gcash_ref = ($payment_method === 'gcash' && !empty($gcash_reference)) ? $gcash_reference : null;
            
            // First try with all columns (if they exist)
            // SQL has 8 placeholders: user_id, amount, description, payment_method, plan_name, customer_name, gcash_reference_number, or_photo_path
            $transaction_sql = "INSERT INTO transactions (user_id, type, amount, date, description, status, payment_method, plan_name, customer_name, gcash_reference_number, or_photo_path) VALUES (?, 'membership', ?, NOW(), ?, 'completed', ?, ?, ?, ?, ?)";
            $transaction_stmt = @mysqli_prepare($conn, $transaction_sql);
            
            if ($transaction_stmt) {
                // Determine or_photo_path
                $transaction_or_path = null;
                if ($payment_method === 'cash' && $or_photo_path) {
                    $transaction_or_path = $or_photo_path;
                } elseif ($payment_method === 'gcash' && $gcash_screenshot_path) {
                    $transaction_or_path = 'assets/gcash_payments/' . $gcash_screenshot_path;
                }
                
                // Bind 8 parameters: user_id(i), amount(d), description(s), payment_method(s), plan_name(s), customer_name(s), gcash_ref(s), or_path(s)
                mysqli_stmt_bind_param($transaction_stmt, 'idssssss', $user_id, $price, $transaction_desc, $payment_method, $membership_plan, $full_name, $gcash_ref, $transaction_or_path);
                
                if (!mysqli_stmt_execute($transaction_stmt)) {
                    // If it fails, try without optional columns
                    mysqli_stmt_close($transaction_stmt);
                    error_log('Transaction insert with optional columns failed, trying base columns only: ' . mysqli_error($conn));
                    
                    $transaction_sql = "INSERT INTO transactions (user_id, type, amount, date, description, status, payment_method, plan_name, customer_name) VALUES (?, 'membership', ?, NOW(), ?, 'completed', ?, ?, ?)";
                    $transaction_stmt = mysqli_prepare($conn, $transaction_sql);
                    if ($transaction_stmt) {
                        mysqli_stmt_bind_param($transaction_stmt, 'idssss', $user_id, $price, $transaction_desc, $payment_method, $membership_plan, $full_name);
                        if (!mysqli_stmt_execute($transaction_stmt)) {
                            error_log('Transaction record creation failed even with base columns: ' . mysqli_error($conn));
                        } else {
                            error_log('Transaction record created successfully with base columns');
                        }
                        mysqli_stmt_close($transaction_stmt);
                    }
                } else {
                    error_log('Transaction record created successfully for revenue tracking');
                    mysqli_stmt_close($transaction_stmt);
                }
            } else {
                // If prepare fails, try with base columns only
                error_log('Transaction insert prepare failed, trying base columns only: ' . mysqli_error($conn));
                $transaction_sql = "INSERT INTO transactions (user_id, type, amount, date, description, status, payment_method, plan_name, customer_name) VALUES (?, 'membership', ?, NOW(), ?, 'completed', ?, ?, ?)";
                $transaction_stmt = mysqli_prepare($conn, $transaction_sql);
                if ($transaction_stmt) {
                    mysqli_stmt_bind_param($transaction_stmt, 'idssss', $user_id, $price, $transaction_desc, $payment_method, $membership_plan, $full_name);
                    if (!mysqli_stmt_execute($transaction_stmt)) {
                        error_log('Transaction record creation failed: ' . mysqli_error($conn));
                    } else {
                        error_log('Transaction record created successfully with base columns');
                    }
                    mysqli_stmt_close($transaction_stmt);
                } else {
                    error_log('Failed to prepare transaction insert statement even with base columns: ' . mysqli_error($conn));
                }
            }
        } catch (Exception $trans_ex) {
            // Log but don't fail the whole process
            error_log('Transaction record creation exception: ' . $trans_ex->getMessage());
        }
        
        // Don't commit yet - we'll verify first, then commit
        error_log("All database operations completed, verifying account creation...");
        
        // Log activity: Payment Transaction and New Member
        // Get transaction ID before commit (if transaction was created)
        $transaction_id = null;
        try {
            // Try to get the last transaction ID if transaction was created
            $trans_check_sql = "SELECT id FROM transactions WHERE user_id = ? AND type = 'membership' ORDER BY id DESC LIMIT 1";
            $trans_check_stmt = mysqli_prepare($conn, $trans_check_sql);
            if ($trans_check_stmt) {
                mysqli_stmt_bind_param($trans_check_stmt, 'i', $user_id);
                mysqli_stmt_execute($trans_check_stmt);
                $trans_result = mysqli_stmt_get_result($trans_check_stmt);
                if ($trans_result && mysqli_num_rows($trans_result) > 0) {
                    $trans_row = mysqli_fetch_assoc($trans_result);
                    $transaction_id = $trans_row['id'];
                }
                mysqli_stmt_close($trans_check_stmt);
            }
        } catch (Exception $e) {
            error_log('Error getting transaction ID: ' . $e->getMessage());
        }
        
        // Log activities (wrap in try-catch to prevent errors from breaking the flow)
        try {
            if (function_exists('logActivity')) {
                // Log payment transaction
                logActivity($conn, 'payment_transaction', "Payment Transaction: {$full_name} paid ₱{$price} for {$membership_plan} via {$payment_method}", $staff_id, $transaction_id, 'transaction', ['amount' => $price, 'plan' => $membership_plan, 'method' => $payment_method]);
                // Log new member creation
                logActivity($conn, 'new_member', "New Member: {$full_name} ({$email}) registered with {$membership_plan} plan", $staff_id, $user_id, 'member', ['plan' => $membership_plan, 'email' => $email]);
            } else {
                error_log('logActivity function not available');
            }
        } catch (Exception $log_ex) {
            // Log but don't fail the whole process
            error_log('Activity logging error: ' . $log_ex->getMessage());
        } catch (Error $log_err) {
            // Catch fatal errors from activity logger
            error_log('Activity logging fatal error: ' . $log_err->getMessage());
        }
        
        // Record process history in pending_memberships table (wrap in try-catch to prevent errors)
        try {
            $current_datetime = date('Y-m-d H:i:s');
            $gcash_ref = ($payment_method === 'gcash' && !empty($gcash_reference)) ? $gcash_reference : null;
            $process_history_query = "INSERT INTO pending_memberships (user_id, plan_name, plan_price, payment_method, gcash_reference_number, gcash_screenshot_path, or_photo_path, status, requested_at, processed_at, processed_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, 'New member account added')";
            $process_history_stmt = mysqli_prepare($conn, $process_history_query);
            if ($process_history_stmt) {
                // Parameters: user_id (i), plan_name (s), plan_price (d), payment_method (s), gcash_ref (s), gcash_screenshot_path (s), or_photo_path (s), requested_at (s), processed_at (s), processed_by (i)
                // 10 placeholders total, so type string should be 10 characters: "isdssssssi"
                mysqli_stmt_bind_param($process_history_stmt, "isdssssssi", $user_id, $membership_plan, $price, $payment_method, $gcash_ref, $gcash_screenshot_path, $or_photo_path, $current_datetime, $current_datetime, $staff_id);
                mysqli_stmt_execute($process_history_stmt);
                mysqli_stmt_close($process_history_stmt);
                error_log("Process history recorded in pending_memberships");
            } else {
                error_log("Failed to prepare pending_memberships insert: " . mysqli_error($conn));
            }
        } catch (Exception $pending_ex) {
            // Log but don't fail the whole process
            error_log('Pending memberships record creation error: ' . $pending_ex->getMessage());
        }
        
        // Commit transaction first
        mysqli_commit($conn);
        error_log("Transaction committed, verifying account creation...");
        
        // Verify account was actually created in database
        $verify_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
        $account_created = false;
        if ($verify_stmt) {
            mysqli_stmt_bind_param($verify_stmt, 's', $email);
            mysqli_stmt_execute($verify_stmt);
            $verify_result = mysqli_stmt_get_result($verify_stmt);
            if ($verify_result && mysqli_num_rows($verify_result) > 0) {
                $account_created = true;
            }
            mysqli_stmt_close($verify_stmt);
        }
        
        // Clear session data
        unset($_SESSION['member_payment_data']);
        error_log("Session data cleared");
        
        if (!$account_created) {
            // Account was not created in database
            error_log("ERROR: Account verification failed for: $email");
            
            // Clear all output buffers completely
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Set headers
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            
            // Output JSON
            echo json_encode([
                'success' => false,
                'message' => 'Account verification failed. The account may not have been created in the database.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Account was successfully created in database
        error_log("Account created successfully for: $email");
        
        // Clear all output buffers completely
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        
        // Output JSON
        echo json_encode([
            'success' => true,
            'message' => 'Member account created successfully!',
            'redirect' => 'member__form.php'
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        
        // Log error with full details
        $error_message = $e->getMessage();
        error_log("ERROR: Member payment error - " . $error_message);
        error_log("ERROR: Stack trace - " . $e->getTraceAsString());
        
        // Return JSON error response with more details
        // Clear all output buffers completely
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        
        // Output JSON with error message (sanitized for security)
        $safe_message = 'An error occurred during account creation. ' . htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8');
        echo json_encode([
            'success' => false,
            'message' => $safe_message
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    // Log invalid request
    error_log("ERROR: Invalid request method or missing process_payment parameter");
    error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
    error_log("POST data: " . print_r($_POST, true));
    
    // Return JSON error response
    // Clear all output buffers completely
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Output JSON
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request. Please ensure you are submitting the payment form correctly.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
