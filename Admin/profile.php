<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include '../config.php';
include '../includes/activity_logger.php';

// Start role-specific session to access session variables
session_name('gym_admin_session');
session_start();

include '../auth_check.php';

// Require admin role for admin profile access
requireRole('admin');

// Get user ID from session (authentication ensures this exists)
$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Delete the specific activity log record: "Attendance Recording: Rainer Bon checked in via qr_scan"
if (isset($conn) && $conn) {
    $delete_query = "DELETE FROM activity_log WHERE description = 'Attendance Recording: Rainer Bon checked in via qr_scan' AND activity_type = 'attendance_recording'";
    $delete_result = mysqli_query($conn, $delete_query);
    if ($delete_result) {
        $deleted_count = mysqli_affected_rows($conn);
        if ($deleted_count > 0) {
            $success_message = "Activity log record removed successfully.";
        }
    } else {
        error_log("Failed to delete activity log record: " . mysqli_error($conn));
    }
}

// Check if profile_photo column exists
$profile_photo_column_exists = false;
if (isset($conn) && $conn) {
    $check_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_photo'");
    if ($check_column && mysqli_num_rows($check_column) > 0) {
        $profile_photo_column_exists = true;
    }
    // Alternative check using DESCRIBE
    if (!$profile_photo_column_exists) {
        $describe_result = mysqli_query($conn, "DESCRIBE users");
        if ($describe_result) {
            while ($row = mysqli_fetch_assoc($describe_result)) {
                if (isset($row['Field']) && $row['Field'] === 'profile_photo') {
                    $profile_photo_column_exists = true;
                    break;
                }
            }
        }
    }
}

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    if (!$profile_photo_column_exists) {
        $errors[] = "Profile photo feature is not available. Please run the database migration script first.";
    } else {
        $upload_dir = __DIR__ . '/../uploads/profile_photos/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_photo'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime_type, $allowed_types)) {
                $errors[] = "Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.";
            } elseif ($file['size'] > $max_size) {
                $errors[] = "File size too large. Maximum size is 5MB.";
            } else {
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                // Delete old profile photo if exists
                $old_photo_query = "SELECT profile_photo FROM users WHERE id = $user_id";
                $old_photo_result = mysqli_query($conn, $old_photo_query);
                if ($old_photo_result && $old_photo = mysqli_fetch_assoc($old_photo_result)) {
                    if ($old_photo['profile_photo'] && file_exists(__DIR__ . '/../uploads/profile_photos/' . $old_photo['profile_photo'])) {
                        @unlink(__DIR__ . '/../uploads/profile_photos/' . $old_photo['profile_photo']);
                    }
                }
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    $relative_path = $filename;
                    $update_photo_query = "UPDATE users SET profile_photo = '$relative_path' WHERE id = $user_id";
                    if (mysqli_query($conn, $update_photo_query)) {
                        $success_message = "Profile photo uploaded successfully!";
                    } else {
                        $errors[] = "Failed to update profile photo in database.";
                        @unlink($filepath);
                    }
                } else {
                    $errors[] = "Failed to upload file. Please try again.";
                }
            }
        } else {
            $errors[] = "No file uploaded or upload error occurred.";
        }
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    
    // Update profile (only full name)
    $update_query = "UPDATE users SET full_name = '$full_name' WHERE id = $user_id";
    
    if (mysqli_query($conn, $update_query)) {
        // Update session data
        $_SESSION['user_name'] = $full_name;
        // Get email from database after update
        $email_query = mysqli_query($conn, "SELECT email FROM users WHERE id = $user_id");
        if ($email_query && $email_row = mysqli_fetch_assoc($email_query)) {
            $_SESSION['user_email'] = $email_row['email'];
        }
        $success_message = "Profile updated successfully!";
        // Refresh user data
        $user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
        if ($user_query) {
            $user_data = mysqli_fetch_assoc($user_query);
        }
    } else {
        $errors[] = "Failed to update profile. Please try again.";
    }
}

