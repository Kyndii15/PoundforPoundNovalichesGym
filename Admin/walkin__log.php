<?php
// Start session before any output
if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
    session_name('gym_admin_session');
    session_start();
}

include '../config.php';
include '../includes/QRCodeGenerator.php';
include '../includes/activity_logger.php';

// Set Philippine timezone
date_default_timezone_set('Asia/Manila');

// Ensure customer_photo column exists in walk_in_log table
$check_photo_column = mysqli_query($conn, "SHOW COLUMNS FROM walk_in_log LIKE 'customer_photo'");
if (mysqli_num_rows($check_photo_column) == 0) {
    mysqli_query($conn, "ALTER TABLE walk_in_log ADD COLUMN customer_photo VARCHAR(255) DEFAULT NULL AFTER id");
}
// Ensure MySQL session uses Philippine time as well
if (isset($conn)) {
    @$conn->query("SET time_zone = '+08:00'");
}

// Auto-update Pending status to Missed Session at 10:00 PM Philippine time
$current_time = date('H:i:s');
$current_hour = (int)date('H');
$current_minute = (int)date('i');

// Check if it's 10:00 PM or later (22:00 in 24-hour format)
if ($current_hour >= 22) {
    // Update all Pending statuses to Missed Session
    $auto_update_query = "UPDATE walk_in_log SET status = 'Missed Session' WHERE status = 'Pending'";
    $auto_update_result = $conn->query($auto_update_query);
    
    // Log the auto-update (optional - for debugging)
    if ($auto_update_result && $conn->affected_rows > 0) {
        error_log("Auto-updated " . $conn->affected_rows . " Pending walk-ins to Missed Session at " . date('Y-m-d H:i:s'));
    }
}

$error = '';
$success = '';
$edit_id = null;

// Get coach ID from session
$coach_id = "";

// Get Personal Contribution Summary
$today_walkins = mysqli_query($conn, "
  SELECT COUNT(*) as count, SUM(amount) as total 
  FROM walk_in_log 
  WHERE DATE(date) = CURDATE() AND name NOT LIKE 'E2E Test%'
")->fetch_assoc();

$monthly_walkins = mysqli_query($conn, "
  SELECT COUNT(*) as count, SUM(amount) as total 
  FROM walk_in_log 
  WHERE MONTH(date) = MONTH(CURRENT_DATE()) 
    AND YEAR(date) = YEAR(CURRENT_DATE())
    AND name NOT LIKE 'E2E Test%'
")->fetch_assoc();

// Handle status updates
if (isset($_GET['update_status'])) {
    $id = intval($_GET['update_status']);
    $status = $_GET['status'] ?? '';
    
    if (in_array($status, ['Timed in', 'Pending', 'Missed Session'])) {
        // Check if the walk-in is already converted
        $check_stmt = $conn->prepare("SELECT status FROM walk_in_log WHERE id = ?");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        $current_status = $row ? $row['status'] : null;
        
        if (!$row) {
            $error = "Walk-in record not found.";
        } else {
            $update_stmt = $conn->prepare("UPDATE walk_in_log SET status = ? WHERE id = ?");
            $update_stmt->bind_param("si", $status, $id);
            if ($update_stmt->execute()) {
                $success = "Status updated to: " . $status;
            } else {
                $error = "Failed to update status.";
            }
        }
    } else {
        $error = "Invalid status.";
    }
}

// DELETE walk-in entry
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // First, get the amount and date to find the corresponding transaction
    $get_entry = $conn->prepare("SELECT amount, date FROM walk_in_log WHERE id = ?");
    $get_entry->bind_param("i", $id);
    $get_entry->execute();
    $entry = $get_entry->get_result()->fetch_assoc();
    
    if ($entry) {
        // Delete the corresponding transaction record
        $delete_transaction = $conn->prepare("DELETE FROM transactions WHERE type = 'walk-in' AND amount = ? AND date = ?");
        $delete_transaction->bind_param("ds", $entry['amount'], $entry['date']);
        $delete_transaction->execute();
        
        // Now delete the walk-in log entry
        $conn->query("DELETE FROM walk_in_log WHERE id = $id");
        $success = "Walk-in entry deleted.";
    } else {
        $error = "Entry not found.";
    }
}

// UPDATE walk-in entry
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_id']) && !empty($_POST['edit_id']) && is_numeric($_POST['edit_id'])) {
    $edit_id = intval($_POST['edit_id']);
    $name = trim($_POST['name']);
    $package = trim($_POST['package']);
    $amount = floatval($_POST['amount']); // Fixed amount from package
    $amount_given = floatval($_POST['amount_given']); // Amount given by customer
    $change_amount = floatval($_POST['change_amount']); // Calculated change
    $date = $_POST['date'] . ' ' . date('H:i:s');

    if (empty($name) || empty($amount) || empty($amount_given) || empty($date)) {
        $error = "Please fill in all required fields.";
    } else {
        // Get the old amount and date to update the corresponding transaction
        $get_old = $conn->prepare("SELECT amount, date FROM walk_in_log WHERE id = ?");
        $get_old->bind_param("i", $edit_id);
        $get_old->execute();
        $old_entry = $get_old->get_result()->fetch_assoc();
        
        if ($old_entry) {
            // Update the walk-in log entry
            $stmt = $conn->prepare("UPDATE walk_in_log SET name=?, package=?, amount=?, amount_given=?, change_amount=?, date=? WHERE id=?");
            $stmt->bind_param("ssdddsi", $name, $package, $amount, $amount_given, $change_amount, $date, $edit_id);
            $stmt->execute();
            
            // Update the corresponding transaction record
            $update_transaction = $conn->prepare("UPDATE transactions SET amount = ?, date = ? WHERE type = 'walk-in' AND amount = ? AND date = ?");
            $update_transaction->bind_param("dsds", $amount, $date, $old_entry['amount'], $old_entry['date']);
            $update_transaction->execute();
            
            $success = "Walk-in entry updated.";
        } else {
            $error = "Entry not found.";
        }
    }
}

// Handle walk-in with receipt upload
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'save_walkin_with_receipt') {
    error_log("=== WALK-IN SUBMISSION RECEIVED ===");
    $name = trim($_POST['name']);
    $package = trim($_POST['package']);
    $amount = floatval($_POST['amount']);
    $amount_given = floatval($_POST['amount_given']);
    $change_amount = floatval($_POST['change_amount']);
    $payment_method = trim($_POST['payment_method']);
    // Map payment method to database enum values
    if ($payment_method === 'gcash') {
        $payment_method = 'gcash';
    }
    $dateOnly = $_POST['date']; // Get date-only part (YYYY-MM-DD)
    $date = $dateOnly . ' ' . date('H:i:s');
    
    // Debug logging
    error_log("Walk-in submission: Name=$name, Package=$package, Amount=$amount, Payment=$payment_method");
    
    // Handle customer photo upload (base64 to file)
    $customer_photo_path = null;
    if (isset($_POST['customer_photo_data']) && !empty($_POST['customer_photo_data'])) {
        $photo_data = $_POST['customer_photo_data'];
        error_log("Photo data received, length: " . strlen($photo_data));
        
        // Check if it's a base64 image
        if (preg_match('/^data:image\/(\w+);base64,/', $photo_data, $matches)) {
            $upload_dir = '../uploads/walkin_photos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $image_type = $matches[1];
            $image_data = base64_decode(substr($photo_data, strpos($photo_data, ',') + 1));
            $filename = 'customer_' . time() . '_' . uniqid() . '.jpg';
            $file_path = $upload_dir . $filename;
            
            if (file_put_contents($file_path, $image_data)) {
                $customer_photo_path = 'uploads/walkin_photos/' . $filename;
                error_log("Customer photo saved successfully: " . $customer_photo_path);
            } else {
                error_log("Failed to save customer photo to: " . $file_path);
                $error = "Failed to save customer photo.";
            }
        } else {
            error_log("Photo data does not match base64 image format");
        }
    } else {
        error_log("No customer_photo_data in POST");
    }
    
    // Handle file upload
    $receipt_path = null;
    if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/walkin_receipts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $filename = 'walkin_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $file_path)) {
                $receipt_path = 'uploads/walkin_receipts/' . $filename;
            } else {
                $error = "Failed to upload receipt file.";
            }
        } else {
            $error = "Invalid file type. Please upload an image file (JPG, PNG, GIF).";
        }
    } else {
        // Receipt upload is optional; proceed without a receipt image
        $receipt_path = null;
    }
    
    if (empty($error)) {
        // Check for duplicate entry - only check same date (not time)
        $check_duplicate = $conn->prepare("SELECT id FROM walk_in_log WHERE name = ? AND package = ? AND DATE(date) = ?");
        $check_duplicate->bind_param("sss", $name, $package, $dateOnly);
        $check_duplicate->execute();
        $duplicate_result = $check_duplicate->get_result();
        
        if ($duplicate_result->num_rows > 0) {
            $error = "⚠️ DUPLICATE ENTRY DETECTED: A walk-in entry for '$name' with package '$package' already exists for this date. Please choose a different package or use a different name to avoid redundancy.";
        } else {
            // Generate unique walk-in reference number
            $qrGenerator = new QRCodeGenerator($conn);
            $walkin_ref = $qrGenerator->generateWalkinRef();
            
            // Insert walk-in log entry with payment method, receipt, customer photo, and walk-in reference
            $stmt = $conn->prepare("INSERT INTO walk_in_log (name, package, amount, amount_given, change_amount, payment_method, receipt_path, customer_photo, walkin_ref, date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->bind_param("ssddssssss", $name, $package, $amount, $amount_given, $change_amount, $payment_method, $receipt_path, $customer_photo_path, $walkin_ref, $date);
            
            error_log("Inserting walk-in with photo path: " . ($customer_photo_path ? $customer_photo_path : 'NULL'));
            
            if ($stmt->execute()) {
                $walkin_id = $conn->insert_id;
                error_log("Walk-in log inserted successfully with ID: $walkin_id");
                
                // Insert corresponding transaction record
                $transaction_stmt = $conn->prepare("INSERT INTO transactions (type, amount, customer_name, plan_name, payment_method, date) VALUES ('walk-in', ?, ?, ?, ?, ?)");
                $transaction_stmt->bind_param("dssss", $amount, $name, $package, $payment_method, $date);
                
                if ($transaction_stmt->execute()) {
                    error_log("Transaction record inserted successfully");
                    
                    // Log activity: Walk-in Recording
                    $admin_id = $_SESSION['user_id'] ?? null;
                    $transaction_id = $conn->insert_id;
                    logActivity($conn, 'walkin_recording', "Walk-in Recording: {$name} - {$package} (₱{$amount} via {$payment_method})", $admin_id, $walkin_id, 'walkin', ['customer_name' => $name, 'package' => $package, 'amount' => $amount, 'payment_method' => $payment_method]);
                    logActivity($conn, 'payment_transaction', "Payment Transaction: Walk-in payment from {$name} - ₱{$amount} for {$package} via {$payment_method}", $admin_id, $transaction_id, 'transaction', ['amount' => $amount, 'package' => $package, 'method' => $payment_method, 'type' => 'walkin']);
                    
                    $success = "Walk-in customer recorded successfully with receipt.";
                } else {
                    error_log("Transaction insert failed: " . $transaction_stmt->error);
                    $error = "Walk-in recorded but transaction failed.";
                }
            } else {
                error_log("Walk-in log insert failed: " . $stmt->error . " | Data => name:" . $name . ", package:" . $package . ", amount:" . $amount . ", amount_given:" . $amount_given . ", change:" . $change_amount . ", payment:" . $payment_method . ", date:" . $date);
                $error = "Failed to record walk-in customer.";
            }
        }
    }
}

