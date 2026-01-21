<?php
include '../config.php';

include '../includes/QRCodeGenerator.php';
include '../includes/activity_logger.php';

// Set Philippine timezone
date_default_timezone_set('Asia/Manila');

include 'navbar.php';

$error = '';
$success = '';

// Handle membership approval/rejection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $pending_id = intval($_POST['pending_id']);
    $staff_id = 4; // Admin user ID
    
    if ($action === 'approve') {
        // Get pending membership details
        $get_pending = mysqli_prepare($conn, "SELECT * FROM pending_memberships WHERE id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($get_pending, "i", $pending_id);
        mysqli_stmt_execute($get_pending);
        $pending_result = mysqli_stmt_get_result($get_pending);
        $pending_data = mysqli_fetch_assoc($pending_result);
        mysqli_stmt_close($get_pending);
        
        // For cash payments, check if OR photo is uploaded
        if ($pending_data['payment_method'] === 'cash') {
            if (empty($_FILES['or_photo']['name'])) {
                $error = "Official Receipt (OR) photo is required for cash payments.";
            } else {
                // Handle OR photo upload
                $upload_dir = '../uploads/or_photos/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['or_photo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (!in_array($file_extension, $allowed_extensions)) {
                    $error = "Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed.";
                } else {
                    $file_name = 'or_' . $pending_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['or_photo']['tmp_name'], $file_path)) {
                        // Ensure or_photo_path column exists in pending_memberships table
                        $column_check = mysqli_query($conn, "SHOW COLUMNS FROM pending_memberships LIKE 'or_photo_path'");
                        if (mysqli_num_rows($column_check) == 0) {
                            mysqli_query($conn, "ALTER TABLE pending_memberships ADD COLUMN or_photo_path VARCHAR(255) DEFAULT NULL AFTER gcash_screenshot_path");
                        }
                        
                        // Update pending membership with OR photo path
                        $update_or = mysqli_prepare($conn, "UPDATE pending_memberships SET or_photo_path = ? WHERE id = ?");
                        if ($update_or) {
                            $relative_path = 'uploads/or_photos/' . $file_name;
                            mysqli_stmt_bind_param($update_or, "si", $relative_path, $pending_id);
                            mysqli_stmt_execute($update_or);
                            mysqli_stmt_close($update_or);
                            // Store the path for later use
                            $pending_data['or_photo_path'] = $relative_path;
                        }
                    } else {
                        $error = "Failed to upload OR photo. Please try again.";
                    }
                }
            }
        }
        
        if ($pending_data) {
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Get or create member record
                $member_query = "SELECT id FROM members WHERE user_id = ?";
                $stmt = mysqli_prepare($conn, $member_query);
                mysqli_stmt_bind_param($stmt, "i", $pending_data['user_id']);
                mysqli_stmt_execute($stmt);
                $member_result = mysqli_stmt_get_result($stmt);
                $member_data = mysqli_fetch_assoc($member_result);
                mysqli_stmt_close($stmt);
                
                if (!$member_data) {
                    // Create member record
                    // Use PHP's date function with Philippine timezone instead of MySQL CURDATE()
                    date_default_timezone_set('Asia/Manila');
                    $joined_at = date('Y-m-d H:i:s');
                    $create_member = mysqli_prepare($conn, "INSERT INTO members (user_id, status, joined_at) VALUES (?, 'active', ?)");
                    mysqli_stmt_bind_param($create_member, "is", $pending_data['user_id'], $joined_at);
                    mysqli_stmt_execute($create_member);
                    $member_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($create_member);
                } else {
                    $member_id = $member_data['id'];
                }
                
                // Calculate expiry date based on plan duration
                $duration_months = 1; // Default
                if (preg_match('/(\d+)\s+Month/i', $pending_data['plan_name'], $matches)) {
                    $duration_months = intval($matches[1]);
                } elseif (preg_match('/(\d+)\s+Year/i', $pending_data['plan_name'], $matches)) {
                    $duration_months = intval($matches[1]) * 12;
                }
                
                $current_date = date('Y-m-d');
                $expiry_date = date('Y-m-d', strtotime("+{$duration_months} months"));
                
                // Create subscription
                $subscription_query = "INSERT INTO subscriptions (member_id, plan_name, status, start_date, expiry_date) VALUES (?, ?, 'active', ?, ?)";
                $stmt = mysqli_prepare($conn, $subscription_query);
                mysqli_stmt_bind_param($stmt, "isss", $member_id, $pending_data['plan_name'], $current_date, $expiry_date);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                
                // Get user details for transaction record
                $user_query = "SELECT full_name FROM users WHERE id = ?";
                $stmt = mysqli_prepare($conn, $user_query);
                mysqli_stmt_bind_param($stmt, "i", $pending_data['user_id']);
                mysqli_stmt_execute($stmt);
                $user_result = mysqli_stmt_get_result($stmt);
                $user_data = mysqli_fetch_assoc($user_result);
                mysqli_stmt_close($stmt);
                
                // Ensure or_photo_path column exists in transactions table
                $trans_column_check = mysqli_query($conn, "SHOW COLUMNS FROM transactions LIKE 'or_photo_path'");
                if (mysqli_num_rows($trans_column_check) == 0) {
                    mysqli_query($conn, "ALTER TABLE transactions ADD COLUMN or_photo_path VARCHAR(255) DEFAULT NULL AFTER gcash_reference_number");
                }
                
                // Create transaction record
                $current_datetime = date('Y-m-d H:i:s');
                
                // Get or_photo_path from pending_data if it exists (for cash payments that were updated)
                $or_photo_path = null;
                if ($pending_data['payment_method'] === 'cash') {
                    // Check if or_photo_path was set during upload, otherwise try to get it from database
                    if (isset($pending_data['or_photo_path']) && !empty($pending_data['or_photo_path'])) {
                        $or_photo_path = $pending_data['or_photo_path'];
                    } else {
                        // Try to get or_photo_path from the updated pending record
                        $get_updated = mysqli_query($conn, "SELECT or_photo_path FROM pending_memberships WHERE id = $pending_id");
                        if ($get_updated && $row = mysqli_fetch_assoc($get_updated)) {
                            $or_photo_path = $row['or_photo_path'] ?? null;
                        }
                    }
                }
                
                $transaction_query = "INSERT INTO transactions (user_id, type, amount, description, status, payment_method, plan_name, customer_name, date, gcash_reference_number, or_photo_path) VALUES (?, 'membership', ?, ?, 'completed', ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $transaction_query);
                $description = "Membership purchase: " . $pending_data['plan_name'] . " - " . $pending_data['payment_method'] . " payment (Approved by staff)";
                $customer_name = $user_data['full_name'];
                $gcash_ref = ($pending_data['payment_method'] === 'gcash') ? ($pending_data['gcash_reference_number'] ?? null) : null;
                mysqli_stmt_bind_param($stmt, "idsssssss", $pending_data['user_id'], $pending_data['plan_price'], $description, $pending_data['payment_method'], $pending_data['plan_name'], $customer_name, $current_datetime, $gcash_ref, $or_photo_path);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                
                // Update pending membership status
                $update_pending = mysqli_prepare($conn, "UPDATE pending_memberships SET status = 'approved', processed_at = ?, processed_by = ? WHERE id = ?");
                mysqli_stmt_bind_param($update_pending, "sii", $current_datetime, $staff_id, $pending_id);
                mysqli_stmt_execute($update_pending);
                mysqli_stmt_close($update_pending);
                
                // Update QR code status
                $qrGenerator = new QRCodeGenerator($conn);
                $qrGenerator->updateQRStatusBasedOnMembership($pending_data['user_id']);
                
                mysqli_commit($conn);
                
                // Log activity: Processed Membership
                $customer_name = $user_data['full_name'] ?? 'Unknown';
                $transaction_id = mysqli_insert_id($conn);
                logActivity($conn, 'processed_membership', "Processed Membership: {$customer_name} - {$pending_data['plan_name']} approved and activated (₱{$pending_data['plan_price']} via {$pending_data['payment_method']})", $staff_id, $transaction_id, 'transaction', ['member_name' => $customer_name, 'plan' => $pending_data['plan_name'], 'amount' => $pending_data['plan_price'], 'method' => $pending_data['payment_method']]);
                
                $success = "Membership approved and activated successfully!";
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = "Failed to approve membership: " . $e->getMessage();
            }
        } else {
            $error = "Pending membership not found.";
        }
        
    } elseif ($action === 'reject') {
        $notes = trim($_POST['notes'] ?? '');
        
        // Update pending membership status to rejected
        $update_pending = mysqli_prepare($conn, "UPDATE pending_memberships SET status = 'rejected', processed_at = NOW(), processed_by = ?, notes = ? WHERE id = ?");
        mysqli_stmt_bind_param($update_pending, "isi", $staff_id, $notes, $pending_id);
        mysqli_stmt_execute($update_pending);
        mysqli_stmt_close($update_pending);
        
        $success = "Membership request rejected.";
    }
}

