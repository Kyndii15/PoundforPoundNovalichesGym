<?php
header('Content-Type: application/json');

try {
    include '../config.php';
    include '../includes/QRCodeGenerator.php';
    include '../includes/activity_logger.php';
    
    // Try to get user ID from session (if available)
    $user_id = null;
    if (session_status() == PHP_SESSION_NONE) {
        // Try different session names
        session_name('gym_admin_session');
        @session_start();
        $user_id = $_SESSION['user_id'] ?? null;
        if (!$user_id) {
            session_name('gym_manager_session');
            @session_start();
            $user_id = $_SESSION['user_id'] ?? null;
        }
        if (!$user_id) {
            session_name('gym_coach_session');
            @session_start();
            $user_id = $_SESSION['user_id'] ?? null;
        }
    } else {
        $user_id = $_SESSION['user_id'] ?? null;
    }

    date_default_timezone_set('Asia/Manila');
    if (isset($conn)) {
        @$conn->query("SET time_zone = '+08:00'");
    }

    $required = ['name','package','amount','payment_method'];
    foreach ($required as $key) {
        if (!isset($_POST[$key]) || $_POST[$key] === '') {
            echo json_encode(['success' => false, 'error' => "Missing field: $key"]);
            exit;
        }
    }

    $name = trim($_POST['name']);
    $package = trim($_POST['package']);
    $amount = floatval($_POST['amount']);
    $payment_method = trim($_POST['payment_method']);
    $amount_given = isset($_POST['amount_given']) ? floatval($_POST['amount_given']) : $amount;
    $change_amount = isset($_POST['change_amount']) ? floatval($_POST['change_amount']) : 0.0;
    $dateOnly = isset($_POST['date']) && $_POST['date'] !== '' ? $_POST['date'] : date('Y-m-d');
    $date = $dateOnly . ' ' . date('H:i:s');

    // Handle customer photo upload (base64 to file)
    $customer_photo_path = null;
    if (isset($_POST['customer_photo_data']) && !empty($_POST['customer_photo_data'])) {
        $photo_data = $_POST['customer_photo_data'];
        error_log("API: Photo data received, length: " . strlen($photo_data));
        
        // Check if it's a base64 image
        if (preg_match('/^data:image\/(\w+);base64,/', $photo_data, $matches)) {
            $base_upload_dir = __DIR__ . '/../uploads';
            if (!is_dir($base_upload_dir)) {
                @mkdir($base_upload_dir, 0777, true);
            }
            $upload_dir_abs = realpath($base_upload_dir) ?: $base_upload_dir;
            $photo_dir_abs = $upload_dir_abs . '/walkin_photos';
            if (!is_dir($photo_dir_abs)) {
                @mkdir($photo_dir_abs, 0777, true);
            }
            
            $image_data = base64_decode(substr($photo_data, strpos($photo_data, ',') + 1));
            $filename = 'customer_' . time() . '_' . uniqid() . '.jpg';
            $file_path = $photo_dir_abs . '/' . $filename;
            
            if (file_put_contents($file_path, $image_data)) {
                $customer_photo_path = 'uploads/walkin_photos/' . $filename;
                error_log("API: Customer photo saved successfully: " . $customer_photo_path);
            } else {
                error_log("API: Failed to save customer photo to: " . $file_path);
            }
        } else {
            error_log("API: Photo data does not match base64 image format");
        }
    } else {
        error_log("API: No customer_photo_data in POST");
    }

    $receipt_path = null;
    if (!empty($_FILES['receipt_file']) && isset($_FILES['receipt_file']['tmp_name']) && is_uploaded_file($_FILES['receipt_file']['tmp_name'])) {
        if ($_FILES['receipt_file']['error'] !== UPLOAD_ERR_OK) {
            error_log('API save_walkin: upload error code ' . $_FILES['receipt_file']['error']);
            echo json_encode(['success' => false, 'error' => 'Receipt upload failed (code ' . $_FILES['receipt_file']['error'] . ')']);
            exit;
        }
        $base_upload_dir = __DIR__ . '/../uploads';
        if (!is_dir($base_upload_dir)) {
            @mkdir($base_upload_dir, 0777, true);
        }
        $upload_dir_abs = realpath($base_upload_dir) ?: $base_upload_dir;
        $walkin_dir_abs = $upload_dir_abs . '/walkin_receipts';
        if (!is_dir($walkin_dir_abs)) {
            @mkdir($walkin_dir_abs, 0777, true);
        }
        $original_name = $_FILES['receipt_file']['name'] ?? '';
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['receipt_file']['tmp_name']);
        // Accept any image/* MIME; map to an extension when missing/unknown
        $mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/bmp' => 'bmp'
        ];
        if (stripos($mime, 'image/') === 0) {
            if ($ext === '' || !in_array($ext, array_values($mime_to_ext))) {
                $ext = $mime_to_ext[$mime] ?? 'png';
            }
            $filename = 'walkin_' . time() . '_' . uniqid() . '.' . $ext;
            $dest_abs = $walkin_dir_abs . '/' . $filename;
            if (@move_uploaded_file($_FILES['receipt_file']['tmp_name'], $dest_abs)) {
                $receipt_path = 'uploads/walkin_receipts/' . $filename; // web path
            } else {
                error_log('API save_walkin: move_uploaded_file failed for ' . $_FILES['receipt_file']['name']);
                echo json_encode(['success' => false, 'error' => 'Failed to save uploaded receipt.']);
                exit;
            }
        } else {
            error_log('API save_walkin: unsupported MIME type ' . $mime);
            echo json_encode(['success' => false, 'error' => 'Unsupported receipt image type (MIME: ' . $mime . ').']);
            exit;
        }
    }

    // Duplicate check (same name + same package + same date only - not time)
    // Compare only the date part, ignore time
    $check = $conn->prepare("SELECT id FROM walk_in_log WHERE name = ? AND package = ? AND DATE(date) = ?");
    $check->bind_param('sss', $name, $package, $dateOnly);
    $check->execute();
    $dup = $check->get_result();
    if ($dup && $dup->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'Duplicate entry for this customer, package and date.']);
        exit;
    }

    // Generate unique walk-in reference number
    $qrGenerator = new QRCodeGenerator($conn);
    $walkin_ref = $qrGenerator->generateWalkinRef();

    // Insert walk-in
    $stmt = $conn->prepare("INSERT INTO walk_in_log (name, package, amount, amount_given, change_amount, payment_method, receipt_path, customer_photo, walkin_ref, date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
    $stmt->bind_param('ssdddsssss', $name, $package, $amount, $amount_given, $change_amount, $payment_method, $receipt_path, $customer_photo_path, $walkin_ref, $date);
    if (!$stmt->execute()) {
        error_log('API Walk-in insert failed: ' . $stmt->error);
        echo json_encode(['success' => false, 'error' => 'Failed to insert walk-in.']);
        exit;
    }
    
    $walkin_id = $conn->insert_id; // Get walk-in ID from walk-in insert

    // Insert transaction
    $transaction = $conn->prepare("INSERT INTO transactions (type, amount, customer_name, plan_name, payment_method, date) VALUES ('walk-in', ?, ?, ?, ?, ?)");
    $transaction->bind_param('dssss', $amount, $name, $package, $payment_method, $date);
    if (!$transaction->execute()) {
        error_log('API Transaction insert failed: ' . $transaction->error);
        // still return success for walk-in, but flag transaction failure
        echo json_encode(['success' => true, 'transaction' => false]);
        exit;
    }
    
    // Log activity: Walk-in Recording
    $transaction_id = $conn->insert_id; // Get transaction ID
    logActivity($conn, 'walkin_recording', "Walk-in Recording: {$name} - {$package} (₱{$amount} via {$payment_method})", $user_id, $walkin_id, 'walkin', ['customer_name' => $name, 'package' => $package, 'amount' => $amount, 'payment_method' => $payment_method]);
    logActivity($conn, 'payment_transaction', "Payment Transaction: Walk-in payment from {$name} - ₱{$amount} for {$package} via {$payment_method}", $user_id, $transaction_id, 'transaction', ['amount' => $amount, 'package' => $package, 'method' => $payment_method, 'type' => 'walkin']);

    echo json_encode(['success' => true, 'receipt_path' => $receipt_path]);
} catch (Throwable $e) {
    error_log('API save_walkin fatal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}