// ADD new walk-in entry (old form handler - only for non-POS submissions)
if ($_SERVER["REQUEST_METHOD"] == "POST" && (!isset($_POST['edit_id']) || empty($_POST['edit_id']) || !is_numeric($_POST['edit_id'])) && !isset($_POST['action'])) {
    $name = trim($_POST['name']);
    $package = trim($_POST['package']);
    $amount = floatval($_POST['amount']); // Fixed amount from package
    $amount_given = floatval($_POST['amount_given']); // Amount given by customer
    $change_amount = floatval($_POST['change_amount']); // Calculated change
    $payment_method = 'cash'; // Default to cash for old form
    $dateOnly = $_POST['date']; // Get date-only part (YYYY-MM-DD)
    $date = $dateOnly . ' ' . date('H:i:s');

    if (empty($name) || empty($amount) || empty($amount_given) || empty($dateOnly)) {
        $error = "Please fill in all required fields.";
    } else {
        // Check for duplicate entry - only check same date (not time)
        $check_duplicate = $conn->prepare("SELECT id FROM walk_in_log WHERE name = ? AND package = ? AND DATE(date) = ?");
        $check_duplicate->bind_param("sss", $name, $package, $dateOnly);
        $check_duplicate->execute();
        $duplicate_result = $check_duplicate->get_result();
        
        if ($duplicate_result->num_rows > 0) {
            $error = "⚠️ DUPLICATE ENTRY DETECTED: A walk-in entry for '$name' with package '$package' already exists for this date. Please choose a different package or use a different name to avoid redundancy.";
        } else {
            // Generate unique walk-in reference number
            $qrGenerator = new QRCodeGenerator($conn);
            $walkin_ref = $qrGenerator->generateWalkinRef();
            
            // Handle customer photo for old form handler (if provided)
            $customer_photo_path = null;
            if (isset($_POST['customer_photo_data']) && !empty($_POST['customer_photo_data'])) {
                $photo_data = $_POST['customer_photo_data'];
                if (preg_match('/^data:image\/(\w+);base64,/', $photo_data, $matches)) {
                    $upload_dir = '../uploads/walkin_photos/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $image_data = base64_decode(substr($photo_data, strpos($photo_data, ',') + 1));
                    $filename = 'customer_' . time() . '_' . uniqid() . '.jpg';
                    $file_path = $upload_dir . $filename;
                    if (file_put_contents($file_path, $image_data)) {
                        $customer_photo_path = 'uploads/walkin_photos/' . $filename;
                    }
                }
            }
            
            $stmt = $conn->prepare("INSERT INTO walk_in_log (name, package, amount, amount_given, change_amount, payment_method, customer_photo, walkin_ref, date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->bind_param("ssddsssss", $name, $package, $amount, $amount_given, $change_amount, $payment_method, $customer_photo_path, $walkin_ref, $date);
            if ($stmt->execute()) {
                $walkin_id = $conn->insert_id;
                $insert_transaction = $conn->prepare("INSERT INTO transactions (type, amount, customer_name, plan_name, payment_method, date) VALUES ('walk-in', ?, ?, ?, ?, ?)");
                $insert_transaction->bind_param("dssss", $amount, $name, $package, $payment_method, $date);
                if ($insert_transaction->execute()) {
                    // Log activity: Walk-in Recording
                    $admin_id = $_SESSION['user_id'] ?? null;
                    $transaction_id = $conn->insert_id;
                    logActivity($conn, 'walkin_recording', "Walk-in Recording: {$name} - {$package} (₱{$amount} via {$payment_method})", $admin_id, $walkin_id, 'walkin', ['customer_name' => $name, 'package' => $package, 'amount' => $amount, 'payment_method' => $payment_method]);
                    logActivity($conn, 'payment_transaction', "Payment Transaction: Walk-in payment from {$name} - ₱{$amount} for {$package} via {$payment_method}", $admin_id, $transaction_id, 'transaction', ['amount' => $amount, 'package' => $package, 'method' => $payment_method, 'type' => 'walkin']);
                }
                $success = "Walk-in entry recorded.";
            } else {
                $error = "Failed to record entry.";
            }
        }
    }
}

// Pagination and filtering
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Records per page
$offset = ($page - 1) * $limit;

// Date filtering
$filter_date = $_GET['date'] ?? date('Y-m-d'); // Default to today
$date_condition = "DATE(date) = '$filter_date' AND name NOT LIKE 'E2E Test%'";

// Function to check if customer has an active member account
function hasActiveMember($conn, $customer_name) {
    $check_active = $conn->prepare("
        SELECT s.id 
        FROM subscriptions s 
        JOIN members m ON s.member_id = m.id 
        JOIN users u ON m.user_id = u.id 
        WHERE u.full_name = ? 
        AND s.status = 'active' 
        AND s.expiry_date > CURDATE()
        ORDER BY s.expiry_date DESC 
        LIMIT 1
    ");
    $check_active->bind_param("s", $customer_name);
    $check_active->execute();
    $result = $check_active->get_result()->fetch_assoc();
    $check_active->close();
    
    return $result !== null; // Returns true if active member exists, false otherwise
}

// Function to check if walk-in can be converted
function canConvertWalkin($conn, $walkin_id, $customer_name) {
    // Check if the member's subscription is expired or expiring within a week
    $check_subscription = $conn->prepare("
        SELECT s.expiry_date, s.status 
        FROM subscriptions s 
        JOIN members m ON s.member_id = m.id 
        JOIN users u ON m.user_id = u.id 
        WHERE u.full_name = ? 
        ORDER BY s.expiry_date DESC 
        LIMIT 1
    ");
    $check_subscription->bind_param("s", $customer_name);
    $check_subscription->execute();
    $subscription = $check_subscription->get_result()->fetch_assoc();
    
    if ($subscription) {
        $expiry_date = new DateTime($subscription['expiry_date']);
        $today = new DateTime();
        $one_week_from_now = (new DateTime())->add(new DateInterval('P7D'));
        
        // Can convert if subscription is expired or expiring within a week
        return $expiry_date <= $one_week_from_now;
    }
    return true; // No subscription found, can convert
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM walk_in_log WHERE $date_condition";
$count_result = $conn->query($count_query);
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Get paginated results - Retrieve GCash reference number from multiple sources
$logs_query = "SELECT w.*, 
               COALESCE(
                   (SELECT gr.reference_number 
                    FROM gcash_references gr 
                    WHERE gr.transaction_type = 'walkin_payment' 
                      AND gr.payment_date BETWEEN DATE_SUB(w.date, INTERVAL 10 MINUTE) AND DATE_ADD(w.date, INTERVAL 10 MINUTE)
                      AND ABS(gr.amount - w.amount) < 0.01
                    LIMIT 1),
                   (SELECT p.reference_number 
                    FROM payments p 
                    WHERE p.payment_method = 'gcash' 
                      AND p.payment_date BETWEEN DATE_SUB(w.date, INTERVAL 10 MINUTE) AND DATE_ADD(w.date, INTERVAL 10 MINUTE)
                      AND ABS(p.amount - w.amount) < 0.01
                    LIMIT 1),
                   NULL
               ) as reference_number
               FROM walk_in_log w 
               WHERE $date_condition 
               ORDER BY w.date DESC 
               LIMIT $limit OFFSET $offset";
$logs = $conn->query($logs_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Walk-in Log - Admin</title>
  <link rel="stylesheet" href="../css/coach.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <style>
    /* OTP Form Styling */
.otp-form {
    width: 100%;
    max-width: 500px;
    margin: 2.8rem auto;
    padding: 2rem;
    background: white;
    border-radius: 12px;
    box-sizing: border-box;
}

.otp-timer {
    text-align: center;
    margin: 0 0 1.5rem 0;
    padding: 12px;
    background: rgba(12, 12, 98, 0.1);
    border-radius: 8px;
    font-size: 15px;
    color: #0c0c62;
    font-weight: 500;
}

.otp-input {
    width: 100%;
    text-align: center;
    letter-spacing: 12px;
    font-size: 28px;
    height: 60px;
    padding: 0 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    margin: 10px 0;
    transition: all 0.3s ease;
}

.otp-input:focus {
    border-color: #0c0c62;
    outline: none;
    box-shadow: 0 0 0 3px rgba(12, 12, 98, 0.15);
}

.input-box {
    margin-bottom: 1.5rem;
}

.input-box label {
    display: block;
    margin-bottom: 8px;
    color: #333;
    font-weight: 500;
}

.btn-submit, .btn-resend {
    width: 100%;
    padding: 14px;
    margin: 8px 0;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-submit {
    background: #0c0c62;
    color: white;
}

.btn-submit:hover {
    background: #0a0a52;
    transform: translateY(-2px);
}

.btn-resend {
    background: #f5f5f5;
    color: #0c0c62;
    border: 1px solid #e0e0e0;
}

.btn-resend:hover {
    background: #eeeeee;
}

.switch-form {
    text-align: center;
    margin-top: 1.5rem;
    font-size: 15px;
    color: #666;
}

.switch-form a {
    color: #0c0c62;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.switch-form a:hover {
    text-decoration: underline;
}

.form-header {
    margin-bottom: 2rem;
    text-align: center;
}

.title-register {
    font-size: 24px !important;
    font-weight: 600 !important;
    color: white !important;
    margin: 0 !important;
    line-height: 5.3 !important;
    position: relative;
    z-index: 2;
}

.subtitle {
    color: #666;
    font-size: 15px;
    margin: 0 0 1.5rem 0;
    line-height: 1.5;
}

.verification-message {
    color: #555;
    font-size: 15px;
    line-height: 1.5;
    margin: 1rem 0 0 0;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
    text-align: center;
}

/* Responsive adjustments */
@media (max-width: 600px) {
    .otp-form {
        padding: 1.5rem;
        margin: 1rem;
    }
    
    .otp-input {
        font-size: 24px;
        height: 55px;
    }
    
    .title-register {
        font-size: 24px;
    }
}

    :root {
      --accent-clr: #5e63ff;
      --line-clr: #444;
    }

    .valid-input {
      border-color: #28a745 !important;
      box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
    }

    body {
      margin: 0;
      font-family: Poppins, sans-serif;
      background: #0c1118;
      color: white;
    }
    main {
      padding: 2rem;
    }
    h1 {
      margin-bottom: 0.5rem;
    }
    .dashboard-grid {
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
      margin-bottom: 2rem;
      animation: fadeInUp 0.6s ease-out;
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .card {
      background: #1c1d25;
      padding: 1.5rem;
      border-radius: 12px;
      flex: 1 1 200px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.15);
      text-align: center;
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      cursor: pointer;
      position: relative;
      overflow: hidden;
      border: 1px solid transparent;
    }

    .card:hover {
      transform: translateY(-4px) scale(1.01);
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
      background: linear-gradient(135deg, #252630 0%, #2a2b36 100%);
      border-color: rgba(94, 99, 255, 0.3);
    }

    .card:hover h2 {
      color: #7c82ff;
      transform: scale(1.02);
    }

    .card:hover p {
      color: #e0e0e0;
      transform: translateY(-2px);
    }

    .card:active {
      transform: translateY(-2px) scale(1.005);
      transition: all 0.1s ease;
    }

    .card h2 {
      margin: 0;
      font-size: 1.5rem;
      color: var(--accent-clr);
      transition: all 0.3s ease;
      position: relative;
    }

    .card p {
      margin-top: 8px;
      font-size: 1rem;
      transition: color 0.3s ease;
    }

    .card small {
      display: block;
      margin-top: 6px;
      font-size: 0.75rem;
      color: #5e63ff;
      font-weight: 500;
      letter-spacing: 0.5px;
      transition: all 0.3s ease;
    }

    .card:hover small {
      color: #7c82ff;
      transform: translateY(-1px);
    }
    .form-card {
      background: white;
      padding: 2rem;
      border-radius: 12px;
      margin-bottom: 2rem;
      box-shadow: 0 4px 20px rgba(0,0,0,0.1);
      color: #333;
    }
    .button-group {
      display: flex;
      gap: 15px;
      flex-wrap: wrap;
    }
    .add-walkin-btn, .convert-member-btn {
      padding: 14px 30px;
      border: none;
      border-radius: 8px;
      font-weight: 600;
      font-size: 16px;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .add-walkin-btn {
      background: var(--accent-clr);
      color: white;
    }
    .add-walkin-btn:hover {
      background: #4c52d4;
      transform: translateY(-2px);
    }
    .convert-member-btn {
      background: #c83126;
      color: white;
    }
    .convert-member-btn:hover {
      background: #a0281f;
      transform: translateY(-2px);
    }
    
    /* Modal Styles */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.7);
      overflow-y: auto;
    }
     
    .modal-content {
      background: #1c1d25;
      margin: 2% auto;
      padding: 0;
      border-radius: 12px;
      width: 95%;
      max-width: 600px;
      min-height: auto;
      box-shadow: 0 8px 32px rgba(0,0,0,0.4);
      border: 1px solid #2a2b36;
      position: relative;
      overflow: hidden;
    }
    
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px 24px;
      border-bottom: 1px solid #2a2b36;
      background: linear-gradient(135deg, #1c1d25 0%, #1f2028 100%);
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .modal-header h2 {
      margin: 0;
      color: var(--accent-clr);
      font-size: 1.2rem;
    }
    .close {
      color: #aaa;
      font-size: 24px;
      font-weight: bold;
      cursor: pointer;
      padding: 5px;
      line-height: 1;
    }
    .close:hover {
      color: white;
    }
    
    /* Form Styles */
    #walkinForm {
      padding: 24px;
    }
    .form-group {
      margin-bottom: 20px;
    }
    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: #e0e0e0;
      font-size: 0.9rem;
      letter-spacing: 0.3px;
    }
    
    .form-group input, .form-group select, .form-group textarea {
      width: 100%;
      padding: 12px 14px;
      border: 1.5px solid #3c3d4a;
      border-radius: 8px;
      font-size: 14px;
      box-sizing: border-box;
      background: #2a2b36;
      color: #ffffff;
      transition: all 0.3s ease;
      -webkit-appearance: none;
      -moz-appearance: none;
      appearance: none;
    }
    
    .form-group input:hover, .form-group select:hover, .form-group textarea:hover {
      border-color: #4c4d5a;
      background: #2f3039;
    }
    
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
      border-color: var(--accent-clr);
      outline: none;
      background: #2f3039;
      box-shadow: 0 0 0 3px rgba(94, 99, 255, 0.1);
    }
    .form-group input[readonly] {
      background: #1f2028;
      color: #999;
      cursor: not-allowed;
      border-color: #2a2b36;
    }
    .package-description {
      margin-top: 10px;
      padding: 12px;
      background: #1f2028;
      border-radius: 8px;
      font-size: 0.85rem;
      color: #ccc;
      border-left: 3px solid var(--accent-clr);
      line-height: 1.5;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .checkbox-group {
      display: flex;
      align-items: center;
    }
    .checkbox-group label {
      display: flex;
      align-items: center;
      margin-bottom: 0;
      cursor: pointer;
    }
    .checkbox-group input[type="checkbox"] {
      width: auto;
      margin-right: 8px;
    }
    .modal-actions {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid #2a2b36;
      flex-wrap: wrap;
    }
    .cancel-btn, .save-btn {
      padding: 12px 24px;
      border: none;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      font-size: 14px;
      min-width: 100px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .cancel-btn {
      background: #3c3d4a;
      color: #e0e0e0;
    }
    .cancel-btn:hover {
      background: #4c4d5a;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }
    .save-btn {
      background: #0c0c62;
      color: white;
    }
    .save-btn:hover {
      background: #4c52d4;
      transform: translateY(-1px);
      box-shadow: 0 4px 16px rgba(94, 99, 255, 0.4);
    }
    
    /* Mobile Responsive Styles */
    @media (max-width: 768px) {
      .button-group {
        flex-direction: column;
        gap: 10px;
      }
      .add-walkin-btn, .convert-member-btn {
        width: 100%;
        padding: 12px;
        font-size: 16px;
      }
      .modal-content {
        width: 98%;
        margin: 1% auto;
        border-radius: 8px;
      }
      .modal-header {
        padding: 12px 15px;
      }
      .modal-header h2 {
        font-size: 1.1rem;
      }
      .close {
        font-size: 20px;
      }
      #walkinForm {
        padding: 12px 15px 15px;
      }
      .form-group {
        margin-bottom: 12px;
      }
      .form-group input, .form-group select {
        padding: 12px;
        font-size: 16px; /* Prevents zoom on iOS */
      }
      .modal-actions {
        flex-direction: column;
        gap: 8px;
      }
      .cancel-btn, .save-btn {
        width: 100%;
        padding: 12px;
        font-size: 16px;
      }
    }
    
    @media (max-width: 480px) {
      .modal-content {
        width: 100%;
        margin: 0;
        border-radius: 0;
        min-height: 100vh;
      }
      .modal-header {
        padding: 15px;
      }
      #walkinForm {
        padding: 15px;
      }
    }
    .alert {
      margin-top: 10px;
      margin-bottom: 20px;
      padding: 15px;
      border-radius: 8px;
      font-weight: 500;
      border-left: 4px solid;
    }
    .success { 
      background: rgba(94, 99, 255, 0.1); 
      color: var(--accent-clr); 
      border-left-color: var(--accent-clr);
      box-shadow: 0 2px 8px rgba(94, 99, 255, 0.1); 
    }
    .error { 
      background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%); 
      color: #d32f2f; 
      border-left-color: #d32f2f;
      box-shadow: 0 4px 12px rgba(211, 47, 47, 0.2);
      font-size: 14px;
      line-height: 1.4;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      background: #1c1d25;
    }

    th, td {
      padding: 8px;
      border: 1px solid var(--line-clr);
      text-align: center;
      font-size: 0.9rem;
    }

    th {
      background: #2a2b36;
    }

    /* Column width adjustments - Desktop only */
    @media (min-width: 769px) {
      th:nth-child(1), td:nth-child(1) { width: 8%; } /* Photo */
      th:nth-child(2), td:nth-child(2) { width: 10%; } /* Name */
      th:nth-child(3), td:nth-child(3) { width: 10%; } /* Package Availed */
      th:nth-child(4), td:nth-child(4) { width: 7%; } /* Price */
      th:nth-child(5), td:nth-child(5) { width: 8%; } /* Payment Method */
      th:nth-child(6), td:nth-child(6) { width: 7%; } /* Receipt */
      th:nth-child(7), td:nth-child(7) { width: 7%; } /* Walk in Ref. */
      th:nth-child(8), td:nth-child(8) { width: 8%; } /* Date */
      th:nth-child(9), td:nth-child(9) { width: 7%; } /* Status */
      th:nth-child(10), td:nth-child(10) { width: 16%; } /* Actions */
      th:nth-child(11), td:nth-child(11) { width: 13%; } /* Convert */
    }

    .table-container {
      overflow-x: auto;
      margin-bottom: 2rem;
    }



    
    .actions-column {
      white-space: nowrap;
    }

    .convert-column {
      white-space: nowrap;
      text-align: center;
    }

    .action-btn {
      display: inline-block;
      width: 70px;
      text-align: center;
      padding: 4px 6px;
      border-radius: 4px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.7rem;
      margin: 1px 2px;
      transition: all 0.3s ease;
      border: none;
      cursor: pointer;
      vertical-align: top;
      box-sizing: border-box;
    }

    .edit-btn {
      background: #4a52e8;
      color: white;
    }

    .edit-btn:hover {
      background: #3a42d8;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(74, 82, 232, 0.3);
    }

    .delete-btn {
      background: #dc3545;
      color: white;
    }

    .delete-btn:hover {
      background: #c82333;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
    }

    /* New Action Button Styles */
    .attended-btn {
      background: #28a745;
      color: white;
    }

    .attended-btn:hover {
      background: #218838;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
    }

    .not-attended-btn {
      background: #ffc107;
      color: #212529;
    }

    .not-attended-btn:hover {
      background: #e0a800;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);
    }

    .convert-btn {
      background: #1873CC;
      color: white;
    }

    .convert-btn:hover {
      background: #1565c0;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(24, 115, 204, 0.3);
    }
    
    /* View field styles */
    .view-field {
      background: var(--hover-clr);
      color: #e0e0e0;
      padding: 12px 15px;
      border-radius: 8px;
      border: 1px solid #333;
      margin-top: 8px;
      word-wrap: break-word;
      font-size: 14px;
      line-height: 1.5;
      min-height: 20px;
      transition: all 0.3s ease;
    }
    
    .view-field:hover {
      border-color: #5e63ff;
      background: #1f2029;
    }
    
    /* View modal specific styles */
    #viewConvertedModal .modal-content {
      max-width: 600px;
      border-radius: 12px;
    }
    
    #viewConvertedModal .modal-header {
      background: linear-gradient(135deg, #5e63ff 0%, #4a52e8 100%);
      color: white;
      padding: 20px 25px;
      border-radius: 12px 12px 0 0;
      border-bottom: none;
    }
    
    #viewConvertedModal .modal-header h2 {
      margin: 0;
      font-size: 20px;
      font-weight: 600;
      color: #ffffff;
    }
    
    #viewConvertedModal .close {
      color: white;
      font-size: 24px;
      font-weight: bold;
      opacity: 0.8;
      transition: opacity 0.3s ease;
    }
    
    #viewConvertedModal .close:hover {
      opacity: 1;
    }
    
    #viewConvertedModal .modal-body {
      padding: 25px;
      background: var(--hover-clr);
    }
    
    #viewConvertedModal .form-group {
      margin-bottom: 20px;
    }
    
    #viewConvertedModal .form-group label {
      color: #b0b0b0;
      font-weight: 600;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 8px;
      display: block;
    }
    
    #viewConvertedModal .modal-footer {
      background: var(--hover-clr);
      padding: 20px 25px;
      border-radius: 0 0 12px 12px;
      border-top: 1px solid #333;
    }
    
    #viewConvertedModal .btn-secondary {
      background: #6c757d;
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 6px;
      font-weight: 500;
      transition: all 0.3s ease;
    }
    
    #viewConvertedModal .btn-secondary:hover {
      background: #5a6268;
      transform: translateY(-1px);
    }
    
    /* Special styling for password field */
    #viewPassword {
      background: var(--hover-clr);
      border-color: #333;
      color: #e0e0e0;
      font-family: 'Courier New', monospace;
      font-weight: normal;
      letter-spacing: 1px;
    }
    
    #viewPassword:hover {
      border-color: #5e63ff;
      background: #1f2029;
    }
    
    /* Responsive design for view modal */
    @media (max-width: 768px) {
      #viewConvertedModal .modal-content {
        margin: 5% auto;
        width: 95%;
        max-width: none;
      }
      
      #viewConvertedModal .modal-body {
        padding: 20px;
      }
      
      #viewConvertedModal .modal-header {
        padding: 15px 20px;
      }
      
      #viewConvertedModal .modal-footer {
        padding: 15px 20px;
      }
    }

    /* Custom Checkbox Styling */
    .checkbox-label {
      display: flex;
      align-items: center;
      cursor: pointer;
      font-size: 0.9rem;
      color: var(--text-clr);
    }

    .checkbox-label input[type="checkbox"] {
      display: none;
    }

    .checkmark {
      width: 18px;
      height: 18px;
      background: var(--hover-clr);
      border: 2px solid var(--line-clr);
      border-radius: 4px;
      margin-right: 10px;
      position: relative;
      transition: all 0.3s ease;
    }

    .checkbox-label input[type="checkbox"]:checked + .checkmark {
      background: var(--accent-clr);
      border-color: var(--accent-clr);
    }

    .checkbox-label input[type="checkbox"]:checked + .checkmark::after {
      content: '✓';
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      color: white;
      font-size: 12px;
      font-weight: bold;
    }

    .checkbox-label:hover .checkmark {
      border-color: var(--accent-clr);
    }

    .nav-arrow:hover {
      background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%) !important;
      color: white !important;
      transform: translateY(-3px);
      box-shadow: 0 6px 25px rgba(107, 114, 128, 0.25);
      border-color: #6b7280;
    }

    .go-to-today-btn:hover {
      background: #4a52e8 !important;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(94, 99, 255, 0.3);
    }

    #currentDateDisplay:hover {
      background: linear-gradient(135deg, #3a3b46 0%, #2c2d35 100%) !important;
      transform: translateY(-2px);
      box-shadow: 0 6px 25px rgba(0, 0, 0, 0.4);
    }
    a {
      color: var(--accent-clr);
      text-decoration: none;
      font-size: 0.95rem;
    }
    @media (max-width: 768px) {
      form {
        flex-direction: column;
        align-items: stretch;
      }

      .dashboard-grid {
        flex-direction: column;
      }

      main {
        padding: 0.75rem;
        overflow-x: hidden;
      }
      
      h1 {
        font-size: 1.1rem;
        margin-bottom: 0.75rem;
      }
      
      .table-container {
        overflow-x: visible;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }
      
      table {
        min-width: 0;
        width: 100%;
        font-size: 0.7rem;
        table-layout: fixed;
        margin: 0;
        border-spacing: 0;
      }
      
      th, td {
        padding: 0.4rem 0.3rem;
        font-size: 0.7rem;
        text-overflow: ellipsis;
      }
      
      /* Mobile column widths */
      th:nth-child(1), td:nth-child(1) { width: 7%; } /* Photo */
      th:nth-child(2), td:nth-child(2) { width: 9%; } /* Name */
      th:nth-child(3), td:nth-child(3) { width: 9%; font-size: 0.65rem; } /* Package Availed */
      th:nth-child(4), td:nth-child(4) { width: 6%; font-size: 0.65rem; } /* Price */
      th:nth-child(5), td:nth-child(5) { width: 7%; font-size: 0.65rem; } /* Payment Method */
      th:nth-child(6), td:nth-child(6) { width: 6%; } /* Receipt */
      th:nth-child(7), td:nth-child(7) { width: 6%; } /* Walk in Ref. */
      th:nth-child(8), td:nth-child(8) { width: 7%; font-size: 0.65rem; } /* Date */
      th:nth-child(9), td:nth-child(9) { width: 6%; font-size: 0.65rem; } /* Status */
      th:nth-child(10), td:nth-child(10) { width: 20%; } /* Actions */
      th:nth-child(11), td:nth-child(11) { width: 18%; } /* Convert */
      
      .actions-column a, .convert-column a {
        font-size: 0.6rem;
        padding: 3px 5px;
        min-width: 50px;
        width: auto;
      }
      
      .form-row {
        flex-direction: column;
      }
      
      .button-group {
        flex-direction: column;
      }
      
      .add-walkin-btn, .convert-member-btn {
        width: 100%;
        justify-content: center;
      }
    }
    
    @media (max-width: 480px) {
      /* Extra small screens */
      main h1 {
        font-size: 1rem;
      }
      
      table {
        font-size: 0.65rem;
      }
      
      th, td {
        padding: 0.35rem 0.25rem;
        font-size: 0.65rem;
      }
      
      th:nth-child(1), td:nth-child(1) { width: 7%; } /* Photo */
      th:nth-child(2), td:nth-child(2) { width: 9%; font-size: 0.6rem; } /* Name */
      th:nth-child(3), td:nth-child(3) { width: 9%; font-size: 0.55rem; } /* Package Availed */
      th:nth-child(4), td:nth-child(4) { width: 6%; font-size: 0.55rem; } /* Price */
      th:nth-child(5), td:nth-child(5) { width: 7%; font-size: 0.55rem; } /* Payment Method */
      th:nth-child(6), td:nth-child(6) { width: 6%; } /* Receipt */
      th:nth-child(7), td:nth-child(7) { width: 6%; } /* Walk in Ref. */
      th:nth-child(8), td:nth-child(8) { width: 7%; font-size: 0.55rem; } /* Date */
      th:nth-child(9), td:nth-child(9) { width: 6%; font-size: 0.55rem; } /* Status */
      th:nth-child(10), td:nth-child(10) { width: 20%; } /* Actions */
      th:nth-child(11), td:nth-child(11) { width: 18%; } /* Convert */
      
      .actions-column a, .convert-column a {
        font-size: 0.55rem;
        padding: 2px 4px;
        min-width: 45px;
      }
      
      .action-btn {
        width: 50px;
        padding: 2px 3px;
        font-size: 0.55rem;
      }
      
      /* Stack action buttons vertically on very small screens */
      .actions-column {
        white-space: normal;
      }
      
      .action-btn {
        display: block;
        margin: 1px 0;
        width: 100%;
      }
    }

    /* POS Modal Styles */
    .pos-section {
      margin-bottom: 15px;
      padding: 15px;
      background: var(--hover-clr);
      border-radius: 8px;
      border: 1px solid #333;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }

    .pos-section h3 {
      margin: 0 0 15px 0;
      color: var(--accent-clr);
      font-size: 1.1rem;
      font-weight: 600;
      border-bottom: 1px solid var(--accent-clr);
      padding-bottom: 8px;
    }

    .pos-info {
      display: grid;
      gap: 8px;
    }

    .info-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px solid #333;
    }

    .info-item:last-child {
      border-bottom: none;
    }

    .info-item .label {
      font-weight: 600;
      color: #b0b0b0;
      font-size: 0.9rem;
    }

    .info-item .value {
      color: #e0e0e0;
      font-weight: 600;
      font-size: 0.95rem;
    }

    .file-upload-container {
      margin: 15px 0;
    }

    .file-upload-label {
      display: inline-flex;
      align-items: center;
      padding: 12px 20px;
      background: var(--accent-clr);
      color: white;
      border-radius: 6px;
      cursor: pointer;
      transition: background 0.3s ease;
      font-weight: 500;
      border: none;
      font-size: 0.9rem;
    }

    .file-upload-label:hover {
      background: #3a42d8;
    }

    .file-status {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 10px;
      padding: 10px;
      background: var(--line-clr);
      border-radius: 6px;
      border: 1px solid #444;
    }

    .file-name {
      color: #4caf50;
      font-weight: 500;
      font-size: 0.9rem;
    }

    .remove-file {
      background: #dc3545;
      color: white;
      border: none;
      border-radius: 50%;
      width: 24px;
      height: 24px;
      cursor: pointer;
      font-size: 14px;
      line-height: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.3s ease;
    }

    .remove-file:hover {
      background: #c82333;
    }

    /* Amount Display Styles */
    .amount-display {
      text-align: center;
      padding: 20px;
      background: var(--accent-clr);
      border-radius: 8px;
      margin: 15px 0;
    }

    .amount-value {
      font-size: 2rem;
      font-weight: 600;
      color: white;
    }

    /* POS Form Styles */
    .pos-section .form-group {
      margin-bottom: 15px;
    }

    .pos-section .form-group label {
      display: block;
      margin-bottom: 6px;
      font-weight: 500;
      color: #b0b0b0;
      font-size: 0.9rem;
    }

    .pos-section .form-group input[type="number"],
    .pos-section .form-group input[type="text"] {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #333;
      border-radius: 6px;
      font-size: 0.9rem;
      background: var(--line-clr);
      color: #e0e0e0;
      transition: border-color 0.3s ease;
    }

    .pos-section .form-group input[type="number"]:focus,
    .pos-section .form-group input[type="text"]:focus {
      outline: none;
      border-color: var(--accent-clr);
    }

    .pos-section .checkbox-group {
      margin: 10px 0;
    }

    .pos-section .checkbox-group label {
      display: flex;
      align-items: center;
      font-weight: 500;
      cursor: pointer;
      color: #e0e0e0;
      font-size: 0.9rem;
    }

    .pos-section .checkbox-group input[type="checkbox"] {
      margin-right: 8px;
      transform: scale(1.1);
      accent-color: var(--accent-clr);
    }

    /* POS Modal Content Styling */
    #posContent {
      padding: 20px;
      background: var(--hover-clr);
    }

    /* Enhanced Modal Header for POS */
    #walkinPosModal .modal-header {
      background: var(--accent-clr);
      color: white;
      border-radius: 8px 8px 0 0;
      border-bottom: none;
      padding: 15px 20px;
    }

    #walkinPosModal .modal-header h2 {
      color: white;
      font-size: 1.2rem;
      font-weight: 600;
    }

    #walkinPosModal .close {
      color: white;
      font-size: 24px;
      opacity: 0.8;
      transition: opacity 0.3s ease;
    }

    #walkinPosModal .close:hover {
      opacity: 1;
    }

    /* Enhanced Modal Content */
    #walkinPosModal .modal-content {
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    }

    /* Mobile Responsive POS Modal */
    @media (max-width: 768px) {
      #walkinPosModal .modal-content {
        width: 95%;
        margin: 2% auto;
        max-width: none;
      }
      
      #posContent {
        padding: 15px;
      }
      
      .pos-section {
        padding: 12px;
        margin-bottom: 12px;
      }
      
      .pos-section h3 {
        font-size: 1rem;
        margin-bottom: 12px;
      }
      
      .amount-display {
        padding: 15px;
        margin: 12px 0;
      }
      
      .amount-value {
        font-size: 1.8rem;
      }
      
      .file-upload-label {
        padding: 10px 16px;
        font-size: 0.85rem;
      }
      
      .info-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
        padding: 6px 0;
      }
      
      .info-item .label {
        font-size: 0.8rem;
      }
      
      .info-item .value {
        font-size: 0.9rem;
        font-weight: 600;
      }
    }
    
    @media (max-width: 480px) {
      #walkinPosModal .modal-content {
        width: 100%;
        margin: 0;
        border-radius: 0;
        min-height: 100vh;
      }
      
      #posContent {
        padding: 10px;
      }
      
      .pos-section {
        padding: 10px;
        margin-bottom: 10px;
      }
      
      .amount-display {
        padding: 12px;
      }
      
      .amount-value {
        font-size: 1.6rem;
      }
      
      .file-upload-label {
        padding: 8px 12px;
        font-size: 0.8rem;
      }
    }
    /* Walk-in Reference Modal Enhanced Styles */
    .walkin-ref-modal-content {
      max-width: 550px;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
      animation: slideInModal 0.3s ease-out;
    }
    
    @keyframes slideInModal {
      from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
    
    .walkin-ref-modal-header {
      background: linear-gradient(135deg, #5e63ff 0%, #4a52e8 50%, #3d44d4 100%);
      padding: 20px 25px;
      border-bottom: none;
      border-radius: 0;
    }
    
    .walkin-ref-modal-body {
      padding: 25px;
      background: var(--hover-clr);
    }
    
    .walkin-ref-customer-card {
      background: var(--bg-clr);
      border: 2px solid #5e63ff;
      border-radius: 12px;
      padding: 18px;
      margin-bottom: 20px;
      transition: all 0.3s ease;
    }
    
    .walkin-ref-customer-card:hover {
      border-color: #5e63ff;
      box-shadow: 0 4px 12px rgba(94, 99, 255, 0.15);
    }
    
    .walkin-ref-customer-name {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--text-clr);
      padding: 12px;
      background: var(--hover-clr);
      border-radius: 8px;
      border: 1px solid var(--line-clr);
    }
    
    .walkin-ref-number-card {
      background: var(--bg-clr);
      border: 2px solid #5e63ff;
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 20px;
    }
    
    .walkin-ref-copy-btn {
      background: var(--bg-clr);
      border: 1px solid var(--line-clr);
      color: var(--text-clr);
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 0.85rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    .walkin-ref-copy-btn:hover {
      background: var(--hover-clr);
      border-color: var(--accent-clr);
    }
    
    .walkin-ref-copy-btn:active {
      transform: translateY(0);
    }
    
    .walkin-ref-display {
      background: var(--hover-clr);
      border: 1px solid var(--line-clr);
      color: var(--text-clr);
      font-weight: 600;
      font-size: 1.3rem;
      text-align: center;
      padding: 20px;
      letter-spacing: 2px;
      font-family: 'Courier New', 'Consolas', monospace;
      border-radius: 8px;
      margin-top: 8px;
      word-break: break-all;
    }
    
    .walkin-ref-copy-success {
      display: flex;
      align-items: center;
      justify-content: center;
      margin-top: 12px;
      padding: 8px;
      background: rgba(40, 167, 69, 0.15);
      border: 1px solid #28a745;
      color: #28a745;
      border-radius: 6px;
      font-size: 0.85rem;
      font-weight: 500;
      animation: fadeInSuccess 0.3s ease;
    }
    
    @keyframes fadeInSuccess {
      from {
        opacity: 0;
        transform: translateY(-5px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    /* Mobile Responsive */
    @media (max-width: 768px) {
      .walkin-ref-modal-content {
        width: 95%;
        margin: 5% auto;
      }
      
      .walkin-ref-modal-header {
        padding: 16px 20px;
      }
      
      .walkin-ref-modal-body {
        padding: 20px;
      }
      
      .walkin-ref-display {
        font-size: 1.2rem;
        padding: 16px;
        letter-spacing: 2px;
      }
    }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<main>
  <h1>Walk-in Log</h1>

  <!-- Personal Contribution Summary -->
  <div class="dashboard-grid">
    <div class="card">
      <h2><?= isset($today_walkins['count']) ? $today_walkins['count'] : 0 ?></h2>
      <p>Walk-ins Handled Today</p>
    </div>
    <div class="card">
      <h2>₱<?= number_format(isset($today_walkins['total']) ? $today_walkins['total'] : 0, 2) ?></h2>
      <p>Today's Walk-in Revenue</p>
    </div>
    <div class="card">
      <h2><?= isset($monthly_walkins['count']) ? $monthly_walkins['count'] : 0 ?></h2>
      <p>Total Walk-ins This Month</p>
    </div>
    <div class="card">
      <h2>₱<?= number_format(isset($monthly_walkins['total']) ? $monthly_walkins['total'] : 0, 2) ?></h2>
      <p>Monthly Walk-in Revenue</p>
    </div>
  </div>

  <!-- Action Buttons -->
  <div class="button-group" style="margin-bottom: 1rem;">
    <button id="addWalkinBtn" class="add-walkin-btn">    
      <i class='bx bx-plus'></i>
      Add Walk-in
    </button>
  </div>
  
  <?php if ($error): ?><div class="alert error"><?= $error ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert success"><?= $success ?></div><?php endif; ?>

  <!-- Walk-in Modal -->
  <div id="walkinModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="modalTitle">Add Walk-in Customer</h2>
        <span class="close">&times;</span>
      </div>
      <form id="walkinForm" method="POST" enctype="multipart/form-data" onsubmit="return openPOSModal(event)">
        <input type="hidden" name="edit_id" id="editId">
        <input type="hidden" name="customer_photo_data" id="customerPhotoData">
        
        <!-- Photo Capture Section -->
        <div class="form-group">
          <label for="customerPhoto">Take Customer Photo *</label>
          <div id="photoCaptureContainer" style="display: flex; flex-direction: column; gap: 12px; background: #1f2028; padding: 16px; border-radius: 10px; border: 1px solid #2a2b36;">
            <div style="position: relative; width: 100%; max-width: 400px; margin: 0 auto;">
              <video id="videoElement" autoplay playsinline style="width: 100%; border-radius: 10px; display: none; background: #1c1d25; box-shadow: 0 4px 12px rgba(0,0,0,0.3);"></video>
              <canvas id="photoCanvas" style="display: none;"></canvas>
              <div id="photoPreviewContainer" style="width: 100%; min-height: 220px; border: 2px dashed #3c3d4a; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #1c1d25; position: relative; transition: all 0.3s ease;">
                <img id="photoPreview" src="" alt="Customer Photo" style="max-width: 100%; max-height: 300px; border-radius: 8px; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">
                <div id="photoPlaceholder" style="text-align: center; color: #666; padding: 20px;">
                  <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 12px; opacity: 0.6;">
                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                    <circle cx="12" cy="13" r="4"></circle>
                  </svg>
                  <p style="margin: 0; font-size: 0.9rem; font-weight: 500;">No photo taken</p>
                </div>
              </div>
            </div>
            <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
              <button type="button" id="startCameraBtn" onclick="startCamera()" style="background: #5e63ff; color: white; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(94, 99, 255, 0.3);">
                📷 Start Camera
              </button>
              <button type="button" id="capturePhotoBtn" onclick="capturePhoto()" style="background: #28a745; color: white; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; display: none; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);">
                📸 Capture Photo
              </button>
              <button type="button" id="retakePhotoBtn" onclick="retakePhoto()" style="background: #ffc107; color: #212529; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; display: none; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);">
                🔄 Retake
              </button>
              <button type="button" id="stopCameraBtn" onclick="stopCamera()" style="background: #dc3545; color: white; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9rem; display: none; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);">
                ⏹ Stop Camera
              </button>
            </div>
            <small style="color: #999; text-align: center; font-size: 0.85rem; font-weight: 500; margin-top: 4px;">Photo is required before proceeding</small>
          </div>
        </div>
        
        <div class="form-group">
          <label for="customerName">Customer Full Name *</label>
          <input type="text" id="customerName" name="name" required placeholder="e.g., Trixyle Anne H. Asuncion" pattern="[A-Za-z\s\.]+" title="Please enter full name with initials (e.g., Trixyle Anne H. Asuncion)">
        </div>
        
        <div class="form-group">
          <label for="packageSelect">Package *</label>
          <select id="packageSelect" name="package" required>
            <option value="">Select a package</option>
            <option value="Package 1" data-price="300" data-description="Circuit Training, Weight Loss, Strength and Conditioning, Athletic training, Weights Training">Package 1 - ₱300</option>
            <option value="Package 2" data-price="400" data-description="Muay Thai, Boxing, Circuit Training, Weight Loss, Strength and Conditioning, Athletic training, Weights Training">Package 2 - ₱400</option>
            <option value="Package 3" data-price="350" data-description="Boxing">Package 3 - ₱350</option>
          </select>
          <div id="packageDescription" class="package-description"></div>
        </div>
        
        <div class="form-group">
          <label for="fixedAmount">Fixed Amount</label>
          <input type="text" id="fixedAmount" readonly>
        </div>
        
        <div class="form-group" id="amountGivenGroup" style="display: none;">
          <label for="amountGiven">Amount Given</label>
          <input type="text" id="amountGiven" name="amount_given" readonly>
        </div>
        
        <div class="form-group" id="changeAmountGroup" style="display: none;">
          <label for="changeAmount">Change</label>
          <input type="text" id="changeAmount" name="change_amount" readonly>
        </div>
        
        <div class="form-group">
          <label for="paymentMethod">Payment Method *</label>
          <select id="paymentMethod" name="payment_method" required>
            <option value="">Select payment method</option>
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
          </select>
        </div>
        
        <input type="hidden" name="date" value="<?= date('Y-m-d') ?>">
        <input type="hidden" name="amount" id="hiddenAmount">
        <input type="hidden" name="change_amount" id="hiddenChangeAmount">
        
        <div class="modal-actions">
          <button type="button" class="cancel-btn">Cancel</button>
          <button type="submit" class="save-btn">Proceed</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Walk-in POS Modal -->
  <div id="walkinPosModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
      <div class="modal-header">
        <h2>Point of Sale (POS)</h2>
        <span class="close" onclick="closeWalkinPOSModal()">&times;</span>
      </div>
      <div id="posContent">
        <!-- Customer Information -->
        <div class="pos-section">
          <h3>Customer Information</h3>
          <div class="pos-info">
            <div class="info-item">
              <span class="label">Name:</span>
              <span class="value" id="posCustomerName"></span>
            </div>
            <div class="info-item">
              <span class="label">Package:</span>
              <span class="value" id="posPackage"></span>
            </div>
            <div class="info-item">
              <span class="label">Payment Method:</span>
              <select id="posPaymentMethod" name="payment_method" required style="width: 100%; padding: 8px 12px; border: 1px solid #333; border-radius: 6px; background: var(--line-clr); color: #e0e0e0;">
                <option value="">Select payment method</option>
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Amount to Pay Section -->
        <div class="pos-section">
          <h3>Amount to Pay</h3>
          <div class="amount-display">
            <span class="amount-value" id="posAmountToPay"></span>
          </div>
        </div>

        <!-- Cash Payment Section (Only for Cash) -->
        <div class="pos-section" id="cashPaymentSection" style="display: none;">
          <h3>Cash Payment</h3>
          <div class="form-group">
            <label for="posAmountGiven">Amount Given *</label>
            <input type="number" id="posAmountGiven" step="0.01" min="0" onchange="calculateChange()">
          </div>
          <div class="form-group checkbox-group">
            <label>
              <input type="checkbox" id="posExactAmount" onchange="handleExactAmount()"> Exact Amount
            </label>
          </div>
          <div class="form-group">
            <label for="posChange">Change</label>
            <input type="text" id="posChange" readonly>
          </div>
        </div>

        <!-- GCash Reference Number Section (Only for GCash) -->
        <div class="pos-section" id="gcashRefSection" style="display: none;">
          <h3>GCash Payment Details</h3>
          <div class="form-group">
            <label for="gcashReferenceNumber">GCash Reference Number *</label>
            <input type="text" id="gcashReferenceNumber" name="gcash_reference_number" placeholder="Enter GCash reference number" maxlength="13" pattern="[0-9]{13}" oninput="this.value = this.value.replace(/[^0-9]/g, '')" onblur="checkGCashReferenceAvailability()" style="width: 100%; padding: 10px 12px; border: 1px solid #333; border-radius: 6px; background: var(--line-clr); color: #e0e0e0;">
            <div id="gcash-reference-validation-notice" style="margin-top: 0.5rem; font-size: 0.875rem; display: none;">
              <span id="gcash-reference-validation-message"></span>
            </div>
          </div>
        </div>

        <!-- Receipt Upload Section -->
        <div class="pos-section" id="receiptSection">
          <h3 id="receiptTitle">Upload Receipt</h3>
          <div class="file-upload-container">
            <input type="file" id="receiptFile" name="receipt_file" accept="image/*" onchange="handleReceiptUpload()">
            <label for="receiptFile" class="file-upload-label">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-right: 8px;">
                <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
              </svg>
              <span id="uploadButtonText">Choose Receipt Photo</span>
            </label>
            <div class="file-status" id="fileStatus" style="display: none;">
              <span class="file-name" id="fileName"></span>
              <button type="button" onclick="removeReceiptUpload()" class="remove-file">×</button>
            </div>
          </div>
          <small id="uploadNote" style="color: #ffc107; font-size: 0.8rem; display: block; margin-top: 5px;">
            ⚠️ Receipt photo required before recording walk-in customer
          </small>
        </div>

        <div class="modal-actions">
          <button type="button" class="cancel-btn" onclick="closeWalkinPOSModal()">Cancel</button>
          <button type="button" class="save-btn" id="saveWalkinBtn" onclick="saveWalkinWithReceipt()" disabled style="opacity: 0.5; cursor: not-allowed;">
            Record Walk-in Customer
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Walk-in Table -->
  <!-- Date Navigation -->
  <div class="date-navigation" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0; padding-bottom: 10px;">
     <a href="?date=<?= date('Y-m-d', strtotime($filter_date . ' -1 day')) ?>" class="nav-arrow nav-arrow-left" style="display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; background: linear-gradient(135deg, #2a2b36 0%, #1c1d25 100%); color: #e0e0e0; border-radius: 16px; text-decoration: none; font-size: 20px; font-weight: bold; transition: all 0.3s ease; border: 2px solid #444; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-left: -2px;">
        <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/>
      </svg>
    </a>
    
     <div class="current-date" id="currentDateDisplay" style="text-align: center; flex: 0 0 auto; padding: 12px 20px; background: linear-gradient(135deg, #2a2b36 0%, #1c1d25 100%); border-radius: 16px; border: 2px solid #444; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3); min-width: 280px; max-width: 320px; cursor: pointer; transition: all 0.3s ease;" onclick="openCalendar()">
      <h3 style="margin: 0; color: var(--accent-clr); font-size: 1.3rem; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.1);">
        <?= date('F j, Y', strtotime($filter_date)) ?>
      </h3>
      <?php if ($total_records > 0): ?>
        <p style="margin: 6px 0 0; color: #4caf50; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 4px;">
          <span style="display: inline-block; width: 6px; height: 6px; background: #4caf50; border-radius: 50%;"></span>
          <?= $total_records ?> walk-in(s) recorded
        </p>
      <?php endif; ?>
    </div>
    
     <?php 
     $today = date('Y-m-d');
     $next_day = date('Y-m-d', strtotime($filter_date . ' +1 day'));
     $is_future = $next_day > $today;
     ?>
     <?php if ($is_future): ?>
       <div class="nav-arrow nav-arrow-right" style="display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; background: linear-gradient(135deg, #1a1a1a 0%, #0f0f0f 100%); color: #666; border-radius: 16px; font-size: 20px; font-weight: bold; border: 2px solid #333; cursor: not-allowed; opacity: 0.5;">
         <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-right: -2px;">
           <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/>
         </svg>
       </div>
     <?php else: ?>
       <a href="?date=<?= $next_day ?>" class="nav-arrow nav-arrow-right" style="display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; background: linear-gradient(135deg, #2a2b36 0%, #1c1d25 100%); color: #e0e0e0; border-radius: 16px; text-decoration: none; font-size: 20px; font-weight: bold; transition: all 0.3s ease; border: 2px solid #444; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);">
         <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-right: -2px;">
           <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/>
         </svg>
       </a>
     <?php endif; ?>
  </div>


  <div class="table-container">
    <table>
    <thead>
      <tr>
        <th>Photo</th>
        <th>Name</th>
        <th>Package Availed</th>
        <th>Price (₱)</th>
        <th>Payment Method</th>
        <th>Receipt</th>
        <th>Walk in Ref.</th>
        <th>Date</th>
        <th>Status</th>
        <th class="actions-column">Actions</th>
        <th class="convert-column">Convert</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($logs->num_rows > 0): ?>
        <?php while ($row = $logs->fetch_assoc()): ?> 
          <tr>
            <td>
              <?php if (!empty($row['customer_photo'])): ?>
                <img src="../<?= htmlspecialchars($row['customer_photo']) ?>" alt="Customer Photo" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px; cursor: pointer;" onclick="viewPhotoModal('../<?= htmlspecialchars($row['customer_photo'], ENT_QUOTES) ?>')">
              <?php else: ?>
                <span style="color: #6c757d; font-size: 0.8rem;">N/A</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?php
                $package = htmlspecialchars($row['package']);
                $packageColor = '';
                if (stripos($package, 'Package 1') === 0) {
                    $packageColor = '#9BF6FF';
                } elseif (stripos($package, 'Package 2') === 0) {
                    $packageColor = '#FDFFB6';
                } elseif (stripos($package, 'Package 3') === 0) {
                    $packageColor = '#FFADAD';
                }
                echo '<span style="color: ' . ($packageColor ? $packageColor : 'inherit') . '; font-weight: 600;">' . $package . '</span>';
            ?></td>
            <td>₱<?= number_format($row['amount'], 2) ?></td>
            <td><?= ucfirst($row['payment_method'] ?? 'N/A') ?></td>
            <td>
              <div style="display: flex; flex-direction: column; gap: 4px;">
                <?php if (!empty($row['receipt_path'])): ?>
                  <?php 
                    $isGCash = strtolower($row['payment_method'] ?? '') === 'gcash';
                    $gcashRefNumber = '';
                    if ($isGCash && !empty($row['reference_number'] ?? '')) {
                        $gcashRefNumber = htmlspecialchars(trim($row['reference_number']), ENT_QUOTES);
                    }
                  ?>
                  <button onclick="openReceiptViewer('<?= htmlspecialchars($row['receipt_path'], ENT_QUOTES) ?>', <?= $isGCash ? 'true' : 'false' ?>, '<?= $gcashRefNumber ?>')" 
                          style="background: #28a745; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8rem; width: fit-content;">
                    View Photo
                  </button>
                <?php else: ?>
                  <span style="color: #6c757d; font-size: 0.8rem;">N/A</span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <?php if (!empty($row['walkin_ref'])): ?>
                <button onclick="showWalkinRef('<?= htmlspecialchars($row['walkin_ref'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['package'], ENT_QUOTES) ?>')" 
                        style="background: #5e63ff; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8rem; width: fit-content;">
                  Show Ref.
                </button>
              <?php else: ?>
                <span style="color: #6c757d; font-size: 0.8rem;">N/A</span>
              <?php endif; ?>
            </td>
            <td><?= date('M d, Y', strtotime($row['date'])) ?><br><span style="color: #888; font-size: 0.9em;"><?= date('g:i A', strtotime($row['date'])) ?></span></td>
            <td><?= $row['status'] ?? 'Pending' ?></td>
            <td class="actions-column">
              <?php 
                $current_status = $row['status'] ?? 'Pending';
                if ($current_status === 'Missed Session'): 
                // Check if customer has an active member account
                $has_active_member = hasActiveMember($conn, $row['name']);
                if ($has_active_member): ?>
                <!-- Disabled buttons for missed session customers with active member -->
                <a href="#" style="display: inline-block; width: 80px; text-align: center; padding: 4px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.7rem; margin: 1px 2px; transition: all 0.3s ease; border: none; cursor: not-allowed; vertical-align: top; box-sizing: border-box; background: #ccc; color: #666; opacity: 0.5; pointer-events: none;" title="Cannot edit - customer has active member">
                  Edit
                </a>
                <a href="#" style="display: inline-block; width: 80px; text-align: center; padding: 4px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.7rem; margin: 1px 2px; transition: all 0.3s ease; border: none; cursor: not-allowed; vertical-align: top; box-sizing: border-box; background: #ccc; color: #666; opacity: 0.5; pointer-events: none;" title="Cannot modify - customer has active member">
                  Time in
                </a>
                <?php else: ?>
                <!-- Enabled buttons for missed session customers without active member -->
                <a href="#" onclick="editWalkin(<?= $row['id'] ?>, '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['package'], ENT_QUOTES) ?>', <?= $row['amount'] ?>, <?= isset($row['amount_given']) ? $row['amount_given'] : $row['amount'] ?>, <?= isset($row['change_amount']) ? $row['change_amount'] : 0 ?>, '<?= $row['date'] ?>'); return false;"
                  style="display: inline-block; width: 80px; text-align: center; padding: 4px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.7rem; margin: 1px 2px; transition: all 0.3s ease; border: none; cursor: pointer; vertical-align: top; box-sizing: border-box; background: #4a52e8; color: white;">
                  Edit
                </a>
                <a href="#" onclick="toggleTimeInStatus(<?= $row['id'] ?>, 'Timed in'); return false;"
                  style="display: inline-block; width: 80px; text-align: center; padding: 4px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.7rem; margin: 1px 2px; transition: all 0.3s ease; border: none; cursor: pointer; vertical-align: top; box-sizing: border-box; background: #28a745; color: white;">
                  Time in
                </a>
                <?php endif; ?>
              <?php else: ?>
                <!-- Active buttons for non-converted customers -->
                <a href="#" onclick="editWalkin(<?= $row['id'] ?>, '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['package'], ENT_QUOTES) ?>', <?= $row['amount'] ?>, <?= isset($row['amount_given']) ? $row['amount_given'] : $row['amount'] ?>, <?= isset($row['change_amount']) ? $row['change_amount'] : 0 ?>, '<?= $row['date'] ?>'); return false;"
                  style="display: inline-block; width: 80px; text-align: center; padding: 4px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.7rem; margin: 1px 2px; transition: all 0.3s ease; border: none; cursor: pointer; vertical-align: top; box-sizing: border-box; background: #4a52e8; color: white;">
                  Edit
                </a>
                <?php if ($current_status === 'Timed in'): ?>
                  <a href="#" onclick="toggleTimeInStatus(<?= $row['id'] ?>, 'Pending'); return false;"
                    style="display: inline-block; width: 80px; text-align: center; padding: 4px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.7rem; margin: 1px 2px; transition: all 0.3s ease; border: none; cursor: pointer; vertical-align: top; box-sizing: border-box; background: #ffc107; color: #212529;">
                    Pending
                  </a>
                <?php else: ?>
                  <a href="#" onclick="toggleTimeInStatus(<?= $row['id'] ?>, 'Timed in'); return false;"
                    style="display: inline-block; width: 80px; text-align: center; padding: 4px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.7rem; margin: 1px 2px; transition: all 0.3s ease; border: none; cursor: pointer; vertical-align: top; box-sizing: border-box; background: #28a745; color: white;">
                    Time in
                  </a>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="convert-column">
              <?php 
                $can_convert = canConvertWalkin($conn, $row['id'], $row['name']);
                if ($can_convert): ?>
                <a href="#" onclick="convertWalkinToMember(<?= $row['id'] ?>, '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['package'], ENT_QUOTES) ?>', <?= $row['amount'] ?>); return false;"
                  style="display: inline-block; width: 80px; text-align: center; padding: 4px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.7rem; margin: 1px 2px; transition: all 0.3s ease; border: none; cursor: pointer; vertical-align: top; box-sizing: border-box; background: #1873CC; color: white;">
                  Convert
                </a>
              <?php else: ?>
                <a href="#" style="display: inline-block; width: 80px; text-align: center; padding: 4px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.7rem; margin: 1px 2px; transition: all 0.3s ease; border: none; cursor: pointer; vertical-align: top; box-sizing: border-box; background: #1873CC; color: white; opacity: 0.5; cursor: not-allowed; pointer-events: none;" title="Cannot convert - subscription is still active">
                  Convert
                </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="11">No walk-in records found.</td></tr>
      <?php endif; ?>
    </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div class="pagination-container" style="display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 20px; flex-wrap: wrap;">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page - 1 ?>&date=<?= $filter_date ?>" class="pagination-btn" style="padding: 8px 12px; background: var(--accent-clr); color: white; text-decoration: none; border-radius: 6px; font-weight: 500;">&laquo; Previous</a>
    <?php endif; ?>
    
    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
      <?php if ($i == $page): ?>
        <span class="pagination-current" style="padding: 8px 12px; background: var(--line-clr); color: var(--accent-clr); border-radius: 6px; font-weight: 600;"><?= $i ?></span>
      <?php else: ?>
        <a href="?page=<?= $i ?>&date=<?= $filter_date ?>" class="pagination-btn" style="padding: 8px 12px; background: #555; color: white; text-decoration: none; border-radius: 6px; font-weight: 500;"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    
    <?php if ($page < $total_pages): ?>
      <a href="?page=<?= $page + 1 ?>&date=<?= $filter_date ?>" class="pagination-btn" style="padding: 8px 12px; background: var(--accent-clr); color: white; text-decoration: none; border-radius: 6px; font-weight: 500;">Next &raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</main>


<!-- Calendar Modal -->
<div id="calendarModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 400px; width: 90%;">
    <div class="modal-header">
      <h2>Select Date</h2>
      <span class="close" onclick="closeCalendar()">&times;</span>
    </div>
    <div style="padding: 20px;">
      <div id="calendar" style="background: var(--hover-clr); border-radius: 8px; padding: 15px;"></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('walkinModal');
    const addBtn = document.getElementById('addWalkinBtn');
    const convertBtn = document.getElementById('convertToMemberBtn');
    const closeBtn = document.querySelector('.close');
    const cancelBtn = document.querySelector('.cancel-btn');
    const packageSelect = document.getElementById('packageSelect');
    const fixedAmountInput = document.getElementById('fixedAmount');
    const amountGivenInput = document.getElementById('amountGiven');
    const exactAmountCheckbox = document.getElementById('exactAmount');
    const changeAmountInput = document.getElementById('changeAmount');
    const packageDescription = document.getElementById('packageDescription');
    const walkinForm = document.getElementById('walkinForm');

    // Open modal
    addBtn.onclick = function() {
        modal.style.display = 'block';
        resetForm();
        document.getElementById('modalTitle').textContent = 'Add Walk-in Customer';
        document.getElementById('editId').value = '';
        
        // Hide edit-specific fields for new entries
        document.getElementById('amountGivenGroup').style.display = 'none';
        document.getElementById('changeAmountGroup').style.display = 'none';
        
        // Show payment method field for new entries
        document.getElementById('paymentMethod').closest('.form-group').style.display = 'block';
        
        // Re-enable package select for new entries
        document.getElementById('packageSelect').disabled = false;
        document.getElementById('packageSelect').style.backgroundColor = '';
        document.getElementById('packageSelect').style.color = '';
        
        // Reset form submission to POS modal for new entries
        walkinForm.onsubmit = function(e) {
            return openPOSModal(e);
        };
    }


    // Convert walk-in to member functionality
    window.convertWalkinToMember = function(id, name, package, amount) {
        // Populate the conversion modal with walk-in data
        document.getElementById('convertWalkinId').value = id;
        document.getElementById('convertCustomerName').value = name;
        
        // Show the conversion modal
        document.getElementById('convertModal').style.display = 'block';
    }

    // Close convert modal
    window.closeConvertModal = function() {
        document.getElementById('convertModal').style.display = 'none';
        document.getElementById('convertForm').reset();
        clearValidationNotices();
        
        const emailInput = document.getElementById('memberEmail');
        const phoneInput = document.getElementById('memberPhone');
        if (emailInput) emailInput.classList.remove('valid-input');
        if (phoneInput) phoneInput.classList.remove('valid-input');
        
        // Reset status messages
        const emailStatus = document.getElementById('emailStatus');
        const passwordStatus = document.getElementById('passwordStatus');
        const phoneStatus = document.getElementById('phoneStatus');
        
        if (emailStatus) {
            emailStatus.textContent = '';
            emailStatus.style.display = 'none';
            emailStatus.style.color = '';
        }
        if (passwordStatus) {
            passwordStatus.textContent = '';
            passwordStatus.style.color = '';
        }
        if (phoneStatus) {
            phoneStatus.textContent = '';
            phoneStatus.style.display = 'none';
            phoneStatus.style.color = '';
        }
    }

    // Email availability checking
    window.checkEmailAvailability = function() {
        const emailInput = document.getElementById('memberEmail');
        const emailStatus = document.getElementById('emailStatus');
        if (!emailInput || !emailStatus) {
            return;
        }
        
        const email = emailInput.value.trim();
        emailInput.classList.remove('valid-input');
        emailStatus.textContent = '';
        emailStatus.style.display = 'none';
        emailStatus.style.color = '#dc3545';
        
        if (!email) {
            return;
        }
        
        // Basic email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            emailStatus.style.display = 'block';
            emailStatus.textContent = 'Please enter a valid email address';
            return;
        }
        
        emailStatus.style.display = 'block';
        emailStatus.style.color = '#ffc107';
        emailStatus.textContent = 'Checking availability...';
        
        // Check email availability
        const formData = new FormData();
        formData.append('email', email);
        
        fetch('../check_email_availability.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.available) {
                emailStatus.style.display = 'none';
                emailStatus.textContent = '';
                emailInput.classList.add('valid-input');
            } else {
                emailStatus.style.display = 'block';
                emailStatus.style.color = '#dc3545';
                emailStatus.textContent = data.message || 'Email already in use. Please input another email';
                emailInput.classList.remove('valid-input');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            emailStatus.style.display = 'block';
            emailStatus.style.color = '#dc3545';
            emailStatus.textContent = 'Error checking email availability';
            emailInput.classList.remove('valid-input');
        });
    }

    // Phone availability checking
    window.checkPhoneAvailability = function() {
        const phoneInput = document.getElementById('memberPhone');
        const phoneStatus = document.getElementById('phoneStatus');
        if (!phoneInput || !phoneStatus) {
            return;
        }
        
        const phone = phoneInput.value.trim();
        phoneInput.classList.remove('valid-input');
        phoneStatus.textContent = '';
        phoneStatus.style.display = 'none';
        phoneStatus.style.color = '#dc3545';
        
        if (!phone) {
            return;
        }
        
        // Basic phone validation
        if (phone.length !== 11 || !phone.startsWith('09')) {
            phoneStatus.style.display = 'block';
            phoneStatus.textContent = 'Phone number must be 11 digits starting with 09';
            return;
        }
        
        phoneStatus.style.display = 'block';
        phoneStatus.style.color = '#ffc107';
        phoneStatus.textContent = 'Checking availability...';
        
        // Check phone availability
        const formData = new FormData();
        formData.append('phone', phone);
        
        fetch('../check_phone_availability.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.available) {
                phoneStatus.style.display = 'none';
                phoneStatus.textContent = '';
                phoneInput.classList.add('valid-input');
            } else {
                phoneStatus.style.display = 'block';
                phoneStatus.style.color = '#dc3545';
                phoneStatus.textContent = data.message || 'Phone number already in use. Please input another number';
                phoneInput.classList.remove('valid-input');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            phoneStatus.style.display = 'block';
            phoneStatus.style.color = '#dc3545';
            phoneStatus.textContent = 'Error checking phone availability';
            phoneInput.classList.remove('valid-input');
        });
    }

    // Toggle password visibility
    window.togglePasswordVisibility = function() {
        const passwordInput = document.getElementById('memberPassword');
        const passwordIcon = document.getElementById('passwordIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            passwordIcon.className = 'bx bx-show';
        } else {
            passwordInput.type = 'password';
            passwordIcon.className = 'bx bx-hide';
        }
    }

    // Password validation
    window.validatePassword = function() {
        const password = document.getElementById('memberPassword').value;
        const passwordInput = document.getElementById('memberPassword');
        const notice = document.getElementById('password-notice');
        const message = document.getElementById('password-validation-message');
        
        if (!notice || !message) return;
        
        if (password.length === 0) {
            notice.style.display = 'none';
            passwordInput.classList.remove('valid-input');
            return;
        }
        
        const errors = [];
        
        // Check minimum length
        if (password.length < 6) {
            errors.push('at least 6 characters long');
        }
        
        // Check for uppercase letter
        if (!/[A-Z]/.test(password)) {
            errors.push('at least 1 uppercase letter');
        }
        
        // Check for number
        if (!/[0-9]/.test(password)) {
            errors.push('at least 1 number');
        }
        
        if (errors.length > 0) {
            notice.style.display = 'block';
            message.textContent = 'Password must be ' + errors.join(', ') + '.';
            passwordInput.classList.remove('valid-input');
        } else {
            // Password is valid - hide notice and show green border
            notice.style.display = 'none';
            passwordInput.classList.add('valid-input');
        }
    }

    // Phone number formatting
    window.formatPhoneNumber = function() {
        const phoneInput = document.getElementById('memberPhone');
        if (!phoneInput) {
            return;
        }
        let phone = phoneInput.value.replace(/\D/g, ''); // Remove non-digits
        
        // Limit to 11 digits
        if (phone.length > 11) {
            phone = phone.substring(0, 11);
        }
        
        phoneInput.value = phone;
        phoneInput.classList.remove('valid-input');
    }


    // Proceed to OTP Verification
    window.proceedToOTPVerification = function() {
        // Get all field values
        const email = document.getElementById('memberEmail').value;
        const password = document.getElementById('memberPassword').value;
        const phone = document.getElementById('memberPhone').value;
        const membershipPlan = document.getElementById('membershipPlan').value;
        const customerName = document.getElementById('convertCustomerName').value;
        
        // Clear previous validation notices
        clearValidationNotices();
        
        // Validate each field and show notices
        let hasErrors = false;
        
        // Validate customer name
        if (!customerName || customerName.trim() === '') {
            showFieldNotice('convertCustomerName', 'Customer name is required');
            hasErrors = true;
        }
        
        // Validate email
        if (!email || email.trim() === '') {
            showFieldNotice('memberEmail', 'Email address is required');
            hasErrors = true;
        }
        
        // Validate password
        if (!password || password.trim() === '') {
            showFieldNotice('memberPassword', 'Password is required');
            hasErrors = true;
        } else {
            const hasCapitalLetter = /[A-Z]/.test(password);
            const hasNumber = /\d/.test(password);
            const isLongEnough = password.length >= 6;
            
            if (!isLongEnough || !hasCapitalLetter || !hasNumber) {
                showFieldNotice('memberPassword', 'Password must have at least 6 characters, one capital letter, and one number');
                hasErrors = true;
            }
        }
        
        // Validate phone
        if (!phone || phone.trim() === '') {
            showFieldNotice('memberPhone', 'Phone number is required');
            hasErrors = true;
        } else if (phone.length !== 11 || !phone.startsWith('09')) {
            showFieldNotice('memberPhone', 'Phone number must be 11 digits starting with 09');
            hasErrors = true;
        }
        
        // Validate membership plan
        if (!membershipPlan || membershipPlan.trim() === '') {
            showFieldNotice('membershipPlan', 'Please select a membership plan');
            hasErrors = true;
        }
        
        // If there are errors, don't proceed
        if (hasErrors) {
            return;
        }
        
        // All validations passed, submit form to OTP handler
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'walkin_otp_handler.php';
        
        // Add form fields
        const fields = {
            'create_walkin_otp': '1',
            'full_name': customerName,
            'email': email,
            'password': password,
            'phone': phone,
            'address': document.getElementById('memberAddress').value || '',
            'membership_plan': membershipPlan,
            'walkin_id': document.getElementById('convertWalkinId').value
        };
        
        for (const [name, value] of Object.entries(fields)) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }
        
        // Submit form
        document.body.appendChild(form);
        form.submit();
    }
    
    // Show field validation notice
    function showFieldNotice(fieldId, message) {
        const field = document.getElementById(fieldId);
        const noticeId = fieldId + 'Notice';
        
        // Remove existing notice
        const existingNotice = document.getElementById(noticeId);
        if (existingNotice) {
            existingNotice.remove();
        }
        
        if (!field) {
            return;
        }
        
        field.classList.remove('valid-input');
        
        // Create new notice
        const notice = document.createElement('div');
        notice.id = noticeId;
        notice.style.color = '#dc3545';
        notice.style.fontSize = '0.8rem';
        notice.style.marginTop = '5px';
        notice.textContent = message;
        
        // Insert notice after the field
        field.parentNode.insertBefore(notice, field.nextSibling);
        
        // Highlight field
        field.style.borderColor = '#dc3545';
    }
    
    // Clear all validation notices
    function clearValidationNotices() {
        const notices = document.querySelectorAll('[id$="Notice"]');
        notices.forEach(notice => notice.remove());
        
        // Reset field borders
        const fields = ['convertCustomerName', 'memberEmail', 'memberPassword', 'memberPhone', 'membershipPlan'];
        fields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.style.borderColor = '';
                field.style.boxShadow = '';
                field.classList.remove('valid-input');
            }
        });

        const statusElements = ['emailStatus', 'phoneStatus'];
        statusElements.forEach(statusId => {
            const status = document.getElementById(statusId);
            if (status) {
                status.textContent = '';
                status.style.display = 'none';
                status.style.color = '';
            }
        });
    }


    // Show POS Modal
    window.showPosModal = function() {
        // Get form data
        const customerName = document.getElementById('convertCustomerName').value;
        const membershipPlanSelect = document.getElementById('membershipPlan');
        const membershipPlan = membershipPlanSelect.value;
        const membershipPlanText = membershipPlanSelect.options[membershipPlanSelect.selectedIndex].text;
        
        // Extract price from membership plan text (display text contains the price)
        const priceMatch = membershipPlanText.match(/₱([0-9,]+)/);
        const packageAmount = priceMatch ? parseFloat(priceMatch[1].replace(/,/g, '')) : 0;
        
        // Populate POS modal
        document.getElementById('posCustomerName').textContent = customerName;
        document.getElementById('posMembershipPlan').textContent = membershipPlanText;
        document.getElementById('posPackageAmount').textContent = '₱' + packageAmount.toLocaleString();
        document.getElementById('posTotalAmount').textContent = '₱' + packageAmount.toLocaleString();
        
        // Reset payment method selection
        document.getElementById('posPaymentMethod').value = '';
        
        // Show POS modal
        document.getElementById('posModal').style.display = 'block';
    }

    // Close POS Modal
    window.closePosModal = function() {
        document.getElementById('posModal').style.display = 'none';
    }


    // Proceed to Payment
    window.proceedToPayment = function() {
        // Validate payment method
        const paymentMethod = document.getElementById('posPaymentMethod').value;
        if (!paymentMethod) {
            alert('Please select a payment method.');
            return;
        }
        
        // Get all form data
        const formData = new FormData(document.getElementById('convertForm'));
        
        // Add POS data
        const totalAmount = document.getElementById('posTotalAmount').textContent;
        formData.append('total_amount', totalAmount.replace('₱', '').replace(',', ''));
        formData.append('payment_method', paymentMethod);
        
        // Show loading state
        const proceedBtn = document.querySelector('#posModal .save-btn');
        const originalText = proceedBtn.textContent;
        proceedBtn.textContent = 'Processing...';
        proceedBtn.disabled = true;
        
        // Submit form
        formData.append('process_walkin_payment', '1');
        fetch('walkin_pos_payment_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message with credentials
                let message = (data.message || 'Conversion complete') + '\n\n';
                const creds = data.account_details || data.credentials;
                if (creds) {
                    message += 'Login Credentials:\n';
                    if (creds.email) message += 'Email: ' + creds.email + '\n';
                    if (creds.password) message += 'Password: ' + creds.password + '\n';
                }
                alert(message);
                
                // Close modals
                closePosModal();
                closeConvertModal();
                
                // Reload page to show updated data
                if (data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    location.reload();
                }
            } else {
                alert('Error: ' + (data.message || 'Failed to create member account'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while processing the payment.');
        })
        .finally(() => {
            // Reset button state
            proceedBtn.textContent = originalText;
            proceedBtn.disabled = false;
        });
    }


    // Close modal
    function closeModal() {
        modal.style.display = 'none';
        resetForm();
    }

    closeBtn.onclick = closeModal;
    cancelBtn.onclick = closeModal;

    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target == modal) {
            closeModal();
        }
    }

    // Reset form
    function resetForm() {
        walkinForm.reset();
        fixedAmountInput.value = '';
        changeAmountInput.value = '';
        packageDescription.textContent = '';
        document.getElementById('hiddenAmount').value = '';
        document.getElementById('hiddenChangeAmount').value = '';
        
        // Reset photo capture
        retakePhoto();
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
    }

    // Package selection handler
    packageSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            const price = selectedOption.getAttribute('data-price');
            const description = selectedOption.getAttribute('data-description');
            
            fixedAmountInput.value = '₱' + price;
            packageDescription.textContent = description;
            
            // Update hidden amount field
            document.getElementById('hiddenAmount').value = price;
            
            // Auto-fill amount given if exact amount is checked
            if (exactAmountCheckbox.checked) {
                amountGivenInput.value = price;
                calculateChange();
            }
        } else {
            fixedAmountInput.value = '';
            packageDescription.textContent = '';
            document.getElementById('hiddenAmount').value = '';
        }
    });

    // Exact amount checkbox handler
    exactAmountCheckbox.addEventListener('change', function() {
        if (this.checked && packageSelect.value) {
            const selectedOption = packageSelect.options[packageSelect.selectedIndex];
            const price = selectedOption.getAttribute('data-price');
            amountGivenInput.value = price;
            document.getElementById('hiddenAmount').value = price;
            calculateChange();
        }
    });

    // Amount given input handler
    amountGivenInput.addEventListener('input', calculateChange);

    // Calculate change
    function calculateChange() {
        const fixedAmount = packageSelect.value ? 
            parseFloat(packageSelect.options[packageSelect.selectedIndex].getAttribute('data-price')) : 0;
        const amountGiven = parseFloat(amountGivenInput.value) || 0;
        const change = amountGiven - fixedAmount;
        
        // Update hidden fields
        document.getElementById('hiddenAmount').value = fixedAmount;
        document.getElementById('hiddenChangeAmount').value = change;
        
        if (change >= 0) {
            changeAmountInput.value = '₱' + change.toFixed(2);
            changeAmountInput.style.color = '#4caf50';
        } else {
            changeAmountInput.value = '₱' + change.toFixed(2);
            changeAmountInput.style.color = '#f44336';
        }
    }

    // Form submission handler
    walkinForm.addEventListener('submit', function(e) {
        if (!packageSelect.value) {
            e.preventDefault();
            alert('Please select a package');
            return;
        }
        
        if (!amountGivenInput.value || parseFloat(amountGivenInput.value) <= 0) {
            e.preventDefault();
            alert('Please enter a valid amount given');
            return;
        }
        
        // Validate customer name format
        const customerName = document.getElementById('customerName').value.trim();
        if (!validateCustomerName(customerName)) {
            e.preventDefault();
            alert('Please enter the customer\'s full name with initials (e.g., Trixyle Anne H. Asuncion)');
            return;
        }
    });
    
    // Customer name validation function
    function validateCustomerName(name) {
        // Check if name contains at least 2 words (first name and last name)
        const words = name.split(/\s+/).filter(word => word.length > 0);
        if (words.length < 2) {
            return false;
        }
        
        // Check if name contains at least one initial (single letter followed by period or space)
        const hasInitial = /[A-Za-z]\.?\s/.test(name) || /\s[A-Za-z]\.?\s/.test(name);
        
        // Check if name is at least 5 characters long (minimum for a proper name)
        if (name.length < 5) {
            return false;
        }
        
        // Check if name contains only letters, spaces, and periods
        const validPattern = /^[A-Za-z\s\.]+$/;
        return validPattern.test(name);
    }


    // Update status functionality
    window.updateStatus = function(id, status) {
        console.log('updateStatus called with:', id, status);
        if (confirm(`Are you sure you want to mark this walk-in as "${status}"?`)) {
            window.location.href = `?update_status=${id}&status=${encodeURIComponent(status)}`;
        }
    };

    // Calendar functionality - moved to global scope

    // changeMonth and selectDate functions moved to global scope

    // Close calendar when clicking outside
    window.onclick = function(event) {
        const calendarModal = document.getElementById('calendarModal');
        if (event.target == calendarModal) {
            closeCalendar();
        }
    };
});

