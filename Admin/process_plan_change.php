<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

include '../config.php';
include '../includes/membership_plans_helper.php';

// Include activity logger
if (file_exists('../includes/activity_logger.php')) {
    include '../includes/activity_logger.php';
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';
$user_id = intval($_POST['user_id'] ?? 0);
$member_id = intval($_POST['member_id'] ?? 0);
$subscription_id = intval($_POST['subscription_id'] ?? 0);
$plan_id = intval($_POST['plan_id'] ?? 0);
$plan_name = $_POST['plan_name'] ?? '';
$plan_price = floatval($_POST['plan_price'] ?? 0);
$current_plan_price = floatval($_POST['current_plan_price'] ?? 0);
$remaining_days = intval($_POST['remaining_days'] ?? 0);
$amount_paid = floatval($_POST['amount_paid'] ?? 0);
$payment_method = $_POST['payment_method'] ?? 'cash';
$gcash_reference = $_POST['gcash_reference'] ?? '';

// Validation
if (!$user_id || !$member_id || !$plan_id || !$plan_name || !$plan_price) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Handle file uploads
$receipt_path = null;
$uploads_dir = '../uploads/payments/';
if (!file_exists($uploads_dir)) {
    mkdir($uploads_dir, 0777, true);
}

if ($payment_method === 'cash' && isset($_FILES['cash_receipt']) && $_FILES['cash_receipt']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['cash_receipt'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    
    if (in_array($file_extension, $allowed_extensions)) {
        $filename = 'plan_receipt_' . time() . '_' . uniqid() . '.' . $file_extension;
        $file_path = $uploads_dir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            $receipt_path = 'uploads/payments/' . $filename;
        }
    }
} elseif ($payment_method === 'gcash' && isset($_FILES['gcash_screenshot']) && $_FILES['gcash_screenshot']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['gcash_screenshot'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    
    if (in_array($file_extension, $allowed_extensions)) {
        $filename = 'gcash_screenshot_' . time() . '_' . uniqid() . '.' . $file_extension;
        $file_path = $uploads_dir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            $receipt_path = 'uploads/payments/' . $filename;
        }
    }
}

// Get plan details
$plan = getMembershipPlanById($conn, $plan_id);
if (!$plan) {
    echo json_encode(['success' => false, 'message' => 'Plan not found']);
    exit;
}

// Start transaction
mysqli_begin_transaction($conn);

try {
    if ($action === 'change_plan' && $subscription_id) {
        // Change existing plan - add new plan duration to remaining days
        // Get current subscription expiry date
        $current_sub_query = "SELECT expiry_date FROM subscriptions WHERE id = ?";
        $current_sub_stmt = $conn->prepare($current_sub_query);
        $current_sub_stmt->bind_param("i", $subscription_id);
        $current_sub_stmt->execute();
        $current_sub_result = $current_sub_stmt->get_result();
        $current_sub = $current_sub_result->fetch_assoc();
        $current_sub_stmt->close();
        
        if (!$current_sub) {
            throw new Exception('Current subscription not found');
        }
        
        // Calculate remaining days from current expiry date
        $current_expiry = new DateTime($current_sub['expiry_date']);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        $current_expiry->setTime(0, 0, 0);
        
        $diff = $today->diff($current_expiry);
        if ($current_expiry >= $today) {
            $remaining_days = $diff->days;
        } else {
            $remaining_days = 0;
        }
        
        // Get new plan duration in months
        $duration_months = $plan['duration_months'] ?? 1;
        
        // Calculate days to add from new plan (approximate: 30 days per month)
        // Use a more accurate calculation by adding months to current date
        $new_expiry = clone $today;
        $new_expiry->modify("+{$duration_months} months");
        
        // Add remaining days to the new expiry date
        if ($remaining_days > 0) {
            $new_expiry->modify("+{$remaining_days} days");
        }
        
        // Update subscription with new expiry date
        $new_expiry_date = $new_expiry->format('Y-m-d');
        $update_sub_query = "UPDATE subscriptions SET plan_name = ?, expiry_date = ?, status = 'active' WHERE id = ?";
        $update_sub_stmt = $conn->prepare($update_sub_query);
        $update_sub_stmt->bind_param("ssi", $plan_name, $new_expiry_date, $subscription_id);
        
        if (!$update_sub_stmt->execute()) {
            throw new Exception('Failed to update subscription: ' . $update_sub_stmt->error);
        }
        $update_sub_stmt->close();
        
    } else {
        // Avail new plan - create new subscription
        // Calculate duration from plan
        $duration_months = $plan['duration_months'] ?? 1;
        
        // If there's a current subscription with remaining days, preserve them
        if ($subscription_id && $remaining_days > 0) {
            $current_sub_query = "SELECT expiry_date FROM subscriptions WHERE id = ?";
            $current_sub_stmt = $conn->prepare($current_sub_query);
            $current_sub_stmt->bind_param("i", $subscription_id);
            $current_sub_stmt->execute();
            $current_sub_result = $current_sub_stmt->get_result();
            $current_sub = $current_sub_result->fetch_assoc();
            $current_sub_stmt->close();
            
            if ($current_sub) {
                // Use current expiry date (preserve remaining days)
                $start_date = date('Y-m-d');
                $expiry_date = $current_sub['expiry_date'];
            } else {
                // Calculate new expiry
                $start_date = date('Y-m-d');
                $expiry_date = date('Y-m-d', strtotime("+{$duration_months} months"));
            }
        } else {
            // New subscription
            $start_date = date('Y-m-d');
            $expiry_date = date('Y-m-d', strtotime("+{$duration_months} months"));
        }
        
        // Deactivate old subscription if exists
        if ($subscription_id) {
            $deactivate_query = "UPDATE subscriptions SET status = 'expired' WHERE id = ?";
            $deactivate_stmt = $conn->prepare($deactivate_query);
            $deactivate_stmt->bind_param("i", $subscription_id);
            $deactivate_stmt->execute();
            $deactivate_stmt->close();
        }
        
        // Create new subscription
        $insert_sub_query = "INSERT INTO subscriptions (member_id, plan_name, start_date, expiry_date, status) VALUES (?, ?, ?, ?, 'active')";
        $insert_sub_stmt = $conn->prepare($insert_sub_query);
        $insert_sub_stmt->bind_param("isss", $member_id, $plan_name, $start_date, $expiry_date);
        
        if (!$insert_sub_stmt->execute()) {
            throw new Exception('Failed to create subscription: ' . $insert_sub_stmt->error);
        }
        $insert_sub_stmt->close();
    }
    
    // Create payment record - always create for both avail_plan and change_plan
    // For new plans: use full plan price
    // For plan changes: use amount_paid (difference), or 0 if no additional payment needed
    $payment_amount = ($action === 'avail_plan') ? $plan_price : max(0, $amount_paid);
    
    // Always create payment record if there's a receipt/screenshot or if amount > 0
    if ($payment_amount > 0 || $receipt_path) {
        $payment_query = "INSERT INTO payments (user_id, member_id, amount, payment_method, payment_date, status, receipt_path, reference_number) VALUES (?, ?, ?, ?, NOW(), 'completed', ?, ?)";
        $payment_stmt = $conn->prepare($payment_query);
        $gcash_ref = ($payment_method === 'gcash' && $gcash_reference) ? $gcash_reference : null;
        $payment_stmt->bind_param("iidsss", $user_id, $member_id, $payment_amount, $payment_method, $receipt_path, $gcash_ref);
        
        if (!$payment_stmt->execute()) {
            throw new Exception('Failed to create payment record: ' . $payment_stmt->error);
        }
        $payment_stmt->close();
    }
    
    // Create transaction record - always create for revenue tracking
    // For new plans: use full plan price
    // For plan changes: use amount_paid (difference), or full plan price if no additional payment needed
    $transaction_amount = ($action === 'avail_plan') ? $plan_price : ($amount_paid > 0 ? $amount_paid : $plan_price);
    $transaction_desc = $action === 'change_plan' ? "Plan change: {$plan_name}" : "New plan: {$plan_name}";
    
    // Ensure or_photo_path column exists in transactions table
    $trans_column_check = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'or_photo_path'");
    if (mysqli_num_rows($trans_column_check) == 0) {
        mysqli_query($conn, "ALTER TABLE transactions ADD COLUMN or_photo_path VARCHAR(255) DEFAULT NULL AFTER gcash_reference_number");
    }
    
    // Check if gcash_reference_number column exists, if not add it
    $gcash_col_check = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'gcash_reference_number'");
    if (mysqli_num_rows($gcash_col_check) == 0) {
        mysqli_query($conn, "ALTER TABLE transactions ADD COLUMN gcash_reference_number VARCHAR(50) DEFAULT NULL AFTER payment_method");
        mysqli_query($conn, "ALTER TABLE transactions ADD COLUMN or_photo_path VARCHAR(255) DEFAULT NULL AFTER gcash_reference_number");
    }
    
    // Always create transaction record for revenue tracking
    // For cash payments, ensure receipt is saved if uploaded
    // For GCash payments, ensure screenshot is saved if uploaded
    $transaction_query = "INSERT INTO transactions (user_id, type, amount, date, description, status, payment_method, plan_name, customer_name, gcash_reference_number, or_photo_path) 
                         SELECT ?, 'membership', ?, NOW(), ?, 'completed', ?, ?, u.full_name, ?, ?
                         FROM users u WHERE u.id = ?";
    $transaction_stmt = $conn->prepare($transaction_query);
    $gcash_ref = ($payment_method === 'gcash' && $gcash_reference) ? $gcash_reference : null;
    // Ensure receipt_path is not null - use empty string if null to avoid binding issues
    $receipt_path_for_db = $receipt_path ? trim($receipt_path) : '';
    // Parameters: user_id(i), amount(d), description(s), payment_method(s), plan_name(s), gcash_ref(s), receipt_path(s), user_id for WHERE(i)
    $transaction_stmt->bind_param("idsssssi", $user_id, $transaction_amount, $transaction_desc, $payment_method, $plan_name, $gcash_ref, $receipt_path_for_db, $user_id);
    
    if (!$transaction_stmt->execute()) {
        throw new Exception('Failed to create transaction record: ' . $transaction_stmt->error);
    }
    $transaction_id = $conn->insert_id;
    $transaction_stmt->close();
    
    // Commit transaction
    mysqli_commit($conn);
    
    // Log activity for plan change payments
    if ($action === 'change_plan' && $transaction_amount > 0 && function_exists('logActivity')) {
        try {
            // Get staff user ID from session
            $staff_id = $_SESSION['user_id'] ?? null;
            
            // Get member/user information
            $user_query = "SELECT full_name, email FROM users WHERE id = ?";
            $user_stmt = $conn->prepare($user_query);
            if ($user_stmt) {
                $user_stmt->bind_param("i", $user_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_row = $user_result->fetch_assoc()) {
                    $customer_name = $user_row['full_name'];
                    $customer_email = $user_row['email'];
                    
                    // Log plan change payment transaction
                    logActivity($conn, 'payment_transaction', "Plan Change Payment: {$customer_name} paid ₱{$transaction_amount} for {$plan_name} via {$payment_method}", $staff_id, $transaction_id, 'transaction', ['amount' => $transaction_amount, 'plan' => $plan_name, 'method' => $payment_method, 'action' => 'change_plan', 'member_id' => $member_id]);
                }
                $user_stmt->close();
            }
        } catch (Exception $log_ex) {
            // Log but don't fail the whole process
            error_log('Activity logging error for plan change: ' . $log_ex->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => $action === 'change_plan' ? 'Plan changed successfully!' : 'Plan availed successfully!'
    ]);
    
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

