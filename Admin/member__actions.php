<?php
session_start();
include '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? null;
    
    if ($action === 'create') {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $password = $_POST['password'];
        $membership_plan = $_POST['membership_plan'];
        
        // Validate required fields
        if (empty($full_name) || empty($email) || empty($phone) || empty($password) || empty($membership_plan)) {
            $_SESSION['error_message'] = 'All required fields must be filled.';
            header('Location: member__form.php');
            exit;
        }
        
        // Check if email already exists
        $check_email = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email'");
        if (mysqli_num_rows($check_email) > 0) {
            $_SESSION['error_message'] = 'Email address already exists.';
            header('Location: member__form.php');
            exit;
        }
        
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Store member data for OTP verification (don't create account yet)
        $_SESSION['temp_member_data'] = [
            'full_name' => $full_name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'password' => $hashed_password,
            'membership_plan' => $membership_plan
        ];
        $_SESSION['member_otp_email'] = $email;
        
        // Redirect to OTP verification
        header('Location: member_otp_verification.php');
        exit;
        
    } elseif ($action === 'update') {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        
        // Validate required fields
        if (empty($full_name) || empty($email) || empty($phone)) {
            $_SESSION['error_message'] = 'All required fields must be filled.';
            header('Location: member__form.php?id=' . $id);
            exit;
        }
        
        // Check if email already exists (excluding current user)
        $check_email = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email' AND id != $id");
        if (mysqli_num_rows($check_email) > 0) {
            $_SESSION['error_message'] = 'Email address already exists.';
            header('Location: member__form.php?id=' . $id);
            exit;
        }
        
        // Update user and member data
        $user_sql = "UPDATE users SET full_name = '$full_name', email = '$email' WHERE id = $id";
        $member_sql = "UPDATE members SET phone = '$phone', address = '$address' WHERE user_id = $id";
        
        if (mysqli_query($conn, $user_sql) && mysqli_query($conn, $member_sql)) {
            $_SESSION['success_message'] = 'Member updated successfully.';
            header('Location: members__management.php');
            exit;
        } else {
            $_SESSION['error_message'] = 'Failed to update member.';
            header('Location: member__form.php?id=' . $id);
            exit;
        }
    }
}

// Handle GET requests for other actions
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? null;

if ($action === 'archive' && $id) {
    $sql = "UPDATE users SET archived = 1 WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $_SESSION['success_message'] = 'Member archived successfully.';
    } else {
        $_SESSION['error_message'] = 'Failed to archive member.';
    }
    header('Location: members__management.php');
    exit;
}

if ($action === 'unarchive' && $id) {
    $sql = "UPDATE users SET archived = 0 WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $_SESSION['success_message'] = 'Member unarchived successfully.';
    } else {
        $_SESSION['error_message'] = 'Failed to unarchive member.';
    }
    header('Location: members__management.php');
    exit;
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['bulk_archive', 'bulk_unarchive'])) {
    $user_ids = $_POST['user_ids'] ?? '';
    $action = $_POST['action'];
    
    if (!empty($user_ids)) {
        $ids_array = explode(',', $user_ids);
        $archived_value = ($action === 'bulk_archive') ? 1 : 0;
        
        foreach ($ids_array as $user_id) {
            $user_id = intval($user_id);
            if ($user_id > 0) {
                $sql = "UPDATE users SET archived = $archived_value WHERE id = $user_id";
                mysqli_query($conn, $sql);
            }
        }
        
        $action_text = ($action === 'bulk_archive') ? 'archived' : 'unarchived';
        $_SESSION['success_message'] = 'Selected members ' . $action_text . ' successfully.';
    }
    
    header('Location: members__management.php');
    exit;
}

header('Location: members__management.php');
exit;
?>