// Edit functionality - moved to global scope
window.editWalkin = function(id, name, package, amount, amountGiven, changeAmount, date) {
    console.log('editWalkin called with:', id, name, package, amount, amountGiven, changeAmount, date);
    const modal = document.getElementById('walkinModal');
    const packageSelect = document.getElementById('packageSelect');
    const packageDescription = document.getElementById('packageDescription');
    const changeAmountInput = document.getElementById('changeAmount');
    const walkinForm = document.getElementById('walkinForm');
    
    if (!modal) {
        console.error('Modal not found');
        alert('Error: Modal not found. Please refresh the page and try again.');
        return;
    }
    
    modal.style.display = 'block';
    document.getElementById('modalTitle').textContent = 'Edit Walk-in Customer';
    document.getElementById('editId').value = id;
    
    // Show edit-specific fields
    const amountGivenGroup = document.getElementById('amountGivenGroup');
    const changeAmountGroup = document.getElementById('changeAmountGroup');
    if (amountGivenGroup) amountGivenGroup.style.display = 'block';
    if (changeAmountGroup) changeAmountGroup.style.display = 'block';
    
    // Hide payment method field for editing (not needed for old entries)
    const paymentMethodField = document.getElementById('paymentMethod');
    if (paymentMethodField && paymentMethodField.closest('.form-group')) {
        paymentMethodField.closest('.form-group').style.display = 'none';
    }
    
    // Make package select read-only
    if (packageSelect) {
        packageSelect.disabled = true;
        packageSelect.style.backgroundColor = 'var(--line-clr)';
        packageSelect.style.color = '#888';
    }
    
    // Populate form fields
    const customerNameInput = document.getElementById('customerName');
    if (customerNameInput) customerNameInput.value = name;
    
    if (packageSelect) {
        packageSelect.value = package;
    }
    
    const fixedAmountInput = document.getElementById('fixedAmount');
    if (fixedAmountInput) fixedAmountInput.value = '₱' + amount;
    
    const amountGivenInput = document.getElementById('amountGiven');
    if (amountGivenInput) amountGivenInput.value = '₱' + amountGiven;
    
    if (changeAmountInput) {
        changeAmountInput.value = '₱' + changeAmount;
    }
    
    const hiddenAmount = document.getElementById('hiddenAmount');
    if (hiddenAmount) hiddenAmount.value = amount;
    
    const hiddenChangeAmount = document.getElementById('hiddenChangeAmount');
    if (hiddenChangeAmount) hiddenChangeAmount.value = changeAmount;
    
    // Set date
    const dateInput = document.querySelector('input[name="date"]');
    if (dateInput) {
        dateInput.value = date.split(' ')[0]; // Get date part only
    }
    
    // Show package description
    if (packageSelect && packageDescription) {
        const selectedOption = packageSelect.options[packageSelect.selectedIndex];
        if (selectedOption) {
            const description = selectedOption.getAttribute('data-description');
            packageDescription.textContent = description || '';
        }
    }
    
    // Set change color
    if (changeAmountInput) {
        if (parseFloat(changeAmount) >= 0) {
            changeAmountInput.style.color = '#4caf50';
        } else {
            changeAmountInput.style.color = '#f44336';
        }
    }
    
    // Change form submission to direct submit for editing (bypass POS modal)
    if (walkinForm) {
        walkinForm.onsubmit = function(e) {
            console.log('Edit form submission triggered');
            e.preventDefault();
            
            // Validate customer name format (only field that can be changed)
            const customerName = customerNameInput ? customerNameInput.value.trim() : '';
            
            // Basic validation - check if name has at least 2 words
            const nameWords = customerName.split(/\s+/).filter(word => word.length > 0);
            if (nameWords.length < 2) {
                alert('Please enter the customer\'s full name (first and last name).');
                return;
            }
            
            // Check if name contains only letters, spaces, and periods
            const validPattern = /^[A-Za-z\s\.]+$/;
            if (!validPattern.test(customerName)) {
                alert('Please enter the customer\'s full name with initials (e.g., Trixyle Anne H. Asuncion)');
                return;
            }
            
            console.log('Form validation passed, submitting...');
            // Submit form directly
            walkinForm.submit();
        };
    }
};

