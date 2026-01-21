<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

include '../config.php';

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: walkin__log.php');
    exit;
}

// Get and sanitize form data
$walkin_id = intval($_POST['walkin_id'] ?? 0);
$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$membership_plan = trim($_POST['membership_plan'] ?? '');

// Validate required fields
if (empty($walkin_id) || empty($email) || empty($password) || empty($phone) || empty($membership_plan)) {
    $_SESSION['error'] = "Please fill in all required fields.";
    header('Location: walkin__log.php');
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = "Please enter a valid email address.";
    header('Location: walkin__log.php');
    exit;
}

// Check if email already exists (case-insensitive comparison)
$email_check = $conn->prepare("SELECT id, full_name FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
$email_check->bind_param("s", $email);
$email_check->execute();
$email_result = $email_check->get_result();

if ($email_result->num_rows > 0) {
    $existing_user = $email_result->fetch_assoc();
    $_SESSION['error'] = "Email address already exists for user: " . htmlspecialchars($existing_user['full_name']) . ". Please use a different email address.";
    header('Location: walkin__log.php');
    exit;
}

// Check if phone already exists
$phone_check = $conn->prepare("SELECT id, full_name FROM users u JOIN members m ON u.id = m.user_id WHERE m.phone = ?");
$phone_check->bind_param("s", $phone);
$phone_check->execute();
$phone_result = $phone_check->get_result();

if ($phone_result->num_rows > 0) {
    $existing_member = $phone_result->fetch_assoc();
    $_SESSION['error'] = "Phone number already exists for user: " . htmlspecialchars($existing_member['full_name']) . ". Please use a different phone number.";
    header('Location: walkin__log.php');
    exit;
}

// Get walk-in data
$walkin_query = $conn->prepare("SELECT name, package, amount, date FROM walk_in_log WHERE id = ?");
$walkin_query->bind_param("i", $walkin_id);
$walkin_query->execute();
$walkin_result = $walkin_query->get_result();
$walkin_data = $walkin_result->fetch_assoc();

if (!$walkin_data) {
    $_SESSION['error'] = "Walk-in record not found.";
    header('Location: walkin__log.php');
    exit;
}

// Store conversion data in session
$_SESSION['temp_walkin_data'] = [
    'full_name' => $walkin_data['name'],
    'email' => $email,
    'password' => $password,
    'password_plain' => $password,
    'phone' => $phone,
    'address' => $address,
    'membership_plan' => $membership_plan
];

$_SESSION['temp_walkin_id'] = $walkin_id;
$_SESSION['walkin_otp_email'] = $email; // Required for OTP verification page

// Debug: Log session data
error_log('Session data set: ' . print_r($_SESSION['temp_walkin_data'], true));

// Send initial OTP
require_once 'walkin_otp_handler.php';

// Generate and send OTP
$otp = sprintf('%06d', mt_rand(100000, 999999));
$otp_expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

// Store OTP in database
$otp_insert = $conn->prepare("INSERT INTO walkin_otp_verification (email, otp_code, expires_at, is_used) VALUES (?, ?, ?, 0)");
$otp_insert->bind_param("sss", $email, $otp, $otp_expires);
$otp_insert->execute();
$otp_insert->close();

// Send OTP email
if (sendWalkinOTPEmail($email, $otp, $walkin_data['name'])) {
    $_SESSION['success'] = 'OTP sent to your email address. Please check your inbox.';
} else {
    $_SESSION['error'] = 'Failed to send OTP. Please try again.';
}

// Redirect to OTP verification page
header('Location: walkin_otp_verification.php');
exit;
?>
