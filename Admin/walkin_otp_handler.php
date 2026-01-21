<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

require '../config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Function to send OTP email using SMTP
function sendWalkinOTPEmail($email, $otp, $name) {
    try {
        require '../vendor/autoload.php';
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'uratacandy@gmail.com';
        $mail->Password = 'zbwy nymk zsli wtsg';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('uratacandy@gmail.com', 'Pound for Pound');
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Walk-in Conversion Verification Code';
        $mail->Body = "
            <h2>Walk-in Conversion Verification</h2>
            <p>Hello $name,</p>
            <p>Your walk-in conversion verification code is: <strong>$otp</strong></p>
            <p>Please enter this code to complete your walk-in to member conversion.</p>
            <p>If you didn't request this, please contact our support team.</p>
            <p>Best regards,<br>Pound for Pound Team</p>
        ";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Walk-in OTP Email sending failed: " . $e->getMessage());
        return false;
    }
}

// Function to clean expired OTPs
function cleanExpiredWalkinOTPs($conn) {
    $table_check = "SHOW TABLES LIKE 'walkin_otp_verification'";
    $table_result = mysqli_query($conn, $table_check);
    
    if ($table_result && mysqli_num_rows($table_result) > 0) {
        $query = "DELETE FROM walkin_otp_verification WHERE expires_at < NOW()";
        mysqli_query($conn, $query);
    }
}

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'redirect' => ''
];