// Move calendar functions to global scope
window.openCalendar = function() {
    document.getElementById('calendarModal').style.display = 'block';
    generateCalendar();
};

window.closeCalendar = function() {
    document.getElementById('calendarModal').style.display = 'none';
};

window.changeMonth = function(direction) {
    const currentDate = new Date('<?= $filter_date ?>');
    currentDate.setMonth(currentDate.getMonth() + direction);
    // Format date in Philippine timezone
    const newDateStr = currentDate.toLocaleDateString('en-CA', {timeZone: 'Asia/Manila'});
    window.location.href = `?date=${newDateStr}`;
};

window.selectDate = function(dateStr) {
    window.location.href = `?date=${dateStr}`;
};

// Generate calendar function - moved to global scope
function generateCalendar() {
    const calendar = document.getElementById('calendar');
    const currentDate = new Date('<?= $filter_date ?>');
    // Use Philippine timezone for today's date
    const today = new Date(new Date().toLocaleString("en-US", {timeZone: "Asia/Manila"}));
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    
    const monthNames = ["January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"];
    
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const firstDay = new Date(year, month, 1).getDay();
    
    let html = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <button onclick="changeMonth(-1)" style="background: var(--accent-clr); color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer;">&lt;</button>
            <h3 style="margin: 0; color: var(--accent-clr);">${monthNames[month]} ${year}</h3>
            <button onclick="changeMonth(1)" style="background: var(--accent-clr); color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer;">&gt;</button>
        </div>
        <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; margin-bottom: 10px;">
            <div style="text-align: center; padding: 8px; color: var(--accent-clr); font-weight: bold;">Sun</div>
            <div style="text-align: center; padding: 8px; color: var(--accent-clr); font-weight: bold;">Mon</div>
            <div style="text-align: center; padding: 8px; color: var(--accent-clr); font-weight: bold;">Tue</div>
            <div style="text-align: center; padding: 8px; color: var(--accent-clr); font-weight: bold;">Wed</div>
            <div style="text-align: center; padding: 8px; color: var(--accent-clr); font-weight: bold;">Thu</div>
            <div style="text-align: center; padding: 8px; color: var(--accent-clr); font-weight: bold;">Fri</div>
            <div style="text-align: center; padding: 8px; color: var(--accent-clr); font-weight: bold;">Sat</div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px;">
    `;
    
    // Empty cells for days before the first day of the month
    for (let i = 0; i < firstDay; i++) {
        html += '<div style="padding: 8px;"></div>';
    }
    
    // Days of the month
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = dateStr === today.toISOString().split('T')[0];
        const isCurrentDate = dateStr === '<?= $filter_date ?>';
        const isFuture = new Date(dateStr) > today;
        
        let dayStyle = `
            padding: 8px; 
            text-align: center; 
            cursor: pointer; 
            border-radius: 6px; 
            transition: all 0.2s ease;
            color: ${isFuture ? '#666' : '#e0e0e0'};
            background: ${isCurrentDate ? 'var(--accent-clr)' : 'transparent'};
            border: ${isToday ? '2px solid #4caf50' : 'none'};
        `;
        
        if (isFuture) {
            dayStyle += 'cursor: not-allowed; opacity: 0.5;';
        }
        
        html += `<div style="${dayStyle}" ${!isFuture ? `onclick="selectDate('${dateStr}')"` : ''}>${day}</div>`;
    }
    
    html += '</div>';
    calendar.innerHTML = html;
}
</script>

<!-- Convert to Member Modal -->
<div id="convertModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 600px;">
    <div class="modal-header">
      <h2>Convert Walk-in to Member</h2>
      <span class="close" onclick="closeConvertModal()">&times;</span>
    </div>
    <form id="convertForm" method="POST" action="process_convert_form.php">
      <input type="hidden" name="walkin_id" id="convertWalkinId">
      <input type="hidden" name="action" value="convert">
      
      <div style="padding: 20px;">
        <div class="form-group">
          <label>Customer Name</label>
          <input type="text" id="convertCustomerName" readonly style="background: var(--hover-clr); color: #888;">
        </div>
        
        <div class="form-group">
          <label for="memberEmail">Email Address *</label>
          <input type="email" id="memberEmail" name="email" placeholder="Email" required style="width: 100%;" onblur="checkEmailAvailability()">
          <small id="emailStatus" style="font-size: 0.8rem; margin-top: 5px; display: none;"></small>
        </div>
        
          <div class="form-group">
            <label for="memberPassword">Password *</label>
            <div style="position: relative;">
              <input type="password" id="memberPassword" name="password" placeholder="Enter Password" required minlength="6" style="width: 100%; padding-right: 40px;" oninput="validatePassword()">
              <button type="button" id="togglePassword" onclick="togglePasswordVisibility()" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #888; cursor: pointer; font-size: 18px; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">
                <i class="bx bx-hide" id="passwordIcon"></i>
              </button>
            </div>
            <div id="password-notice" style="margin-top: 0.5rem; font-size: 0.875rem; color: #dc3545; display: none;">
              <i class='bx bx-info-circle' style="margin-right: 0.25rem;"></i>
              <span id="password-validation-message">Password must be at least 6 characters long, contain at least 1 uppercase letter, and at least 1 number</span>
            </div>
            <div style="margin-top: 0.5rem; font-size: 0.875rem; color: #d1d1d1;">
              password must be at least 6 characters long, has 1 capital letter and at least 1 number
            </div>
          </div>
        
        <div class="form-group">
          <label for="memberPhone">Phone Number *</label>
          <input type="tel" id="memberPhone" name="phone" placeholder="09123456789" required maxlength="11" style="width: 100%;" oninput="formatPhoneNumber()" onblur="checkPhoneAvailability()">
          <small id="phoneStatus" style="font-size: 0.8rem; margin-top: 5px; display: none;"></small>
        </div>
        
        <div class="form-group">
          <label for="memberAddress">Address</label>
          <input type="text" id="memberAddress" name="address" placeholder="Complete address">
        </div>
        
        <div class="form-group">
          <label for="membershipPlan">Membership Plan *</label>
          <select id="membershipPlan" name="membership_plan" required>
            <option value="">Select a membership plan</option>
            <!-- Package 1: Boxing -->
            <option value="Package 1: Boxing (1 Month)">Package 1: Boxing (1 Month) - ₱2,500</option>
            <option value="Package 1: Boxing (2 Months)">Package 1: Boxing (2 Months) - ₱4,000</option>
            <option value="Package 1: Boxing (3 Months)">Package 1: Boxing (3 Months) - ₱6,000</option>
            <!-- Package 2: Circuit Training -->
            <option value="Package 2: Circuit Training (1 Month)">Package 2: Circuit Training (1 Month) - ₱1,700</option>
            <option value="Package 2: Circuit Training (3 Months)">Package 2: Circuit Training (3 Months) - ₱2,900</option>
            <option value="Package 2: Circuit Training (6 Months)">Package 2: Circuit Training (6 Months) - ₱5,500</option>
            <option value="Package 2: Circuit Training (1 Year)">Package 2: Circuit Training (1 Year) - ₱9,000</option>
            <!-- Package 3: Muay Thai -->
            <option value="Package 3: Muay Thai (1 Month)">Package 3: Muay Thai (1 Month) - ₱3,000</option>
            <option value="Package 3: Muay Thai (2 Months)">Package 3: Muay Thai (2 Months) - ₱5,000</option>
            <option value="Package 3: Muay Thai (3 Months)">Package 3: Muay Thai (3 Months) - ₱7,000</option>
          </select>
        </div>
        
        
        
        
        <div class="modal-actions">
          <button type="button" class="cancel-btn" onclick="closeConvertModal()">Cancel</button>
          <button type="button" class="save-btn" onclick="proceedToOTPVerification()">Proceed to OTP Verification</button>
        </div>
      </div>
    </form>
  </div>
</div>


<!-- POS (Point of Sale) Modal -->
<div id="posModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 700px;">
    <div class="modal-header">
      <h2>Point of Sale - Transaction Summary</h2>
      <span class="close" onclick="closePosModal()">&times;</span>
    </div>
    
    <div style="padding: 20px;">
      <!-- Transaction Summary -->
      <div class="pos-summary" style="background: var(--hover-clr); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <h3 style="color: #e0e0e0; margin: 0 0 15px 0; border-bottom: 2px solid var(--accent-clr, #5e63ff); padding-bottom: 10px;">Transaction Details</h3>
        
        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 10px;">
          <span style="color: #888;">Customer Name:</span>
          <span style="color: #e0e0e0; font-weight: 600;" id="posCustomerName">-</span>
        </div>
        
        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 10px;">
          <span style="color: #888;">Membership Plan:</span>
          <span style="color: #e0e0e0; font-weight: 600;" id="posMembershipPlan">-</span>
        </div>
      </div>

      <!-- Package Amount Section -->
      <div class="pos-amount" style="background: var(--hover-clr); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <h3 style="color: #e0e0e0; margin: 0 0 15px 0; border-bottom: 2px solid var(--accent-clr, #5e63ff); padding-bottom: 10px;">Package Amount</h3>
        
        <div class="amount-display" style="padding: 15px; background: var(--line-clr); border-radius: 6px; text-align: center;">
          <div style="color: #888; font-size: 0.9rem; margin-bottom: 5px;">Selected Package Amount</div>
          <div id="posPackageAmount" style="color: var(--accent-clr, #5e63ff); font-size: 2rem; font-weight: 600;">₱0.00</div>
        </div>
      </div>

      <!-- Payment Method Selection -->
      <div class="pos-payment" style="background: var(--hover-clr); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <h3 style="color: #e0e0e0; margin: 0 0 15px 0; border-bottom: 2px solid var(--accent-clr, #5e63ff); padding-bottom: 10px;">Payment Method</h3>
        
        <div class="form-group">
          <label for="posPaymentMethod">Select Payment Method *</label>
          <select id="posPaymentMethod" name="payment_method" required style="width: 100%; padding: 10px 12px; border: 1px solid #333; border-radius: 6px; background: var(--line-clr); color: #e0e0e0;">
            <option value="">Select payment method</option>
            <option value="Cash">Cash</option>
            <option value="GCash">GCash</option>
          </select>
        </div>
      </div>

      <!-- Total Amount -->
      <div class="pos-totals" style="background: var(--hover-clr); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <h3 style="color: #e0e0e0; margin: 0 0 15px 0; border-bottom: 2px solid var(--accent-clr, #5e63ff); padding-bottom: 10px;">Total Amount</h3>
        
        <div class="total-row" style="display: flex; justify-content: space-between; padding: 15px; background: var(--line-clr); border-radius: 6px;">
          <span style="color: #e0e0e0; font-size: 1.2rem; font-weight: 600;">Amount to Pay:</span>
          <span style="color: var(--accent-clr, #5e63ff); font-size: 1.2rem; font-weight: 600;" id="posTotalAmount">₱0.00</span>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="modal-actions">
        <button type="button" class="cancel-btn" onclick="closePosModal()">Cancel</button>
        <button type="button" class="save-btn" onclick="proceedToPayment()" style="background: var(--accent-clr, #5e63ff);">Proceed</button>
      </div>
    </div>
  </div>
</div>

<script>

    // Auto-generate credentials when modal opens
    function convertWalkinToMember(id, name, package, amount) {
        document.getElementById('convertWalkinId').value = id;
        document.getElementById('convertCustomerName').value = name;
        
        // Show the conversion modal
        document.getElementById('convertModal').style.display = 'block';
    }

    // View converted details
    function viewConvertedDetails(id, name) {
        // Fetch conversion details via AJAX
        fetch('get_converted_details.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Populate the view modal with data
                    document.getElementById('viewCustomerName').textContent = data.details.full_name;
                    document.getElementById('viewEmail').textContent = data.details.email;
                    document.getElementById('viewPassword').textContent = data.details.password_plain;
                    document.getElementById('viewPhone').textContent = data.details.phone || 'Not provided';
                    document.getElementById('viewAddress').textContent = data.details.address || 'Not provided';
                    document.getElementById('viewMembershipPlan').textContent = data.details.plan_name;
                    document.getElementById('viewPaymentMethod').textContent = data.details.payment_method;
                    document.getElementById('viewAmount').textContent = '₱' + parseFloat(data.details.amount).toLocaleString('en-US', {minimumFractionDigits: 2});
                    document.getElementById('viewConversionDate').textContent = data.details.conversion_date;
                    
                    // Receipt or GCash evidence
                    const receiptGroup = document.getElementById('viewReceiptGroup');
                    const receiptBtn = document.getElementById('viewReceiptBtn');
                    const refSpan = document.getElementById('viewReference');
                    if (data.details.receipt_path) {
                        receiptGroup.style.display = 'block';
                        receiptBtn.style.display = 'inline-block';
                        refSpan.style.display = data.details.reference_number ? 'inline' : 'none';
                        refSpan.textContent = data.details.reference_number ? ('Reference: ' + data.details.reference_number) : '';
                        receiptBtn.onclick = function() { window.open('../' + data.details.receipt_path, '_blank'); };
                    } else if (data.details.reference_number) {
                        receiptGroup.style.display = 'block';
                        receiptBtn.style.display = 'none';
                        refSpan.style.display = 'inline';
                        refSpan.textContent = 'Reference: ' + data.details.reference_number;
                    } else {
                        receiptGroup.style.display = 'none';
                    }

                    // Show the view modal
                    document.getElementById('viewConvertedModal').style.display = 'block';
                } else {
                    alert('Error loading conversion details: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading conversion details');
            });
    }

// Close convert modal
function closeConvertModal() {
    document.getElementById('convertModal').style.display = 'none';
    document.getElementById('convertForm').reset();
}

// Close view converted modal
function closeViewConvertedModal() {
    document.getElementById('viewConvertedModal').style.display = 'none';
}

// POS Modal Functions
// Camera and Photo Capture Functions
let stream = null;
let photoCaptured = false;

function startCamera() {
    const video = document.getElementById('videoElement');
    const startBtn = document.getElementById('startCameraBtn');
    const captureBtn = document.getElementById('capturePhotoBtn');
    const stopBtn = document.getElementById('stopCameraBtn');
    const previewContainer = document.getElementById('photoPreviewContainer');
    
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
        .then(function(mediaStream) {
            stream = mediaStream;
            video.srcObject = stream;
            video.style.display = 'block';
            previewContainer.style.display = 'none';
            startBtn.style.display = 'none';
            captureBtn.style.display = 'inline-block';
            stopBtn.style.display = 'inline-block';
        })
        .catch(function(err) {
            console.error('Error accessing camera:', err);
            alert('Unable to access camera. Please ensure camera permissions are granted.');
        });
}

function capturePhoto() {
    const video = document.getElementById('videoElement');
    const canvas = document.getElementById('photoCanvas');
    const preview = document.getElementById('photoPreview');
    const previewContainer = document.getElementById('photoPreviewContainer');
    const placeholder = document.getElementById('photoPlaceholder');
    const captureBtn = document.getElementById('capturePhotoBtn');
    const retakeBtn = document.getElementById('retakePhotoBtn');
    const stopBtn = document.getElementById('stopCameraBtn');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    // Convert to base64
    const photoData = canvas.toDataURL('image/jpeg', 0.8);
    document.getElementById('customerPhotoData').value = photoData;
    
    // Show preview
    preview.src = photoData;
    preview.style.display = 'block';
    placeholder.style.display = 'none';
    video.style.display = 'none';
    previewContainer.style.display = 'block';
    
    // Update buttons
    captureBtn.style.display = 'none';
    retakeBtn.style.display = 'inline-block';
    stopBtn.style.display = 'none';
    
    photoCaptured = true;
    
    // Stop camera stream
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
}

function retakePhoto() {
    const preview = document.getElementById('photoPreview');
    const placeholder = document.getElementById('photoPlaceholder');
    const retakeBtn = document.getElementById('retakePhotoBtn');
    const startBtn = document.getElementById('startCameraBtn');
    
    preview.style.display = 'none';
    placeholder.style.display = 'block';
    retakeBtn.style.display = 'none';
    startBtn.style.display = 'inline-block';
    document.getElementById('customerPhotoData').value = '';
    photoCaptured = false;
}

function stopCamera() {
    const video = document.getElementById('videoElement');
    const startBtn = document.getElementById('startCameraBtn');
    const captureBtn = document.getElementById('capturePhotoBtn');
    const stopBtn = document.getElementById('stopCameraBtn');
    const previewContainer = document.getElementById('photoPreviewContainer');
    
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
    
    video.style.display = 'none';
    previewContainer.style.display = 'block';
    startBtn.style.display = 'inline-block';
    captureBtn.style.display = 'none';
    stopBtn.style.display = 'none';
}

function openPOSModal(event) {
    event.preventDefault();
    
    // Validate photo is captured
    const photoData = document.getElementById('customerPhotoData').value;
    if (!photoData || photoData.trim() === '') {
        alert('Please take a photo of the customer before proceeding.');
        return false;
    }
    
    // Get form data
    const form = document.getElementById('walkinForm');
    const formData = new FormData(form);
    
    // Validate required fields
    const name = formData.get('name');
    const package = formData.get('package');
    const amount = formData.get('amount');
    const paymentMethod = formData.get('payment_method');
    
    // Check if full name is entered
    if (!name || name.trim() === '') {
        alert('Please enter the customer\'s full name.');
        document.getElementById('customerName').focus();
        return false;
    }
    
    // Check if name has at least 2 words (first and last name)
    const nameWords = name.trim().split(/\s+/).filter(word => word.length > 0);
    if (nameWords.length < 2) {
        alert('Please enter the customer\'s full name (first and last name).');
        document.getElementById('customerName').focus();
        return false;
    }
    
    if (!package || !amount || !paymentMethod) {
        alert('Please fill in all required fields.');
        return false;
    }
    
    // Populate POS modal
    document.getElementById('posCustomerName').textContent = name;
    document.getElementById('posPackage').textContent = package;
    document.getElementById('posAmountToPay').textContent = '₱' + parseFloat(amount).toFixed(2);
    document.getElementById('posPaymentMethod').value = paymentMethod;
    
    // Show/hide sections based on payment method
    const cashPaymentSection = document.getElementById('cashPaymentSection');
    const receiptSection = document.getElementById('receiptSection');
    const receiptTitle = document.getElementById('receiptTitle');
    const uploadButtonText = document.getElementById('uploadButtonText');
    const uploadNote = document.getElementById('uploadNote');
    
    // Add event listener for payment method change
    document.getElementById('posPaymentMethod').addEventListener('change', function() {
        const selectedPaymentMethod = this.value.toLowerCase();
        const gcashRefSection = document.getElementById('gcashRefSection');
        
        if (selectedPaymentMethod === 'cash') {
            // Show cash payment section
            cashPaymentSection.style.display = 'block';
            gcashRefSection.style.display = 'none';
            receiptTitle.textContent = 'Upload Official Receipt (OR)';
            uploadButtonText.textContent = 'Choose OR Photo';
            uploadNote.textContent = '⚠️ OR photo required before recording walk-in customer';
            
            // Reset GCash reference validation
            const gcashRefInput = document.getElementById('gcashReferenceNumber');
            const gcashNotice = document.getElementById('gcash-reference-validation-notice');
            if (gcashRefInput) {
                gcashRefInput.value = '';
                gcashRefInput.style.borderColor = '#333';
            }
            if (gcashNotice) gcashNotice.style.display = 'none';
        } else if (selectedPaymentMethod === 'gcash') {
            // Hide cash payment section, show GCash reference section
            cashPaymentSection.style.display = 'none';
            gcashRefSection.style.display = 'block';
            receiptTitle.textContent = 'Upload GCash Screenshot';
            uploadButtonText.textContent = 'Choose GCash Screenshot';
            uploadNote.textContent = '⚠️ GCash screenshot and reference number required before recording walk-in customer';
        }
        
        // Validate form after payment method change
        validateGCashForm();
    });
    
    // Add event listener for GCash reference number input
    document.getElementById('gcashReferenceNumber').addEventListener('input', function() {
        // Remove any non-numeric characters
        this.value = this.value.replace(/[^0-9]/g, '');
        
        const reference = this.value.trim();
        const notice = document.getElementById('gcash-reference-validation-notice');
        const message = document.getElementById('gcash-reference-validation-message');
        
        // Reset validation notice on input
        if (notice && message) {
            notice.style.display = 'none';
            this.style.borderColor = '#333';
            this.classList.remove('valid');
        }
        
        // Check if contains non-numeric characters
        if (reference.length > 0 && !/^[0-9]+$/.test(reference)) {
            if (notice && message) {
                notice.style.display = 'block';
                notice.style.color = '#dc3545';
                message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>GCash reference number must contain only numbers.';
                this.style.borderColor = '#dc3545';
            }
        } else if (reference.length > 0 && reference.length !== 13) {
            if (notice && message) {
                notice.style.display = 'block';
                notice.style.color = '#dc3545';
                message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>GCash reference number must be exactly 13 characters long.';
                this.style.borderColor = '#dc3545';
            }
        } else if (reference.length === 13) {
            // Will be validated by checkGCashReferenceAvailability on blur
            this.style.borderColor = '#333';
        }
        
        validateGCashForm();
    });
    
    if (paymentMethod === 'cash') {
        // Show cash payment section
        cashPaymentSection.style.display = 'block';
        document.getElementById('gcashRefSection').style.display = 'none';
        receiptTitle.textContent = 'Upload Official Receipt (OR)';
        uploadButtonText.textContent = 'Choose OR Photo';
        uploadNote.textContent = '⚠️ OR photo required before recording walk-in customer';
    } else if (paymentMethod === 'gcash') {
        // Hide cash payment section, show GCash reference section
        cashPaymentSection.style.display = 'none';
        document.getElementById('gcashRefSection').style.display = 'block';
        receiptTitle.textContent = 'Upload GCash Screenshot';
        uploadButtonText.textContent = 'Choose GCash Screenshot';
        uploadNote.textContent = '⚠️ GCash screenshot and reference number required before recording walk-in customer';
    }
    
    // Validate form after initial setup
    validateGCashForm();
    
    // Reset form fields
    document.getElementById('posAmountGiven').value = '';
    document.getElementById('posExactAmount').checked = false;
    document.getElementById('posChange').value = '';
    document.getElementById('gcashReferenceNumber').value = '';
    document.getElementById('receiptFile').value = '';
    document.getElementById('fileStatus').style.display = 'none';
    document.getElementById('saveWalkinBtn').disabled = true;
    document.getElementById('saveWalkinBtn').style.opacity = '0.5';
    document.getElementById('saveWalkinBtn').style.cursor = 'not-allowed';
    
    // Close walk-in modal and open POS modal
    document.getElementById('walkinModal').style.display = 'none';
    document.getElementById('walkinPosModal').style.display = 'block';
    
    return false;
}

function closeWalkinPOSModal() {
    document.getElementById('walkinPosModal').style.display = 'none';
    // Reset file upload
    document.getElementById('receiptFile').value = '';
    document.getElementById('fileStatus').style.display = 'none';
    document.getElementById('saveWalkinBtn').disabled = true;
    document.getElementById('saveWalkinBtn').style.opacity = '0.5';
    document.getElementById('saveWalkinBtn').style.cursor = 'not-allowed';
}

// Calculate change for cash payments
function calculateChange() {
    const amountToPay = parseFloat(document.getElementById('posAmountToPay').textContent.replace('₱', '').replace(',', ''));
    const amountGiven = parseFloat(document.getElementById('posAmountGiven').value) || 0;
    const change = amountGiven - amountToPay;
    
    document.getElementById('posChange').value = change >= 0 ? '₱' + change.toFixed(2) : '₱0.00';
}

// Handle exact amount checkbox
function handleExactAmount() {
    const exactAmountCheckbox = document.getElementById('posExactAmount');
    const amountGivenInput = document.getElementById('posAmountGiven');
    const amountToPay = parseFloat(document.getElementById('posAmountToPay').textContent.replace('₱', '').replace(',', ''));
    
    if (exactAmountCheckbox.checked) {
        amountGivenInput.value = amountToPay.toFixed(2);
        document.getElementById('posChange').value = '₱0.00';
    } else {
        amountGivenInput.value = '';
        document.getElementById('posChange').value = '';
    }
}

function handleReceiptUpload() {
    const fileInput = document.getElementById('receiptFile');
    const fileStatus = document.getElementById('fileStatus');
    
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        const fileName = file.name;
        
        fileStatus.style.display = 'flex';
        document.getElementById('fileName').textContent = fileName;
    } else {
        fileStatus.style.display = 'none';
    }
    
    // Check validation after file upload
    validateGCashForm();
}

// Function to check GCash reference availability
function checkGCashReferenceAvailability() {
    const referenceInput = document.getElementById('gcashReferenceNumber');
    const reference = referenceInput.value.trim();
    const notice = document.getElementById('gcash-reference-validation-notice');
    const message = document.getElementById('gcash-reference-validation-message');
    
    if (!notice || !message) return;
    
    // Don't check if reference is empty
    if (!reference) {
        notice.style.display = 'none';
        referenceInput.style.borderColor = '#333';
        validateGCashForm();
        return;
    }
    
    // Check if contains only numbers
    if (!/^[0-9]+$/.test(reference)) {
        notice.style.display = 'block';
        notice.style.color = '#dc3545';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>GCash reference number must contain only numbers.';
        referenceInput.style.borderColor = '#dc3545';
        referenceInput.classList.remove('valid');
        validateGCashForm();
        return;
    }
    
    // Check length first
    if (reference.length !== 13) {
        notice.style.display = 'block';
        notice.style.color = '#dc3545';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>GCash reference number must be exactly 13 characters long.';
        referenceInput.style.borderColor = '#dc3545';
        referenceInput.classList.remove('valid');
        validateGCashForm();
        return;
    }
    
    // Show loading state
    notice.style.display = 'block';
    notice.style.color = '#ffc107';
    message.innerHTML = '<i class="bx bx-loader-alt bx-spin" style="margin-right: 0.25rem;"></i>Checking reference availability...';
    referenceInput.style.borderColor = '#333';
    
    // Make AJAX request to check reference availability
    const formData = new FormData();
    formData.append('gcash_reference', reference);
    
    fetch('../check_gcash_reference_availability.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        return response.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text);
                throw new Error('Invalid JSON response from server');
            }
        });
    })
    .then(data => {
        if (data.available) {
            // Hide notice and just mark input as valid (green border)
            notice.style.display = 'none';
            referenceInput.style.borderColor = '#28a745';
            referenceInput.classList.add('valid');
        } else {
            notice.style.display = 'block';
            notice.style.color = '#dc3545';
            message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>' + data.message;
            referenceInput.style.borderColor = '#dc3545';
            referenceInput.classList.remove('valid');
        }
        validateGCashForm();
    })
    .catch(error => {
        notice.style.color = '#dc3545';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Error checking reference availability. Please try again.';
        referenceInput.style.borderColor = '#dc3545';
        console.error('GCash reference check error:', error);
        validateGCashForm();
    });
}

function validateGCashForm() {
    const paymentMethod = document.getElementById('posPaymentMethod').value.toLowerCase();
    const saveBtn = document.getElementById('saveWalkinBtn');
    const fileInput = document.getElementById('receiptFile');
    const gcashRefInput = document.getElementById('gcashReferenceNumber');
    const gcashNotice = document.getElementById('gcash-reference-validation-notice');
    
    let isValid = false;
    
    if (paymentMethod === 'cash') {
        // Receipt optional for cash
        isValid = true;
    } else if (paymentMethod === 'gcash') {
        // Require valid reference number (13 chars and not duplicate)
        const reference = gcashRefInput.value.trim();
        const hasValidReference = reference.length === 13 && 
                                  gcashRefInput.classList.contains('valid') &&
                                  (!gcashNotice || 
                                   gcashNotice.style.display === 'none' ||
                                   gcashNotice.style.color === 'rgb(40, 167, 69)' || 
                                   gcashNotice.style.color === '#28a745');
        
        // Check if receipt file is uploaded
        const hasReceiptFile = fileInput && fileInput.files && fileInput.files.length > 0;
        
        // Both reference and receipt are required for GCash
        isValid = hasValidReference && hasReceiptFile;
    }
    
    if (isValid) {
        saveBtn.disabled = false;
        saveBtn.style.opacity = '1';
        saveBtn.style.cursor = 'pointer';
    } else {
        saveBtn.disabled = true;
        saveBtn.style.opacity = '0.5';
        saveBtn.style.cursor = 'not-allowed';
    }
}

function removeReceiptUpload() {
    document.getElementById('receiptFile').value = '';
    document.getElementById('fileStatus').style.display = 'none';
    
    // Validate form after removing file
    validateGCashForm();
}

function saveWalkinWithReceipt() {
    const fileInput = document.getElementById('receiptFile');
    const form = document.getElementById('walkinForm');
    const paymentMethodSelect = document.getElementById('posPaymentMethod');
    const paymentMethod = paymentMethodSelect.value.toLowerCase();
    
    // Validate payment method selection
    if (!paymentMethod) {
        alert('Please select a payment method.');
        return;
    }
    
    // Validate cash payment fields if payment method is cash
    if (paymentMethod === 'cash') {
        const amountGiven = document.getElementById('posAmountGiven').value;
        if (!amountGiven || parseFloat(amountGiven) <= 0) {
            alert('Please enter the amount given for cash payment.');
            return;
        }
    }
    
    // Validate GCash reference number if payment method is GCash
    if (paymentMethod === 'gcash') {
        const gcashRef = document.getElementById('gcashReferenceNumber').value.trim();
        const gcashRefInput = document.getElementById('gcashReferenceNumber');
        const gcashNotice = document.getElementById('gcash-reference-validation-notice');
        
        if (!gcashRef) {
            alert('Please enter the GCash reference number.');
            return;
        }
        
        // Validate reference length
        if (gcashRef.length !== 13) {
            alert('GCash reference number must be exactly 13 characters long.');
            return;
        }
        
        // Check if reference has validation errors (duplicate check)
        if (gcashNotice && gcashNotice.style.display !== 'none') {
            const noticeColor = gcashNotice.style.color;
            if (noticeColor === 'rgb(220, 53, 69)' || noticeColor === '#dc3545') {
                alert('Please fix the GCash reference number validation errors before proceeding.');
                return;
            }
        }
    }
    
    // Create FormData with all form data plus optional receipt file
    const formData = new FormData(form);
    
    // Ensure customer photo data is included (it should be in the form, but explicitly set it to be sure)
    const photoData = document.getElementById('customerPhotoData').value;
    console.log('Photo data length:', photoData ? photoData.length : 0);
    if (photoData && photoData.trim() !== '') {
        // Use set() to ensure it's included (will overwrite if already exists from form)
        formData.set('customer_photo_data', photoData);
        console.log('Photo data set in FormData');
    } else {
        console.error('No photo data found!');
        alert('Error: Photo data is missing. Please take a photo again.');
        return;
    }
    
    if (fileInput.files && fileInput.files[0]) {
        formData.append('receipt_file', fileInput.files[0]);
    }
    formData.append('action', 'save_walkin_with_receipt');
    // Ensure payment method from POS modal is sent
    formData.set('payment_method', paymentMethod);
    // Ensure amount and date are set from POS modal and current date
    const amountToPay = document.getElementById('posAmountToPay').textContent.replace('₱', '').replace(',', '');
    formData.set('amount', amountToPay);
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    formData.set('date', `${yyyy}-${mm}-${dd}`);
    
    // Add POS modal data
    if (paymentMethod === 'cash') {
        formData.append('amount_given', document.getElementById('posAmountGiven').value);
        const change = document.getElementById('posChange').value.replace('₱', '');
        formData.append('change_amount', change);
    } else {
        // For GCash, amount given equals amount to pay
        const amountToPay = document.getElementById('posAmountToPay').textContent.replace('₱', '').replace(',', '');
        formData.append('amount_given', amountToPay);
        formData.append('change_amount', '0.00');
        // Add GCash reference number
        formData.append('gcash_reference_number', document.getElementById('gcashReferenceNumber').value);
    }
    
    // Debug: Log form data
    console.log('Submitting walk-in data:');
    for (let [key, value] of formData.entries()) {
        console.log(key, value);
    }
    
    // Submit via AJAX
    fetch('../api/save_walkin.php', {
        method: 'POST',
        body: formData
    })
    .then(async (r) => {
        const status = r.status;
        const text = await r.text();
        try {
            const json = JSON.parse(text);
            if (json.success) {
                if (!json.receipt_path) {
                    console.warn('Saved without receipt_path.');
                }
                closeWalkinPOSModal();
                window.location.reload();
                return;
            }
            alert('Failed to save walk-in (' + status + '): ' + (json.error || 'Unknown error'));
        } catch (e) {
            alert('Failed to save walk-in (' + status + '). Server said:\n' + text.slice(0, 800));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving walk-in customer. Please try again.');
    });
}

// Receipt Viewer Function - Displays receipt image and GCash reference number
window.openReceiptViewer = function(receiptPath, isGCashPayment, gcashReferenceNumber) {
    // Validate receipt path
    if (!receiptPath) {
        alert('Receipt path is missing.');
        return;
    }
    
    // Get modal elements
    const modal = document.getElementById('receiptViewModal');
    const receiptImg = document.getElementById('receiptImage');
    const refDisplay = document.getElementById('receiptGCashRef');
    const refLabel = document.getElementById('receiptGCashRefLabel');
    
    if (!modal || !receiptImg) {
        alert('Receipt viewer elements not found.');
        return;
    }
    
    // Set receipt image
    receiptImg.src = '../' + receiptPath;
    receiptImg.alt = 'Receipt Screenshot';
    
    // Handle GCash reference number display below the photo
    if (refDisplay && refLabel) {
        // Convert isGCashPayment to boolean (handles string 'true'/'false' from PHP)
        const isGCash = isGCashPayment === true || isGCashPayment === 'true' || String(isGCashPayment).toLowerCase() === 'true';
        
        // Check if we have a valid reference number
        let refValue = '';
        if (gcashReferenceNumber) {
            refValue = String(gcashReferenceNumber).trim();
        }
        
        const hasValidReference = isGCash && refValue !== '' && refValue !== 'undefined' && refValue !== 'null';
        
        if (hasValidReference) {
            // Display GCash reference number below the photo
            refDisplay.textContent = refValue;
            refDisplay.style.display = 'block';
            refLabel.style.display = 'block';
        } else {
            // Hide reference number section for non-GCash payments or missing reference
            refDisplay.style.display = 'none';
            refLabel.style.display = 'none';
            refDisplay.textContent = ''; // Clear any previous value
        }
    }
    
    // Show modal
    modal.style.display = 'block';
};

// Close Receipt Modal
window.closeReceiptModal = function() {
    const modal = document.getElementById('receiptViewModal');
    if (modal) {
        modal.style.display = 'none';
    }
};

// Get package description based on package name
function getPackageDescription(packageName) {
    if (!packageName) return '';
    
    const packageLower = packageName.toLowerCase();
    
    if (packageLower.includes('package 1')) {
        return 'Circuit Training, Weight Loss, Strength and Conditioning, Athletic training, Weights Training';
    } else if (packageLower.includes('package 2')) {
        return 'Muay Thai, Boxing, Circuit Training, Weight Loss, Strength and Conditioning, Athletic training, Weights Training';
    } else if (packageLower.includes('package 3')) {
        return 'Boxing';
    }
    
    return '';
}

window.showWalkinRef = function(walkinRef, customerName, packageName) {
    const modal = document.getElementById('walkinRefModal');
    const refDisplay = document.getElementById('walkinRefDisplay');
    const nameDisplay = document.getElementById('walkinRefCustomerName');
    const packageDisplay = document.getElementById('walkinRefPackageInfo');
    const packageNameDisplay = document.getElementById('walkinRefPackageName');
    const copySuccess = document.getElementById('copyWalkinRefSuccess');
    const copyBtn = document.getElementById('copyWalkinRefBtn');
    const copyText = document.getElementById('copyWalkinRefText');
    
    if (!modal || !refDisplay) {
        alert('Walk-in reference modal elements not found.');
        return;
    }
    
    // Set customer name
    if (nameDisplay) {
        nameDisplay.textContent = customerName || 'Customer';
    }
    
    // Set walk-in reference number
    refDisplay.textContent = walkinRef;
    
    // Set package information
    if (packageNameDisplay && packageDisplay) {
        if (packageName) {
            packageNameDisplay.textContent = packageName;
            const description = getPackageDescription(packageName);
            if (description) {
                packageDisplay.textContent = description;
                packageDisplay.parentElement.style.display = 'block';
            } else {
                packageDisplay.parentElement.style.display = 'none';
            }
        } else {
            packageDisplay.parentElement.style.display = 'none';
        }
    }
    
    // Reset copy button state
    if (copySuccess) {
        copySuccess.style.display = 'none';
    }
    if (copyText) {
        copyText.textContent = 'Copy';
    }
    if (copyBtn) {
        copyBtn.setAttribute('data-ref', walkinRef);
    }
    
    // Show modal
    modal.style.display = 'block';
};

// Copy Walk-in Reference to Clipboard
window.copyWalkinRef = function() {
    const copyBtn = document.getElementById('copyWalkinRefBtn');
    const copySuccess = document.getElementById('copyWalkinRefSuccess');
    const copyText = document.getElementById('copyWalkinRefText');
    const refDisplay = document.getElementById('walkinRefDisplay');
    
    if (!copyBtn || !refDisplay) return;
    
    const walkinRef = copyBtn.getAttribute('data-ref') || refDisplay.textContent.trim();
    
    // Copy to clipboard
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(walkinRef).then(function() {
            // Show success message
            if (copySuccess) {
                copySuccess.style.display = 'flex';
                setTimeout(function() {
                    copySuccess.style.display = 'none';
                }, 2000);
            }
            if (copyText) {
                copyText.textContent = 'Copied!';
                setTimeout(function() {
                    copyText.textContent = 'Copy';
                }, 2000);
            }
        }).catch(function(err) {
            console.error('Failed to copy:', err);
            alert('Failed to copy to clipboard');
        });
    } else {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = walkinRef;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            if (copySuccess) {
                copySuccess.style.display = 'flex';
                setTimeout(function() {
                    copySuccess.style.display = 'none';
                }, 2000);
            }
            if (copyText) {
                copyText.textContent = 'Copied!';
                setTimeout(function() {
                    copyText.textContent = 'Copy';
                }, 2000);
            }
        } catch (err) {
            console.error('Fallback copy failed:', err);
            alert('Failed to copy to clipboard');
        }
        document.body.removeChild(textArea);
    }
};

// Close Walk-in Reference Modal
window.closeWalkinRefModal = function() {
    const modal = document.getElementById('walkinRefModal');
    if (modal) {
        modal.style.display = 'none';
    }
};

// View Photo Modal
window.viewPhotoModal = function(photoPath) {
    const modal = document.getElementById('photoModal');
    const photoImg = document.getElementById('photoModalImage');
    if (modal && photoImg) {
        photoImg.src = photoPath;
        modal.style.display = 'block';
    }
};

// Close Photo Modal
window.closePhotoModal = function() {
    const modal = document.getElementById('photoModal');
    if (modal) {
        modal.style.display = 'none';
    }
};
</script>

<!-- View Converted Details Modal -->
<div id="viewConvertedModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Conversion Details</h2>
      <span class="close" onclick="closeViewConvertedModal()">&times;</span>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Customer Name</label>
        <div id="viewCustomerName" class="view-field"></div>
      </div>
      
      <div class="form-group">
        <label>Email Address</label>
        <div id="viewEmail" class="view-field"></div>
      </div>
      
      <div class="form-group">
        <label>Password</label>
        <div id="viewPassword" class="view-field"></div>
      </div>
      
      <div class="form-group">
        <label>Phone Number</label>
        <div id="viewPhone" class="view-field"></div>
      </div>
      
      <div class="form-group">
        <label>Address</label>
        <div id="viewAddress" class="view-field"></div>
      </div>
      
      <div class="form-group">
        <label>Membership Plan</label>
        <div id="viewMembershipPlan" class="view-field"></div>
      </div>
      
      <div class="form-group">
        <label>Payment Method</label>
        <div id="viewPaymentMethod" class="view-field"></div>
      </div>
      
      <div class="form-group">
        <label>Amount Paid</label>
        <div id="viewAmount" class="view-field"></div>
      </div>
      <div class="form-group" id="viewReceiptGroup" style="display: none;">
        <label>Receipt / GCash</label>
        <div>
          <button type="button" id="viewReceiptBtn" class="btn btn-secondary" style="margin-right:8px; display:none;">View Receipt</button>
          <span id="viewReference" style="font-size:0.9rem; color:#888; display:none;"></span>
        </div>
      </div>
      
      <div class="form-group">
        <label>Conversion Date</label>
        <div id="viewConversionDate" class="view-field"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeViewConvertedModal()" class="btn btn-secondary">Close</button>
    </div>
  </div>
</div>

<script>
// Toggle Time In / Pending Status functionality
window.toggleTimeInStatus = function(id, newStatus) {
    console.log('toggleTimeInStatus called with:', id, newStatus);
    
    // Determine the action message based on the new status
    let actionMessage = '';
    if (newStatus === 'Timed in') {
        actionMessage = 'mark this walk-in customer as "Timed in"?';
    } else if (newStatus === 'Pending') {
        actionMessage = 'change this walk-in customer back to "Pending" status?';
    } else {
        actionMessage = `change this walk-in customer status to "${newStatus}"?`;
    }
    
    if (confirm(`Are you sure you want to ${actionMessage}`)) {
        // Redirect to update the status
        window.location.href = `?update_status=${id}&status=${encodeURIComponent(newStatus)}`;
    }
};
</script>

<!-- Receipt Viewer Modal -->
<div id="receiptViewModal" class="modal">
  <div class="modal-content" style="max-width: 700px;">
    <div class="modal-header">
      <h2>Receipt & Payment Details</h2>
      <span class="close" onclick="closeReceiptModal()">&times;</span>
    </div>
    <div class="modal-body">
      <!-- Receipt Image -->
      <div class="form-group" style="text-align: center; margin-bottom: 25px;">
        <img id="receiptImage" src="" alt="Receipt Screenshot" style="max-width: 100%; height: auto; border-radius: 8px; border: 1px solid var(--line-clr); box-shadow: 0 2px 8px rgba(0,0,0,0.3);">
      </div>
      
      <!-- GCash Reference Number Section - Displayed below the photo -->
      <div class="form-group" id="receiptGCashRefLabel" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--line-clr);">
        <label style="font-weight: 600; color: var(--accent-clr); margin-bottom: 10px; display: block; font-size: 0.95rem;">GCash Reference Number</label>
        <div class="view-field" id="receiptGCashRef" style="background: linear-gradient(135deg, rgba(94, 99, 255, 0.15) 0%, rgba(74, 82, 232, 0.15) 100%); border: 2px solid #5e63ff; color: #5e63ff; font-weight: 700; font-size: 1.2rem; text-align: center; padding: 12px; letter-spacing: 1px; font-family: 'Courier New', monospace; border-radius: 6px;">
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Photo Modal -->
<div id="photoModal" class="modal" onclick="if(event.target === this) closePhotoModal();" style="z-index: 10000;">
  <div class="modal-content" style="max-width: 90%; max-height: 90vh; background: transparent; border: none; padding: 0;">
    <span class="close" onclick="closePhotoModal()" style="position: absolute; top: 10px; right: 10px; color: white; font-size: 40px; font-weight: bold; z-index: 10001; text-shadow: 2px 2px 4px rgba(0,0,0,0.8);">&times;</span>
    <img id="photoModalImage" src="" alt="Customer Photo" style="max-width: 100%; max-height: 90vh; border-radius: 8px; display: block; margin: 0 auto;">
  </div>
</div>

<!-- Walk-in Reference Modal -->
<div id="walkinRefModal" class="modal">
  <div class="modal-content walkin-ref-modal-content">
    <div class="modal-header walkin-ref-modal-header">
      <h2 style="margin: 0; color: white; font-size: 1.4rem; font-weight: 600;">Walk-in Reference</h2>
      <span class="close" onclick="closeWalkinRefModal()" style="color: white; opacity: 0.9;">&times;</span>
    </div>
    <div class="modal-body walkin-ref-modal-body">
      <!-- Customer Info Card -->
      <div class="walkin-ref-customer-card">
        <label style="font-weight: 600; color: #b0b0b0; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 8px 0; display: block;">Customer Name</label>
        <div id="walkinRefCustomerName" class="walkin-ref-customer-name"></div>
      </div>
      
      <!-- Reference Number Card -->
      <div class="walkin-ref-number-card">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
          <label style="font-weight: 600; color: #b0b0b0; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">Reference Number</label>
          <button id="copyWalkinRefBtn" onclick="copyWalkinRef()" class="walkin-ref-copy-btn" title="Copy to clipboard">
            <span id="copyWalkinRefText">Copy</span>
          </button>
        </div>
        <div id="walkinRefDisplay" class="walkin-ref-display"></div>
        <div id="copyWalkinRefSuccess" class="walkin-ref-copy-success" style="display: none;">
          Copied to clipboard!
        </div>
      </div>
      
      <!-- Package Information Card -->
      <div class="walkin-ref-package-card" style="background: rgba(94, 99, 255, 0.1); border: 1px solid rgba(94, 99, 255, 0.2); border-radius: 8px; padding: 16px; margin-bottom: 16px;">
        <label style="font-weight: 600; color: #b0b0b0; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 8px 0; display: block;">Package Availed</label>
        <div id="walkinRefPackageName" style="font-size: 1rem; font-weight: 600; color: #5e63ff; margin-bottom: 8px;"></div>
        <div id="walkinRefPackageInfo" style="font-size: 0.9rem; color: #e0e0e0; line-height: 1.6;"></div>
      </div>
    </div>
  </div>
</div>

<script>
// Close modal when clicking outside
window.onclick = function(event) {
    const receiptModal = document.getElementById('receiptViewModal');
    if (event.target == receiptModal) {
        closeReceiptModal();
    }
    
    const walkinRefModal = document.getElementById('walkinRefModal');
    if (event.target == walkinRefModal) {
        closeWalkinRefModal();
    }
}
</script>

</body>
</html>