// Get pending memberships
$pending_query = "
    SELECT 
        pm.*,
        u.full_name,
        u.email
    FROM pending_memberships pm
    JOIN users u ON pm.user_id = u.id
    WHERE pm.status = 'pending'
    ORDER BY pm.requested_at ASC
";
$pending_result = mysqli_query($conn, $pending_query);

// Get processed memberships (for history)
$processed_query = "
    SELECT 
        pm.*,
        u.full_name,
        u.email,
        staff.full_name as processed_by_name
    FROM pending_memberships pm
    JOIN users u ON pm.user_id = u.id
    LEFT JOIN users staff ON pm.processed_by = staff.id
    WHERE pm.status IN ('approved', 'rejected', 'cancelled')
    ORDER BY COALESCE(pm.processed_at, pm.requested_at) DESC
    LIMIT 50
";
$processed_result = mysqli_query($conn, $processed_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Transactions - Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .membership-transactions-container {
            width: 1000px;
            max-width: 1000px;
            margin: 0 auto;
            padding: 1.5rem;
            padding-bottom: 0;
            box-sizing: border-box;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--accent-clr);
            margin: 0;
        }

        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--line-clr);
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--secondary-text-clr);
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .tab.active {
            color: var(--accent-clr);
            border-bottom-color: var(--accent-clr);
        }

        .tab-content {
            display: none;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
        }

        .tab-content.active {
            display: block;
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .tabs-container {
            position: relative;
            min-height: auto;
            overflow: visible;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .membership-card {
            background: var(--base-clr);
            border-radius: 6px;
            padding: 0.625rem;
            margin-bottom: 0.5rem;
            border: 1px solid var(--line-clr);
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            flex-shrink: 0;
        }

        .membership-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .member-info h3 {
            margin: 0 0 0.25rem 0;
            color: var(--text-clr);
            font-size: 0.9rem;
            font-weight: 600;
        }

        .member-details {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 0.5rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }

        .detail-label {
            font-size: 0.7rem;
            color: var(--secondary-text-clr);
            font-weight: 500;
        }

        .detail-value {
            color: var(--text-clr);
            font-weight: 500;
            font-size: 0.8rem;
        }

        .plan-info {
            background: rgba(94, 99, 255, 0.05);
            border-radius: 6px;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .plan-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--accent-clr);
            margin-bottom: 0.25rem;
        }

        .plan-price {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-clr);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .btn-approve {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.4rem 0.875rem;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.8rem;
        }

        .btn-approve:hover {
            background: #218838;
        }

        .btn-approve:disabled {
            background: #6c757d !important;
            cursor: not-allowed !important;
        }

        .or-upload-section {
            margin: 6px 0;
        }

        .file-upload-container {
            position: relative;
        }

        .file-upload-container input[type="file"] {
            display: none;
        }

        .file-upload-label {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            background: #007bff;
            color: white;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.3s ease;
            border: none;
        }

        .file-upload-label:hover {
            background: #0056b3;
        }

        .file-status {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
            padding: 5px 8px;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 5px;
            color: #155724;
        }

        .file-name {
            flex: 1;
            font-size: 0.75rem;
        }

        .remove-file {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
        }

        .remove-file:hover {
            background: #c82333;
        }

        .btn-reject {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.4rem 0.875rem;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.8rem;
        }

        .btn-reject:hover {
            background: #c82333;
        }

        .status-badge {
            padding: 0;
            border-radius: 0;
            font-size: 0.8rem;
            font-weight: 600;
            text-align: center;
        }

        .status-pending {
            background: none;
            color: var(--accent-clr);
            border: none;
        }

        .payment-method {
            font-weight: 500;
            font-size: 0.8rem;
        }

        .gcash-reference {
            font-family: 'Courier New', monospace;
            background: rgba(0, 123, 255, 0.1);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.75rem;
            color: #007bff;
        }

        .status-approved {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }

        .status-rejected {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        .status-cancelled {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--secondary-text-clr);
        }

        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .search-container {
            margin-bottom: 1.5rem;
            position: relative;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .search-bar {
            width: 100%;
            max-width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 1px solid var(--line-clr);
            border-radius: 8px;
            background: var(--base-clr);
            color: var(--text-clr);
            font-size: 0.9rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            box-sizing: border-box;
            resize: none;
        }

        .search-bar:focus {
            outline: none;
            border-color: var(--accent-clr);
            box-shadow: 0 0 0 3px rgba(94, 99, 255, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary-text-clr);
            pointer-events: none;
        }

        .no-results {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--secondary-text-clr);
            display: none;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.8);
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: var(--card-bg);
            margin: 0;
            padding: 2rem;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            border: 1px solid var(--line-clr);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translate(-50%, -60%);
            }
            to { 
                opacity: 1;
                transform: translate(-50%, -50%);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-clr);
            margin: 0;
        }

        .close {
            font-size: 2rem;
            font-weight: bold;
            color: var(--secondary-text-clr);
            cursor: pointer;
            line-height: 1;
        }

        .close:hover {
            color: var(--text-clr);
        }

        .form-group {
            margin-bottom: 1.5rem;
            width: 100%;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-clr);
            text-align: left;
        }

        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--line-clr);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-clr);
            resize: vertical;
            min-height: 100px;
            box-sizing: border-box;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
            width: 100%;
        }

        .btn-secondary {
            background: var(--secondary-clr);
            color: var(--text-clr);
            border: 1px solid var(--line-clr);
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .btn-secondary:hover {
            background: var(--line-clr);
        }

        @media (max-width: 768px) {
            .membership-transactions-container {
                width: 100%;
                max-width: 100%;
                padding: 1rem;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .member-details {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .modal-content {
                width: 95%;
                max-width: none;
                padding: 1.5rem;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
            }
        }
    </style>
</head>
<body>
    <div class="membership-transactions-container">
        <div class="section-header">
            <h1 class="section-title">Membership Transactions</h1>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab active" onclick="showTab('pending')">Pending Requests</button>
            <button class="tab" onclick="showTab('processed')">Processed History</button>
        </div>

        <div class="search-container">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <path d="m21 21-4.35-4.35"></path>
            </svg>
            <input type="text" id="searchInput" class="search-bar" placeholder="Search by name, email, or GCash reference..." onkeyup="filterTransactions()">
        </div>

        <div class="tabs-container">
            <!-- Pending Requests Tab -->
            <div id="pending-tab" class="tab-content active">
            <?php if (mysqli_num_rows($pending_result) > 0): ?>
                <?php while ($pending = mysqli_fetch_assoc($pending_result)): ?>
                    <div class="membership-card" data-name="<?= strtolower(htmlspecialchars($pending['full_name'])) ?>" data-email="<?= strtolower(htmlspecialchars($pending['email'])) ?>" data-gcash="<?= strtolower(htmlspecialchars($pending['gcash_reference_number'] ?? '')) ?>">
                        <div class="membership-header">
                            <div class="member-info">
                                <h3><?= htmlspecialchars($pending['full_name']) ?></h3>
                                <div class="member-details">
                                    <div class="detail-item">
                                        <span class="detail-label">Email</span>
                                        <span class="detail-value"><?= htmlspecialchars($pending['email']) ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Requested</span>
                                        <span class="detail-value"><?= date('M j, Y g:i A', strtotime($pending['requested_at'])) ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Payment Method</span>
                                        <span class="detail-value payment-method">
                                            <?= ucfirst($pending['payment_method']) ?>
                                        </span>
                                    </div>
                                    <?php if ($pending['payment_method'] === 'gcash'): ?>
                                    <div class="detail-item">
                                        <span class="detail-label">GCash Reference</span>
                                        <span class="detail-value gcash-reference">
                                            <?= htmlspecialchars($pending['gcash_reference_number']) ?>
                                        </span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Transaction Screenshot</span>
                                        <span class="detail-value">
                                            <?php if ($pending['gcash_screenshot_path']): ?>
                                                <button class="btn btn-secondary btn-sm" onclick="viewGCashScreenshot('<?= htmlspecialchars($pending['gcash_screenshot_path']) ?>')">
                                                    View Screenshot
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">No screenshot</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php elseif ($pending['payment_method'] === 'cash'): ?>
                                    <div class="detail-item">
                                        <span class="detail-label">Official Receipt (OR)</span>
                                        <span class="detail-value">
                                            <div class="or-upload-section" id="or-upload-<?= $pending['id'] ?>">
                                                <div class="file-upload-container">
                                                    <input type="file" id="orPhoto<?= $pending['id'] ?>" name="or_photo" accept="image/*" onchange="handleORUpload(<?= $pending['id'] ?>)">
                                                    <label for="orPhoto<?= $pending['id'] ?>" class="file-upload-label">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="margin-right: 8px;">
                                                            <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                                                        </svg>
                                                        Choose OR Photo
                                                    </label>
                                                    <div class="file-status" id="file-status-<?= $pending['id'] ?>" style="display: none;">
                                                        <span class="file-name"></span>
                                                        <button type="button" onclick="removeORUpload(<?= $pending['id'] ?>)" class="remove-file">×</button>
                                                    </div>
                                                </div>
                                                <small style="color: #ffc107; font-size: 0.8rem; display: block; margin-top: 5px;">
                                                    ⚠️ OR photo required for cash payment approval
                                                </small>
                                            </div>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="status-badge status-pending">Pending</div>
                        </div>

                        <div class="plan-info">
                            <div class="plan-name"><?= htmlspecialchars($pending['plan_name']) ?></div>
                            <div class="plan-price">₱<?= number_format($pending['plan_price'], 2) ?></div>
                        </div>

                        <div class="action-buttons">
                            <button class="btn-reject" onclick="showRejectModal(<?= $pending['id'] ?>)">Reject</button>
                            <?php if ($pending['payment_method'] === 'cash'): ?>
                                <button class="btn-approve" id="approve-btn-<?= $pending['id'] ?>" onclick="approveMembership(<?= $pending['id'] ?>, '<?= $pending['payment_method'] ?>')" disabled style="opacity: 0.5; cursor: not-allowed;">
                                    Process Payment
                                </button>
                            <?php else: ?>
                                <button class="btn-approve" onclick="approveMembership(<?= $pending['id'] ?>, '<?= $pending['payment_method'] ?>')">Approve & Process Payment</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                    <h3>No Pending Requests</h3>
                    <p>All membership requests have been processed.</p>
                </div>
            <?php endif; ?>
                <div class="no-results" id="pending-no-results">
                    <h3>No results found</h3>
                    <p>Try adjusting your search terms.</p>
                </div>
        </div>

        <!-- Processed History Tab -->
        <div id="processed-tab" class="tab-content">
            <?php if (mysqli_num_rows($processed_result) > 0): ?>
                <?php while ($processed = mysqli_fetch_assoc($processed_result)): ?>
                    <div class="membership-card" data-name="<?= strtolower(htmlspecialchars($processed['full_name'])) ?>" data-email="<?= strtolower(htmlspecialchars($processed['email'])) ?>" data-gcash="<?= strtolower(htmlspecialchars($processed['gcash_reference_number'] ?? '')) ?>">
                        <div class="membership-header">
                            <div class="member-info">
                                <h3><?= htmlspecialchars($processed['full_name']) ?></h3>
                                <div class="member-details">
                                    <div class="detail-item">
                                        <span class="detail-label">Email</span>
                                        <span class="detail-value"><?= htmlspecialchars($processed['email']) ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Processed By</span>
                                        <span class="detail-value"><?= htmlspecialchars($processed['processed_by_name'] ?? 'System') ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Processed</span>
                                        <span class="detail-value"><?= $processed['processed_at'] ? date('M j, Y g:i A', strtotime($processed['processed_at'])) : ($processed['requested_at'] ? date('M j, Y g:i A', strtotime($processed['requested_at'])) : 'N/A') ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Payment Method</span>
                                        <span class="detail-value payment-method">
                                            <?= ucfirst($processed['payment_method']) ?>
                                        </span>
                                    </div>
                                    <?php if ($processed['payment_method'] === 'gcash'): ?>
                                    <div class="detail-item">
                                        <span class="detail-label">GCash Reference</span>
                                        <span class="detail-value gcash-reference">
                                            <?= htmlspecialchars($processed['gcash_reference_number']) ?>
                                        </span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Transaction Screenshot</span>
                                        <span class="detail-value">
                                            <?php if ($processed['gcash_screenshot_path']): ?>
                                                <button class="btn btn-secondary btn-sm" onclick="viewGCashScreenshot('<?= htmlspecialchars($processed['gcash_screenshot_path']) ?>')">
                                                    View Screenshot
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">No screenshot</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php elseif ($processed['payment_method'] === 'cash'): ?>
                                    <div class="detail-item">
                                        <span class="detail-label">Official Receipt (OR)</span>
                                        <span class="detail-value">
                                            <?php if ($processed['or_photo_path']): ?>
                                                <button class="btn btn-secondary btn-sm" onclick="viewORReceipt('<?= htmlspecialchars($processed['or_photo_path']) ?>')">
                                                    View Receipt
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">No receipt</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="status-badge <?= $processed['status'] === 'approved' ? 'status-approved' : ($processed['status'] === 'cancelled' ? 'status-cancelled' : 'status-rejected') ?>">
                                <?= ucfirst($processed['status']) ?>
                            </div>
                        </div>

                        <div class="plan-info">
                            <div class="plan-name"><?= htmlspecialchars($processed['plan_name']) ?></div>
                            <div class="plan-price">₱<?= number_format($processed['plan_price'], 2) ?></div>
                        </div>

                        <?php 
                        $notes = trim($processed['notes'] ?? '');
                        // Only show notes if they exist and are not "Unknown Reference" or empty
                        if (!empty($notes) && strtolower($notes) !== 'unknown reference'): ?>
                            <div class="detail-item">
                                <span class="detail-label">Notes</span>
                                <span class="detail-value"><?= htmlspecialchars($notes) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                    <h3>No Processed Requests</h3>
                    <p>No membership requests have been processed yet.</p>
                </div>
            <?php endif; ?>
                <div class="no-results" id="processed-no-results">
                    <h3>No results found</h3>
                    <p>Try adjusting your search terms.</p>
                </div>
        </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Reject Membership Request</h2>
                <span class="close" onclick="closeRejectModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="pending_id" id="rejectPendingId">
                <div class="form-group">
                    <label for="rejectNotes">Reason for rejection (optional):</label>
                    <textarea name="notes" id="rejectNotes" placeholder="Enter reason for rejection..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn-reject">Reject Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cash Approval Modal -->
    <div id="cashApprovalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Approve Cash Payment Request</h2>
                <span class="close" onclick="closeCashApprovalModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="orPhoto">Upload Official Receipt (OR) Photo *</label>
                    <input type="file" id="orPhoto" name="or_photo" accept="image/*" required>
                    <small style="color: #888; font-size: 0.8rem;">Please upload a clear photo of the official receipt for this cash payment.</small>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeCashApprovalModal()">Cancel</button>
                    <button type="button" class="btn-approve" onclick="submitCashApproval()">Proceed with OR</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            const targetTab = document.getElementById(tabName + '-tab');
            
            // Remove active class from all tabs
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Add active class to clicked tab
            event.target.classList.add('active');
            
            // Hide all tab contents
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Show target tab
            targetTab.classList.add('active');
            
            // Clear search when switching tabs
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.value = '';
                filterTransactions();
            }
        }

        function approveMembership(pendingId, paymentMethod) {
            if (paymentMethod === 'cash') {
                // For cash payments, check if OR is uploaded
                const orFile = document.getElementById('orPhoto' + pendingId).files[0];
                if (!orFile) {
                    alert('Please upload the Official Receipt (OR) photo before processing the payment.');
                    return;
                }
                
                if (confirm('Are you sure you want to approve this cash payment request? This will activate the membership and record the transaction.')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.enctype = 'multipart/form-data';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'approve';
                    form.appendChild(actionInput);
                    
                    const pendingIdInput = document.createElement('input');
                    pendingIdInput.type = 'hidden';
                    pendingIdInput.name = 'pending_id';
                    pendingIdInput.value = pendingId;
                    form.appendChild(pendingIdInput);
                    
                    const fileInput = document.createElement('input');
                    fileInput.type = 'file';
                    fileInput.name = 'or_photo';
                    fileInput.files = document.getElementById('orPhoto' + pendingId).files;
                    form.appendChild(fileInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            } else {
                // For non-cash payments, proceed directly
                if (confirm('Are you sure you want to approve this membership request? This will activate the membership and record the transaction.')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="pending_id" value="${pendingId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }
        
        function showCashApprovalModal(pendingId) {
            document.getElementById('cashApprovalPendingId').value = pendingId;
            document.getElementById('cashApprovalModal').style.display = 'block';
        }
        
        function submitCashApproval() {
            const pendingId = document.getElementById('cashApprovalPendingId').value;
            const orPhoto = document.getElementById('orPhoto').files[0];
            
            if (!orPhoto) {
                alert('Please upload the Official Receipt (OR) photo before proceeding.');
                return;
            }
            
            if (confirm('Are you sure you want to approve this cash payment request? This will activate the membership and record the transaction.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.enctype = 'multipart/form-data';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'approve';
                form.appendChild(actionInput);
                
                const pendingIdInput = document.createElement('input');
                pendingIdInput.type = 'hidden';
                pendingIdInput.name = 'pending_id';
                pendingIdInput.value = pendingId;
                form.appendChild(pendingIdInput);
                
                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.name = 'or_photo';
                fileInput.files = document.getElementById('orPhoto').files;
                form.appendChild(fileInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        function showRejectModal(pendingId) {
            document.getElementById('rejectPendingId').value = pendingId;
            document.getElementById('rejectModal').style.display = 'block';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.getElementById('rejectNotes').value = '';
        }
        
        function closeCashApprovalModal() {
            document.getElementById('cashApprovalModal').style.display = 'none';
            document.getElementById('orPhoto').value = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const rejectModal = document.getElementById('rejectModal');
            const cashModal = document.getElementById('cashApprovalModal');
            if (event.target === rejectModal) {
                closeRejectModal();
            } else if (event.target === cashModal) {
                closeCashApprovalModal();
            }
        }
        // Handle OR Upload
        function handleORUpload(pendingId) {
            const fileInput = document.getElementById('orPhoto' + pendingId);
            const fileStatus = document.getElementById('file-status-' + pendingId);
            const approveBtn = document.getElementById('approve-btn-' + pendingId);
            
            if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                const fileName = file.name;
                
                // Show file status
                fileStatus.style.display = 'flex';
                fileStatus.querySelector('.file-name').textContent = fileName;
                
                // Enable approve button
                approveBtn.disabled = false;
                approveBtn.style.opacity = '1';
                approveBtn.style.cursor = 'pointer';
            } else {
                // Hide file status
                fileStatus.style.display = 'none';
                
                // Disable approve button
                approveBtn.disabled = true;
                approveBtn.style.opacity = '0.5';
                approveBtn.style.cursor = 'not-allowed';
            }
        }
        
        // Remove OR Upload
        function removeORUpload(pendingId) {
            const fileInput = document.getElementById('orPhoto' + pendingId);
            const fileStatus = document.getElementById('file-status-' + pendingId);
            const approveBtn = document.getElementById('approve-btn-' + pendingId);
            
            // Clear file input
            fileInput.value = '';
            
            // Hide file status
            fileStatus.style.display = 'none';
            
            // Disable approve button
            approveBtn.disabled = true;
            approveBtn.style.opacity = '0.5';
            approveBtn.style.cursor = 'not-allowed';
        }

        // View GCash Screenshot
        function viewGCashScreenshot(filename) {
            const screenshotUrl = '../assets/gcash_payments/' + filename;
            window.open(screenshotUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
        }

        // View OR Receipt
        function viewORReceipt(photoPath) {
            const orUrl = '../' + photoPath;
            window.open(orUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
        }

        // Search functionality
        function filterTransactions() {
            const searchInput = document.getElementById('searchInput');
            const searchTerm = searchInput.value.toLowerCase().trim();
            
            // Get active tab
            const activeTab = document.querySelector('.tab-content.active');
            const tabId = activeTab.id;
            
            // Get all cards in active tab
            const cards = activeTab.querySelectorAll('.membership-card');
            const noResults = document.getElementById(tabId === 'pending-tab' ? 'pending-no-results' : 'processed-no-results');
            const emptyState = activeTab.querySelector('.empty-state');
            
            let visibleCount = 0;
            
            cards.forEach(card => {
                const name = card.getAttribute('data-name') || '';
                const email = card.getAttribute('data-email') || '';
                const gcash = card.getAttribute('data-gcash') || '';
                
                if (searchTerm === '' || 
                    name.includes(searchTerm) || 
                    email.includes(searchTerm) || 
                    gcash.includes(searchTerm)) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            if (searchTerm !== '' && visibleCount === 0) {
                noResults.style.display = 'block';
                if (emptyState) emptyState.style.display = 'none';
            } else {
                noResults.style.display = 'none';
                if (emptyState) emptyState.style.display = 'block';
            }
        }

    </script>
</body>
</html>