// Ensure phone column exists in users table
$check_phone_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'phone'");
if (mysqli_num_rows($check_phone_column) == 0) {
    // Add phone column if it doesn't exist
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER email");
}

// Get current user data - use SELECT * to avoid column name issues
$user_query = "SELECT * FROM users WHERE id = $user_id";
$user_result = mysqli_query($conn, $user_query);

if (!$user_result) {
    $errors[] = "Failed to retrieve user data. Please try again.";
    $user_data = [];
} else {
    $user_data = mysqli_fetch_assoc($user_result);
    if (!$user_data) {
        $errors[] = "User data not found. Please contact administrator.";
        $user_data = [];
    } else {
        // Debug: Check what we actually got from database
        error_log("=== PHONE DEBUG ===");
        error_log("User ID: " . $user_id);
        error_log("All user_data keys: " . implode(', ', array_keys($user_data)));
        error_log("Phone key exists: " . (array_key_exists('phone', $user_data) ? 'YES' : 'NO'));
        error_log("Phone isset: " . (isset($user_data['phone']) ? 'yes' : 'no'));
        if (array_key_exists('phone', $user_data)) {
            error_log("Phone value (raw): " . var_export($user_data['phone'], true));
            error_log("Phone type: " . gettype($user_data['phone']));
            error_log("Phone === null: " . ($user_data['phone'] === null ? 'YES' : 'NO'));
            error_log("Phone empty(): " . (empty($user_data['phone']) ? 'YES' : 'NO'));
            error_log("Phone strlen: " . (is_string($user_data['phone']) ? strlen($user_data['phone']) : 'N/A'));
        }
        
        // Handle phone value - check for NULL, empty string, or whitespace
        // First check if the key exists in the array (column might not exist)
        if (!array_key_exists('phone', $user_data)) {
            // Column doesn't exist in result - try to get it directly
            $phone_check = mysqli_query($conn, "SELECT phone FROM users WHERE id = $user_id");
            if ($phone_check && $phone_row = mysqli_fetch_assoc($phone_check)) {
                $user_data['phone'] = $phone_row['phone'] ?? null;
            } else {
                $user_data['phone'] = null;
            }
        }
        
        // Now handle the value
        if ($user_data['phone'] === null || $user_data['phone'] === '') {
            $user_data['phone'] = null;
        } else {
            // Trim whitespace
            $user_data['phone'] = trim($user_data['phone']);
            // If it's an empty string after trimming, set to null
            if ($user_data['phone'] === '') {
                $user_data['phone'] = null;
            }
        }
        
        error_log("Final phone value: " . var_export($user_data['phone'], true));
        error_log("==================");
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Admin Dashboard</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/user.css">
    <script type="text/javascript" src="../java/admin.js?v=2.0" defer></script>
    <style>
        /* Enhanced Personal Information UI */
        .header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .edit-btn {
            background: var(--accent);
            color: white;
            border: 1px solid var(--accent);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            font-size: 0.875rem;
        }

        .edit-btn:hover {
            background: #4c52e8;
            border-color: #4c52e8;
        }

        .profile-display {
            margin-top: 1.5rem;
            background: transparent;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--line-clr);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .info-item {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid rgba(94, 99, 255, 0.08);
        }

        .info-item .info-label {
            font-weight: 700;
            color: var(--accent);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-item .info-label::before {
            content: '';
            width: 4px;
            height: 4px;
            background: var(--accent);
            border-radius: 50%;
        }

        .info-item .info-value {
            color: var(--text-clr);
            font-size: 1.1rem;
            font-weight: 500;
            line-height: 1.4;
            word-break: break-word;
        }

        .info-item .info-value:empty::after {
            content: 'Not provided';
            color: var(--secondary-text-clr);
            font-style: italic;
            font-weight: 400;
        }

        /* Enhanced Edit Form Styling */
        .profile-form-container {
            margin-top: 1.5rem;
            background: transparent;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--line-clr);
        }

        .profile-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .profile-form .form-group {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid rgba(94, 99, 255, 0.08);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .profile-form .form-group label {
            font-weight: 700;
            color: var(--accent);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-form .form-group label::before {
            content: '';
            width: 4px;
            height: 4px;
            background: var(--accent);
            border-radius: 50%;
        }

        .profile-form .form-group input,
        .profile-form .form-group textarea {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(94, 99, 255, 0.2);
            border-radius: 6px;
            padding: 0.75rem;
            color: var(--text-clr);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .profile-form .form-group input:focus,
        .profile-form .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(94, 99, 255, 0.1);
        }

        .profile-form .form-group input[readonly] {
            background: rgba(255, 255, 255, 0.02);
            color: var(--secondary-text-clr);
            cursor: not-allowed;
            border-color: rgba(94, 99, 255, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: flex-end;
            grid-column: 1 / -1;
        }

        .form-actions .btn {
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            border: 1px solid;
            transition: all 0.3s ease;
        }

        .form-actions .btn-primary {
            background: #28a745 !important;
            color: white !important;
            border-color: #28a745 !important;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
        }

        .form-actions .btn-primary:hover {
            background: #218838 !important;
            border-color: #1e7e34 !important;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }

        /* Two Column Layout */
        .two-column-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        /* Account Information Panel Styling */
        .panel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .info-panel {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
        }

        .info-panel-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .info-panel-header i {
            font-size: 1.5rem;
        }

        .info-panel-header h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .info-panel-header.account i {
            color: var(--accent);
        }

        .info-panel-header.account h3 {
            color: var(--accent);
        }

        .info-panel-content {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            flex: 1;
        }

        .info-panel-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 0.75rem;
        }

        .info-panel-label {
            color: #b0b3c1;
            font-size: 0.75rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .info-panel-value {
            color: white;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-success {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .status-error {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        /* Profile Photo Styles */
        .profile-photo-section {
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 12px;
            border: 1px solid var(--line-clr);
            text-align: center;
        }

        .profile-photo-container {
            position: relative;
            display: inline-block;
            margin-bottom: 1rem;
        }

        .profile-photo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent);
            background: rgba(255, 255, 255, 0.05);
        }

        .profile-photo-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: rgba(94, 99, 255, 0.1);
            border: 3px solid var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            color: var(--accent);
            font-size: 3rem;
        }

        .photo-upload-form {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .photo-upload-input {
            display: none;
        }

        .photo-upload-label {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: var(--accent);
            color: white;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
        }

        .photo-upload-label:hover {
            background: #4c52e8;
        }

        .photo-preview {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            margin-top: 1rem;
            display: none;
        }

        .photo-upload-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .btn-remove-photo {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
        }

        .btn-remove-photo:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        /* Responsive design */
        /* Tablet and below */
        @media (max-width: 992px) {
            .two-column-layout {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .info-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }

            .profile-form {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }

        /* Mobile devices */
        @media (max-width: 768px) {
            main {
                padding: 1rem;
            }

            .two-column-layout {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                margin-top: 1rem;
            }

            .panel-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
                margin-top: 1rem;
            }

            .info-grid,
            .profile-form {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .info-item,
            .profile-form .form-group {
                padding: 0.75rem;
            }

            .info-item .info-label {
                font-size: 0.75rem;
            }

            .info-item .info-value {
                font-size: 0.95rem;
            }

            .info-panel {
                padding: 1rem;
            }

            .form-actions {
                flex-direction: column;
                gap: 0.75rem;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .edit-btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
                width: 100%;
                justify-content: center;
            }

            .profile-form-container,
            .profile-display {
                padding: 1rem;
                margin-top: 1rem;
            }

            .section-card {
                padding: 1rem;
            }

            .section-header h2 {
                font-size: 1.25rem;
            }
        }

        /* Small phones */
        @media (max-width: 480px) {
            main {
                padding: 0.75rem;
            }

            h1 {
                font-size: 1.5rem;
            }

            .info-item,
            .profile-form .form-group {
                padding: 0.625rem;
            }

            .info-item .info-label {
                font-size: 0.7rem;
                margin-bottom: 0.375rem;
            }

            .info-item .info-value {
                font-size: 0.875rem;
            }

            .profile-form-container,
            .profile-display {
                padding: 0.75rem;
            }

            .section-card {
                padding: 0.75rem;
            }

            .info-panel {
                padding: 0.75rem;
            }

            .info-panel-header {
                gap: 0.5rem;
                margin-bottom: 0.75rem;
            }

            .info-panel-header i {
                font-size: 1.25rem;
            }

            .info-panel-header h3 {
                font-size: 0.9rem;
            }

            .info-panel-label {
                font-size: 0.7rem;
            }

            .info-panel-value {
                font-size: 0.85rem;
            }

            .edit-btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.75rem;
            }

            .form-actions .btn {
                padding: 0.625rem 1.5rem;
                font-size: 0.875rem;
            }

            .profile-photo-section {
                padding: 1rem;
            }

            .profile-photo,
            .profile-photo-placeholder {
                width: 120px;
                height: 120px;
            }

            .profile-photo-placeholder {
                font-size: 2.5rem;
            }
        }

        /* Activity Log Styles */
        .activity-log-container {
            margin-top: 1.5rem;
            background: transparent;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--line-clr);
        }

        .activity-log-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-height: 520px; /* Limit to show exactly 4 activity items (each ~120px + 3 gaps of 16px) */
            overflow-y: auto;
            padding-right: 0.5rem;
            scrollbar-width: thin;
            scrollbar-color: var(--accent) rgba(255, 255, 255, 0.05);
        }

        .activity-log-list::-webkit-scrollbar {
            width: 6px;
        }

        .activity-log-list::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }

        .activity-log-list::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 3px;
        }

        .activity-log-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            border: 1px solid rgba(94, 99, 255, 0.08);
            transition: all 0.3s ease;
        }

        .activity-log-item:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(94, 99, 255, 0.2);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .activity-description {
            color: var(--text-clr);
            font-size: 0.95rem;
            font-weight: 500;
            line-height: 1.4;
        }

        .activity-meta {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--secondary-text-clr);
        }

        .activity-separator {
            color: var(--secondary-text-clr);
            opacity: 0.5;
        }

        .activity-time,
        .activity-user {
            color: var(--secondary-text-clr);
        }

        .activity-log-empty {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--secondary-text-clr);
        }

        @media (max-width: 768px) {
            .activity-log-item {
                padding: 0.75rem;
                gap: 0.75rem;
            }

            .activity-icon {
                width: 35px;
                height: 35px;
                font-size: 1rem;
            }

            .activity-description {
                font-size: 0.875rem;
            }

            .activity-meta {
                font-size: 0.75rem;
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <main>
        <h1>My Profile</h1>
        <p></p>

        <!-- Messages -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c17.7 0 32 14.3 32 32V256c0 17.7-14.3 32-32 32s-32-14.3-32-32V160c0-17.7 14.3-32 32-32zM224 352a32 32 0 1 1 64 0 32 32 0 1 1 -64 0z"/></svg>
                <span><?= implode(', ', $errors) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c70.7 0 128 57.3 128 128s-57.3 128-128 128s-128-57.3-128-128s57.3-128 128-128zM96.8 431.2c5.1-12.2 19.1-18.8 31.3-13.7c67.9 28.3 144.1 28.3 212 0c12.2-5.1 26.2 1.5 31.3 13.7c5.1 12.2-1.5 26.2-13.7 31.3c-82.6 34.4-175.7 34.4-258.3 0c-12.2-5.1-18.8-19.1-13.7-31.3z"/></svg>
                <span><?= $success_message ?></span>
            </div>
        <?php endif; ?>
        
        <?php 
        // Temporary debug display - remove after fixing
        if (isset($_GET['debug']) && $_GET['debug'] === 'phone'): 
            $debug_query = mysqli_query($conn, "SELECT id, full_name, email, phone FROM users WHERE id = $user_id");
            $debug_data = mysqli_fetch_assoc($debug_query);
        ?>
            <div class="alert" style="background: #fff3cd; border: 1px solid #ffc107; padding: 1rem; margin: 1rem 0;">
                <strong>Debug Info:</strong><br>
                User ID: <?= $user_id ?><br>
                Phone from DB: <?= var_export($debug_data['phone'] ?? 'NOT SET', true) ?><br>
                Phone in $user_data: <?= var_export($user_data['phone'] ?? 'NOT SET', true) ?><br>
                Phone empty check: <?= empty($user_data['phone']) ? 'YES (empty)' : 'NO (has value)' ?><br>
                Phone trim check: <?= (!empty($user_data['phone']) && trim($user_data['phone']) !== '') ? 'HAS VALUE' : 'EMPTY/NULL' ?>
            </div>
        <?php endif; ?>

        <!-- Profile Photo and Personal Information Side by Side -->
        <div class="two-column-layout">
            <!-- Profile Photo Section -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Profile Photo</h2>
                </div>
                <div class="profile-photo-section">
                    <div class="profile-photo-container">
                        <?php 
                        $profile_photo_path = null;
                        if ($profile_photo_column_exists) {
                            $profile_photo_path = $user_data['profile_photo'] ?? null;
                        }
                        if ($profile_photo_path && file_exists(__DIR__ . '/../uploads/profile_photos/' . $profile_photo_path)): 
                        ?>
                            <img src="../uploads/profile_photos/<?= htmlspecialchars($profile_photo_path) ?>" alt="Profile Photo" class="profile-photo" id="current-profile-photo">
                        <?php else: ?>
                            <div class="profile-photo-placeholder">
                                <i class='bx bx-user'></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="photo-upload-form">
                        <input type="file" id="profile-photo-input" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" class="photo-upload-input" onchange="previewPhoto(this)">
                        <label for="profile-photo-input" class="photo-upload-label">
                            <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512" style="vertical-align: middle; margin-right: 0.5rem;"><path fill="currentColor" d="M288 109.3V352c0 17.7-14.3 32-32 32s-32-14.3-32-32V109.3l-73.4 73.4c-12.5 12.5-32.8 12.5-45.3 0s-12.5-32.8 0-45.3l128-128c12.5-12.5 32.8-12.5 45.3 0l128 128c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L288 109.3zM64 352H192c0 35.3 28.7 64 64 64s64-28.7 64-64H448c35.3 0 64 28.7 64 64v32c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V416c0-35.3 28.7-64 64-64zM432 456a24 24 0 1 0 0-48 24 24 0 1 0 0 48z"/></svg>
                            Upload Photo
                        </label>
                        <img id="photo-preview" class="photo-preview" alt="Preview">
                        <div class="photo-upload-actions" id="photo-actions" style="display: none;">
                            <button type="submit" name="upload_photo" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                Save Photo
                            </button>
                            <button type="button" class="btn-remove-photo" onclick="cancelPhotoUpload()">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Profile Information -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Personal Information</h2>
                    <div class="header-actions">
                        <button type="button" id="edit-profile-btn" class="btn btn-secondary edit-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512"><path d="M471.6 21.7c-21.9-21.9-57.3-21.9-79.2 0L362.3 51.7l97.9 97.9 30.1-30.1c21.9-21.9 21.9-57.3 0-79.2L471.6 21.7zm-299.2 220c-6.1 6.1-10.8 13.6-13.5 21.9l-29.6 88.8c-2.9 8.6-.6 18.1 5.8 24.6s15.9 8.7 24.6 5.8l88.8-29.6c8.2-2.7 15.7-7.4 21.9-13.5L437.7 172.3 339.7 74.3 172.4 241.7zM96 64C43 64 0 107 0 160V416c0 53 43 96 96 96H352c53 0 96-43 96-96V320c0-17.7-14.3-32-32-32s-32 14.3-32 32v96c0 17.7-14.3 32-32 32H96c-17.7 0-32-14.3-32-32V160c0-17.7 14.3-32 32-32h96c17.3 0 32-14.3 32-32s-14.3-32-32-32H96z"/></svg>
                            <span>Edit</span>
                        </button>
                    </div>
                </div>
                
                 <!-- Read-only display -->
                 <div id="profile-display" class="profile-display">
                     <div class="info-grid">
                         <div class="info-item">
                             <span class="info-label">Full Name</span>
                             <span class="info-value"><?= htmlspecialchars($user_data['full_name'] ?? '') ?></span>
                         </div>
                     </div>
                 </div>

                 <!-- Edit form (hidden by default) -->
                 <div id="profile-form" class="profile-form-container" style="display: none;">
                     <form method="POST" class="profile-form">
                         <div class="form-group">
                             <label for="full_name">Full Name</label>
                             <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($user_data['full_name'] ?? '') ?>" autocomplete="off" required>
                         </div>

                         <div class="form-actions">
                             <button type="submit" name="update_profile" class="btn btn-primary">
                                 <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512"><path d="M471.6 21.7c-21.9-21.9-57.3-21.9-79.2 0L362.3 51.7l97.9 97.9 30.1-30.1c21.9-21.9 21.9-57.3 0-79.2L471.6 21.7zm-299.2 220c-6.1 6.1-10.8 13.6-13.5 21.9l-29.6 88.8c-2.9 8.6-.6 18.1 5.8 24.6s15.9 8.7 24.6 5.8l88.8-29.6c8.2-2.7 15.7-7.4 21.9-13.5L437.7 172.3 339.7 74.3 172.4 241.7zM96 64C43 64 0 107 0 160V416c0 53 43 96 96 96H352c53 0 96-43 96-96V320c0-17.7-14.3-32-32-32s-32 14.3-32 32v96c0 17.7-14.3 32-32 32H96c-17.7 0-32-14.3-32-32V160c0-17.7 14.3-32 32-32h96c17.3 0 32-14.3 32-32s-14.3-32-32-32H96z"/></svg>
                                 <span>Save Changes</span>
                             </button>
                         </div>
                     </form>
                 </div>
            </div>

        </div>

        <!-- Account Information Panel -->
        <div class="panel-grid">
            <div class="info-panel">
                <div class="info-panel-header account">
                    <i class='bx bx-user-circle'></i>
                    <h3>Account Information</h3>
                </div>
                <div class="info-panel-content">
                    <div class="info-panel-item">
                        <div class="info-panel-label">Account ID</div>
                        <div class="info-panel-value"><?= $user_data['id'] ?? 'N/A' ?></div>
                    </div>
                    <div class="info-panel-item">
                        <div class="info-panel-label">Role</div>
                        <div class="info-panel-value">
                            <span class="status-badge status-success">
                                <?= ucfirst($user_data['role'] ?? 'Unknown') ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-panel-item">
                        <div class="info-panel-label">Registration Date</div>
                        <div class="info-panel-value"><?= isset($user_data['created_at']) ? date('F j, Y \a\t g:i A', strtotime($user_data['created_at'])) : 'N/A' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity Log Section -->
        <div class="section-card" style="margin-top: 2rem;">
            <div class="section-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h2>Activity Log</h2>
                    <p style="color: var(--secondary-text-clr); font-size: 0.9rem; margin-top: 0.5rem;">Recent system activities and events</p>
                </div>
                <a href="activity_logs.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; text-decoration: none; white-space: nowrap;">
                    <svg xmlns="http://www.w3.org/2000/svg" height="18" width="18" viewBox="0 0 512 512">
                        <path fill="currentColor" d="M64 464l256 0 0-304-256 0 0 304zM64 96l256 0 0-48L64 48c-8.8 0-16 7.2-16 16l0 32zm336-48l0 48 48 0 0-32c0-8.8-7.2-16-16-16l-32 0zm48 304l0-256-48 0 0 256 48 0zm-48 80l32 0c8.8 0 16-7.2 16-16l0-32-48 0 0 48z"/>
                    </svg>
                    <span>View logs</span>
                </a>
            </div>
            <div class="activity-log-container">
                <?php
                // Check if activity_log table exists, create if not
                $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'activity_log'");
                if (!$table_check || mysqli_num_rows($table_check) == 0) {
                    // Create the table if it doesn't exist
                    $create_table_sql = "CREATE TABLE IF NOT EXISTS `activity_log` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `activity_type` varchar(100) NOT NULL,
                        `description` text NOT NULL,
                        `user_id` int(11) DEFAULT NULL,
                        `related_id` int(11) DEFAULT NULL,
                        `related_type` varchar(50) DEFAULT NULL,
                        `metadata` text DEFAULT NULL,
                        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `idx_activity_type` (`activity_type`),
                        KEY `idx_user_id` (`user_id`),
                        KEY `idx_created_at` (`created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                    
                    // Try to add foreign key constraint if users table exists
                    if (mysqli_query($conn, $create_table_sql)) {
                        // Add foreign key constraint separately (may fail if users table doesn't exist, but that's okay)
                        $fk_check = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
                        if ($fk_check && mysqli_num_rows($fk_check) > 0) {
                            // Check if foreign key already exists
                            $fk_exists = mysqli_query($conn, "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND CONSTRAINT_NAME = 'fk_activity_log_user'");
                            if (!$fk_exists || mysqli_num_rows($fk_exists) == 0) {
                                @mysqli_query($conn, "ALTER TABLE activity_log ADD CONSTRAINT fk_activity_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
                            }
                        }
                    }
                }
                
                // Fetch recent activity logs
                $activity_query = "SELECT al.*, u.full_name as user_name 
                                  FROM activity_log al 
                                  LEFT JOIN users u ON al.user_id = u.id 
                                  ORDER BY al.created_at DESC 
                                  LIMIT 50";
                $activity_result = mysqli_query($conn, $activity_query);
                
                if ($activity_result && mysqli_num_rows($activity_result) > 0):
                ?>
                    <div class="activity-log-list">
                        <?php while ($activity = mysqli_fetch_assoc($activity_result)): 
                            // Get activity icon based on type
                            $icon = 'bx-info-circle';
                            $icon_color = 'var(--accent)';
                            switch($activity['activity_type']) {
                                case 'payment_transaction':
                                    $icon = 'bx-credit-card';
                                    $icon_color = '#28a745';
                                    break;
                                case 'new_member':
                                    $icon = 'bx-user-plus';
                                    $icon_color = '#17a2b8';
                                    break;
                                case 'new_user_account':
                                    $icon = 'bx-user-circle';
                                    $icon_color = '#6f42c1';
                                    break;
                                case 'new_coach_scheduling':
                                    $icon = 'bx-calendar-plus';
                                    $icon_color = '#ffc107';
                                    break;
                                case 'deleted_coach_schedule':
                                    $icon = 'bx-calendar-x';
                                    $icon_color = '#dc3545';
                                    break;
                                case 'attendance_recording':
                                    $icon = 'bx-check-circle';
                                    $icon_color = '#17a2b8';
                                    break;
                                case 'processed_membership':
                                    $icon = 'bx-check-square';
                                    $icon_color = '#28a745';
                                    break;
                                case 'walkin_recording':
                                    $icon = 'bx-walk';
                                    $icon_color = '#17a2b8';
                                    break;
                            }
                            
                            // Format timestamp in Philippine Time
                            // Ensure we're using Philippine Time for display
                            $original_timezone = date_default_timezone_get();
                            date_default_timezone_set('Asia/Manila');
                            
                            // Parse the timestamp and format it
                            $timestamp = strtotime($activity['created_at']);
                            $activity_time = date('F j, Y \a\t g:i A', $timestamp);
                            
                            // Restore original timezone
                            date_default_timezone_set($original_timezone);
                            $performed_by = $activity['user_name'] ? "by {$activity['user_name']}" : "by System";
                        ?>
                            <div class="activity-log-item">
                                <div class="activity-icon" style="background: <?= $icon_color ?>20; color: <?= $icon_color ?>;">
                                    <i class='bx <?= $icon ?>'></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-description"><?= htmlspecialchars($activity['description']) ?></div>
                                    <div class="activity-meta">
                                        <span class="activity-time"><?= $activity_time ?></span>
                                        <span class="activity-separator">•</span>
                                        <span class="activity-user"><?= $performed_by ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="activity-log-empty">
                        <i class='bx bx-info-circle' style="font-size: 3rem; color: var(--secondary-text-clr); margin-bottom: 1rem;"></i>
                        <p style="color: var(--secondary-text-clr);">No activity logs available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });

            // Profile edit functionality
            const profileDisplay = document.getElementById('profile-display');
            const profileForm = document.getElementById('profile-form');
            const editBtn = document.getElementById('edit-profile-btn');
            
            // Function to toggle edit mode
            function toggleEditMode() {
                if (!profileDisplay || !profileForm) {
                    console.error('Profile elements not found');
                    return;
                }
                
                if (profileForm.style.display === 'none' || !profileForm.style.display) {
                    // Show form, hide display
                    profileDisplay.style.display = 'none';
                    profileForm.style.display = 'block';
                    
                    // Change Edit button to Close button
                    if (editBtn) {
                        editBtn.innerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512">
                                <path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM175 175c9.4-9.4 24.6-9.4 33.9 0l47 47 47-47c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9l-47 47 47 47c9.4 9.4 9.4 24.6 0 33.9s-24.6 9.4-33.9 0l-47-47-47 47c-9.4 9.4-24.6 9.4-33.9 0s-9.4-24.6 0-33.9l47-47-47-47c-9.4-9.4-9.4-24.6 0-33.9z"/>
                            </svg>
                            <span>Close</span>
                        `;
                        editBtn.id = 'close-edit-btn';
                    }
                } else {
                    // Show display, hide form
                    profileForm.style.display = 'none';
                    profileDisplay.style.display = 'block';
                    
                    // Change Close button back to Edit button
                    const closeBtn = document.getElementById('close-edit-btn');
                    if (closeBtn) {
                        closeBtn.innerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512">
                                <path d="M471.6 21.7c-21.9-21.9-57.3-21.9-79.2 0L362.3 51.7l97.9 97.9 30.1-30.1c21.9-21.9 21.9-57.3 0-79.2L471.6 21.7zm-299.2 220c-6.1 6.1-10.8 13.6-13.5 21.9l-29.6 88.8c-2.9 8.6-.6 18.1 5.8 24.6s15.9 8.7 24.6 5.8l88.8-29.6c8.2-2.7 15.7-7.4 21.9-13.5L437.7 172.3 339.7 74.3 172.4 241.7zM96 64C43 64 0 107 0 160V416c0 53 43 96 96 96H352c53 0 96-43 96-96V320c0-17.7-14.3-32-32-32s-32 14.3-32 32v96c0 17.7-14.3 32-32 32H96c-17.7 0-32-14.3-32-32V160c0-17.7 14.3-32 32-32h96c17.3 0 32-14.3 32-32s-14.3-32-32-32H96z"/>
                            </svg>
                            <span>Edit</span>
                        `;
                        closeBtn.id = 'edit-profile-btn';
                    }
                }
            }
            
            // Attach direct event listener to edit button
            if (editBtn) {
                editBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleEditMode();
                });
            }
            
            // Also use event delegation as fallback
            document.addEventListener('click', function(e) {
                if (e.target.closest('#edit-profile-btn')) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleEditMode();
                } else if (e.target.closest('#close-edit-btn')) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleEditMode();
                }
            });

            // Form validation
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!form.checkValidity()) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    form.classList.add('was-validated');
                });
            });
        });

        // Profile photo preview functionality
        function previewPhoto(input) {
            const preview = document.getElementById('photo-preview');
            const actions = document.getElementById('photo-actions');
            const file = input.files[0];
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                    actions.style.display = 'flex';
                };
                reader.readAsDataURL(file);
            }
        }

        function cancelPhotoUpload() {
            const input = document.getElementById('profile-photo-input');
            const preview = document.getElementById('photo-preview');
            const actions = document.getElementById('photo-actions');
            
            input.value = '';
            preview.src = '';
            preview.style.display = 'none';
            actions.style.display = 'none';
        }
    </script>

</body>
</html>