// Handle OTP generation and sending for walk-in conversion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_walkin_otp'])) {
    // Debug: Log that we're processing the request
    error_log('Walk-in OTP Handler: Processing create_walkin_otp request');
    
    // Get and sanitize input
    $name = trim($_POST['full_name'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $membership_plan = trim($_POST['membership_plan'] ?? '');
    $walkin_id = intval($_POST['walkin_id'] ?? 0);
    
    // Validate input
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Full Name is required.";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    } else {
        // Check if email domain exists
        $domain = substr(strrchr($email, "@"), 1);
        if (!checkdnsrr($domain, "MX")) {
            $errors[] = "The email you entered is invalid. Please check your email address and try again.";
        }
    }
    
    if (empty($password) || strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    
    if (empty($phone) || !preg_match('/^09[0-9]{9}$/', $phone)) {
        $errors[] = "Please enter a valid 11-digit phone number starting with 09.";
    }
    
    if (empty($membership_plan)) {
        $errors[] = "Please select a membership plan.";
    }
    
    if ($walkin_id <= 0) {
        $errors[] = "Invalid walk-in ID.";
    }
    
    if (!empty($errors)) {
        $response['message'] = implode(' ', $errors);
    } else {
        // Check if email already exists
        $check_email = "SELECT id FROM users WHERE email = ?";
        if ($check_stmt = mysqli_prepare($conn, $check_email)) {
            mysqli_stmt_bind_param($check_stmt, 's', $email);
            mysqli_stmt_execute($check_stmt);
            $result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($result) > 0) {
                $response['message'] = 'This email is already registered. Please use a different email address.';
            } else {
                // Generate OTP
                $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                
                // Clean expired OTPs
                cleanExpiredWalkinOTPs($conn);
                
                // Store OTP in database
                $otp_query = "INSERT INTO walkin_otp_verification (email, otp_code, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW())";
                if ($otp_stmt = mysqli_prepare($conn, $otp_query)) {
                    mysqli_stmt_bind_param($otp_stmt, 'ss', $email, $otp);
                    
                    if (mysqli_stmt_execute($otp_stmt)) {
                        // Store walk-in data in session for later use
                        $_SESSION['temp_walkin_data'] = [
                            'full_name' => $name,
                            'email' => $email,
                            'password' => password_hash($password, PASSWORD_DEFAULT),
                            'password_plain' => $password, // Store plain password for POS form
                            'phone' => $phone,
                            'address' => $address,
                            'membership_plan' => $membership_plan,
                            'walkin_id' => $walkin_id
                        ];
                        $_SESSION['walkin_otp_email'] = $email;
                        
                        // Send OTP email
                        if (sendWalkinOTPEmail($email, $otp, $name)) {
                            $response = [
                                'success' => true,
                                'message' => 'Verification code sent to your email!',
                                'redirect' => 'walkin_otp_verification.php'
                            ];
                        } else {
                            $response['message'] = 'Failed to send verification email. Please try again.';
                        }
                    } else {
                        $response['message'] = 'Failed to generate verification code. Please try again.';
                    }
                    mysqli_stmt_close($otp_stmt);
                } else {
                    $response['message'] = 'Database error. Please try again.';
                }
            }
            mysqli_stmt_close($check_stmt);
        } else {
            $response['message'] = 'Database error. Please try again.';
        }
    }
    
    // Handle direct form submission (non-AJAX)
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        if ($response['success']) {
            $_SESSION['success'] = $response['message'];
            header('Location: ' . $response['redirect']);
        } else {
            $_SESSION['error'] = $response['message'];
            header('Location: walkin__log.php');
        }
        exit();
    }
    
    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_walkin_otp'])) {
    $otp_code = trim($_POST['otp_code'] ?? '');
    
    if (empty($otp_code)) {
        $_SESSION['error'] = 'Please enter the verification code.';
        header('Location: walkin_otp_verification.php');
        exit();
    }
    
    if (!isset($_SESSION['walkin_otp_email'])) {
        $_SESSION['error'] = 'No verification session found. Please start the process again.';
        header('Location: walkin__log.php');
        exit();
    }
    
    $email = $_SESSION['walkin_otp_email'];
    
    // Verify OTP
    $verify_query = "SELECT * FROM walkin_otp_verification 
                    WHERE email = ? AND otp_code = ? AND is_used = 0 AND expires_at > NOW() 
                    ORDER BY created_at DESC LIMIT 1";
    
    if ($verify_stmt = mysqli_prepare($conn, $verify_query)) {
        mysqli_stmt_bind_param($verify_stmt, 'ss', $email, $otp_code);
        mysqli_stmt_execute($verify_stmt);
        $verify_result = mysqli_stmt_get_result($verify_stmt);
        
        if (mysqli_num_rows($verify_result) > 0) {
            // OTP is valid - mark as used
            $otp_row = mysqli_fetch_assoc($verify_result);
            $update_otp = "UPDATE walkin_otp_verification SET is_used = 1 WHERE id = ?";
            if ($update_stmt = mysqli_prepare($conn, $update_otp)) {
                mysqli_stmt_bind_param($update_stmt, 'i', $otp_row['id']);
                mysqli_stmt_execute($update_stmt);
                mysqli_stmt_close($update_stmt);
            }
            
            // Get walk-in data from session
            $walkin_data = $_SESSION['temp_walkin_data'] ?? null;
            
            if ($walkin_data) {
                // Preserve existing plain password unless a new one is explicitly provided
                if (!empty($_POST['password_plain'])) {
                    $_SESSION['temp_walkin_data']['password_plain'] = $_POST['password_plain'];
                }
                
                // Clear OTP email session but keep walk-in data
                unset($_SESSION['walkin_otp_email']);
                
                // Redirect to POS payment form
                $_SESSION['success'] = 'Email verified successfully! Please complete your payment.';
                header('Location: walkin_pos_payment.php');
                exit();
            } else {
                $_SESSION['error'] = 'Walk-in data not found. Please start the process again.';
                header('Location: walkin__log.php');
                exit();
            }
        } else {
            $_SESSION['error'] = 'Invalid or expired verification code.';
            header('Location: walkin_otp_verification.php');
            exit();
        }
        mysqli_stmt_close($verify_stmt);
    } else {
        $_SESSION['error'] = 'Database error. Please try again.';
        header('Location: walkin_otp_verification.php');
        exit();
    }
}

// Handle resend OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_walkin_otp'])) {
    if (!isset($_SESSION['walkin_otp_email'])) {
        $response['message'] = 'No verification session found.';
    } else {
        $email = $_SESSION['walkin_otp_email'];
        $name = $_SESSION['temp_walkin_data']['full_name'] ?? 'User';
        
        // Generate new OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Clean expired OTPs
        cleanExpiredWalkinOTPs($conn);
        
        // Store new OTP
        $otp_query = "INSERT INTO walkin_otp_verification (email, otp_code, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW())";
        if ($otp_stmt = mysqli_prepare($conn, $otp_query)) {
            mysqli_stmt_bind_param($otp_stmt, 'ss', $email, $otp);
            
            if (mysqli_stmt_execute($otp_stmt)) {
                if (sendWalkinOTPEmail($email, $otp, $name)) {
                    $response = [
                        'success' => true,
                        'message' => 'New verification code sent to your email!'
                    ];
                } else {
                    $response['message'] = 'Failed to send verification email. Please try again.';
                }
            } else {
                $response['message'] = 'Failed to generate new verification code. Please try again.';
            }
            mysqli_stmt_close($otp_stmt);
        } else {
            $response['message'] = 'Database error. Please try again.';
        }
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// If no valid action, redirect to walk-in log
header('Location: walkin__log.php');
exit();
?>
