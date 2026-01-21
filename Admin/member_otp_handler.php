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
function sendMemberOTPEmail($email, $otp, $name) {
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
        $mail->Subject = 'Member Account Verification Code';
        $mail->Body = "
            <h2>Member Account Verification</h2>
            <p>Hello $name,</p>
            <p>Your member account verification code is: <strong>$otp</strong></p>
            <p>Please enter this code to complete your member account setup.</p>
            <p>If you didn't request this, please contact our support team.</p>
            <p>Best regards,<br>Pound for Pound Team</p>
        ";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Member OTP Email sending failed: " . $e->getMessage());
        return false;
    }
}

// Function to clean expired OTPs
function cleanExpiredMemberOTPs($conn) {
    $table_check = "SHOW TABLES LIKE 'member_otp_verification'";
    $table_result = mysqli_query($conn, $table_check);
    
    if ($table_result && mysqli_num_rows($table_result) > 0) {
        $query = "DELETE FROM member_otp_verification WHERE expires_at < NOW()";
        mysqli_query($conn, $query);
    }
}

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'redirect' => ''
];

// Handle member update (no OTP required for updates)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_member'])) {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    if ($id > 0 && !empty($name)) {
        $update_query = "UPDATE users SET full_name = ? WHERE id = ?";
        if ($update_stmt = mysqli_prepare($conn, $update_query)) {
            mysqli_stmt_bind_param($update_stmt, 'si', $name, $id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                // Update member details
                $update_member_query = "UPDATE members SET phone = ?, address = ? WHERE user_id = ?";
                if ($update_member_stmt = mysqli_prepare($conn, $update_member_query)) {
                    mysqli_stmt_bind_param($update_member_stmt, 'ssi', $phone, $address, $id);
                    mysqli_stmt_execute($update_member_stmt);
                    mysqli_stmt_close($update_member_stmt);
                }
                
                $response = [
                    'success' => true,
                    'message' => 'Member updated successfully!',
                    'redirect' => 'members__management.php'
                ];
            } else {
                $response['message'] = 'Failed to update member. Please try again.';
            }
            mysqli_stmt_close($update_stmt);
        } else {
            $response['message'] = 'Database error. Please try again.';
        }
    } else {
        $response['message'] = 'Invalid data provided.';
    }
    
    // Handle direct form submission (non-AJAX)
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        if ($response['success']) {
            $_SESSION['success'] = $response['message'];
        } else {
            $_SESSION['error'] = $response['message'];
        }
        header('Location: ' . $response['redirect']);
        exit();
    }
    
    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle OTP generation and sending for member creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_member_otp'])) {
    // Debug: Log that we're processing the request
    error_log('Member OTP Handler: Processing create_member_otp request');
    // Get and sanitize input
    $name = trim($_POST['full_name'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $membership_plan = trim($_POST['membership_plan'] ?? '');
    
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
    
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    
    if (empty($phone)) {
        $errors[] = "Phone number is required.";
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $errors[] = "Please enter a valid 11-digit Philippine mobile number starting with 09.";
    }
    
    if (empty($membership_plan)) {
        $errors[] = "Please select a membership plan.";
    }
    
    // Check if email already exists
    if (empty($errors)) {
        $check_email = "SELECT id FROM users WHERE email = ?";
        if ($check_stmt = mysqli_prepare($conn, $check_email)) {
            mysqli_stmt_bind_param($check_stmt, 's', $email);
            mysqli_stmt_execute($check_stmt);
            $result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($result) > 0) {
                $errors[] = "Email address already exists.";
            }
            mysqli_stmt_close($check_stmt);
        }
    }
    
    if (!empty($errors)) {
        $response['message'] = implode(' ', $errors);
    } else {
        try {
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Store member data for OTP verification
            $_SESSION['temp_member_data'] = [
                'full_name' => $name,
                'email' => $email,
                'password' => $hashedPassword,
                'plain_password' => $password,
                'phone' => $phone,
                'address' => $address,
                'membership_plan' => $membership_plan
            ];
            
            // Check if member_otp_verification table exists, if not create it
            $createTable = "CREATE TABLE IF NOT EXISTS `member_otp_verification` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `email` varchar(255) NOT NULL,
                `otp_code` varchar(10) NOT NULL,
                `member_data` text NOT NULL,
                `created_at` datetime NOT NULL,
                `expires_at` datetime NOT NULL,
                `is_used` tinyint(1) NOT NULL DEFAULT 0,
                `used_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `email` (`email`),
                KEY `otp_code` (`otp_code`),
                KEY `expires_at` (`expires_at`),
                KEY `is_used` (`is_used`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            if (!mysqli_query($conn, $createTable)) {
                throw new Exception("Failed to create member OTP verification table: " . mysqli_error($conn));
            }
            
            // Delete any existing OTPs for this email
            $delete_query = "DELETE FROM member_otp_verification WHERE email = ?";
            if ($delete_stmt = mysqli_prepare($conn, $delete_query)) {
                mysqli_stmt_bind_param($delete_stmt, 's', $email);
                if (!mysqli_stmt_execute($delete_stmt)) {
                    throw new Exception("Failed to clean up old OTPs: " . mysqli_stmt_error($delete_stmt));
                }
                mysqli_stmt_close($delete_stmt);
            } else {
                throw new Exception("Failed to prepare delete statement: " . mysqli_error($conn));
            }
            
            // Set OTP verification data
            $created_at = date('Y-m-d H:i:s');
            $is_used = 0;
            $used_at = null;
            
            // Generate OTP
            $otpCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $memberData = json_encode($_SESSION['temp_member_data']);
            
            // Insert new OTP
            $insert_query = "INSERT INTO member_otp_verification (email, otp_code, created_at, expires_at, is_used) 
                           VALUES (?, ?, ?, ?, ?)";
            
            if ($insert_stmt = mysqli_prepare($conn, $insert_query)) {
                mysqli_stmt_bind_param($insert_stmt, 'ssssi', $email, $otpCode, $created_at, $expiresAt, $is_used);
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    mysqli_stmt_close($insert_stmt);
                    
                    // Send OTP email
                    if (sendMemberOTPEmail($email, $otpCode, $name)) {
                        $_SESSION['member_otp_email'] = $email;
                        $response = [
                            'success' => true,
                            'message' => 'Verification code sent to your email!',
                            'redirect' => 'member_otp_verification.php'
                        ];
                    } else {
                        throw new Exception("Failed to send verification email. Please try again.");
                    }
                } else {
                    throw new Exception("Failed to store OTP: " . mysqli_stmt_error($insert_stmt));
                }
            } else {
                throw new Exception("Failed to prepare insert statement: " . mysqli_error($conn));
            }
        } catch (Exception $e) {
            error_log("Member OTP Handler Error: " . $e->getMessage());
            $response['message'] = $e->getMessage();
        }
    }
    
    // Handle direct form submission (non-AJAX)
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        if ($response['success']) {
            $_SESSION['success'] = $response['message'];
            header('Location: ' . $response['redirect']);
            exit();
        } else {
            $_SESSION['error'] = $response['message'];
            header('Location: member__form.php');
            exit();
        }
    }
    
    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle resend OTP
if (isset($_POST['resend_member_otp'])) {
    if (!isset($_SESSION['member_otp_email'])) {
        echo json_encode(['success' => false, 'message' => 'No email found in session.']);
        exit;
    }
    
    $email = $_SESSION['member_otp_email'];
    $member_data = $_SESSION['temp_member_data'];
    $full_name = $member_data['full_name'];
    
    // Generate new OTP
    $otpCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $created_at = date('Y-m-d H:i:s');
    $is_used = 0;
    
    // Delete old OTP for this email
    $delete_query = "DELETE FROM member_otp_verification WHERE email = ?";
    if ($delete_stmt = mysqli_prepare($conn, $delete_query)) {
        mysqli_stmt_bind_param($delete_stmt, 's', $email);
        mysqli_stmt_execute($delete_stmt);
        mysqli_stmt_close($delete_stmt);
    }
    
    // Insert new OTP
    $insert_query = "INSERT INTO member_otp_verification (email, otp_code, created_at, expires_at, is_used) 
                   VALUES (?, ?, ?, ?, ?)";
    
    if ($insert_stmt = mysqli_prepare($conn, $insert_query)) {
        mysqli_stmt_bind_param($insert_stmt, 'ssssi', $email, $otpCode, $created_at, $expiresAt, $is_used);
        
        if (mysqli_stmt_execute($insert_stmt)) {
            mysqli_stmt_close($insert_stmt);
            
            // Send OTP email
            if (sendMemberOTPEmail($email, $otpCode, $full_name)) {
                echo json_encode(['success' => true, 'message' => 'New verification code sent successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to send email. Please try again.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to generate new code. Please try again.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
    exit;
}

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_member_otp']) && isset($_POST['otp_code'])) {
    error_log("OTP Handler: Processing OTP verification");
    $otp_code = trim($_POST['otp_code']);
    $email = $_SESSION['member_otp_email'] ?? '';
    error_log("OTP Handler: OTP code: " . $otp_code);
    error_log("OTP Handler: Email: " . $email);
    
    if (empty($otp_code) || empty($email)) {
        $_SESSION['error'] = 'Invalid verification code.';
        header('Location: member_otp_verification.php');
        exit;
    }
    
    // Verify OTP
    $otp_query = "SELECT * FROM member_otp_verification 
                  WHERE email = ? AND otp_code = ? AND is_used = 0 AND expires_at > NOW() 
                  ORDER BY created_at DESC LIMIT 1";
    
    if ($otp_stmt = mysqli_prepare($conn, $otp_query)) {
        mysqli_stmt_bind_param($otp_stmt, 'ss', $email, $otp_code);
        mysqli_stmt_execute($otp_stmt);
        $otp_result = mysqli_stmt_get_result($otp_stmt);
        
        if (mysqli_num_rows($otp_result) > 0) {
            error_log("OTP Handler: OTP verification successful");
            // OTP is valid - mark as used
            $otp_row = mysqli_fetch_assoc($otp_result);
            $update_otp = "UPDATE member_otp_verification SET is_used = 1 WHERE id = " . $otp_row['id'];
            mysqli_query($conn, $update_otp);
            
            // Clear OTP session data
            unset($_SESSION['member_otp_email']);
            
            // OTP is verified - store member data for payment processing (DON'T create account yet)
            $member_data = $_SESSION['temp_member_data'];
            $full_name = $member_data['full_name'];
            $email = $member_data['email'];
            $phone = $member_data['phone'];
            $address = $member_data['address'];
            $password = $member_data['password'];
            $membership_plan = $member_data['membership_plan'];
            
            // Calculate expiry date based on membership plan
            $expiry_date = '';
            if (strpos($membership_plan, '1 Month') !== false) {
                $expiry_date = date('Y-m-d', strtotime('+1 month'));
            } elseif (strpos($membership_plan, '2 Months') !== false) {
                $expiry_date = date('Y-m-d', strtotime('+2 months'));
            } elseif (strpos($membership_plan, '3 Months') !== false) {
                $expiry_date = date('Y-m-d', strtotime('+3 months'));
            } elseif (strpos($membership_plan, '6 Months') !== false) {
                $expiry_date = date('Y-m-d', strtotime('+6 months'));
            } elseif (strpos($membership_plan, '1 Year') !== false) {
                $expiry_date = date('Y-m-d', strtotime('+1 year'));
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
            
            // Store member data for payment processing (account will be created AFTER payment)
            $_SESSION['member_payment_data'] = [
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'password' => $password, // This is the hashed password
                'password_plain' => $member_data['plain_password'], // This is the plain password
                'membership_plan' => $membership_plan,
                'price' => $price,
                'expiry_date' => $expiry_date
            ];
            unset($_SESSION['temp_member_data']);
            
            // Redirect to payment page
            error_log("OTP Handler: Redirecting to member_payment.php");
            header('Location: member_payment.php');
            exit;
        } else {
            $_SESSION['error'] = 'Invalid or expired verification code.';
        }
        mysqli_stmt_close($otp_stmt);
    } else {
        $_SESSION['error'] = 'Database error. Please try again.';
    }
    
    header('Location: member_otp_verification.php');
    exit;
}

// Handle OTP resend
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend') {
    $email = $_SESSION['member_otp_email'] ?? '';
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'No email address found.']);
        exit;
    }
    
    // Generate new OTP
    $otp_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    // Store OTP in database
    $otp_sql = "INSERT INTO member_otp_verification (email, otp_code, expires_at, created_at) 
                VALUES ('$email', '$otp_code', '$expires_at', NOW())";
    
    if (mysqli_query($conn, $otp_sql)) {
        // Send OTP email
        $member_data = $_SESSION['temp_member_data'];
        $full_name = $member_data['full_name'];
        
        if (sendMemberOTPEmail($email, $otp_code, $full_name)) {
            echo json_encode(['success' => true, 'message' => 'New verification code sent to your email!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send email. Please try again.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to generate new code. Please try again.']);
    }
    exit;
}

// Clean up expired OTPs
cleanExpiredMemberOTPs($conn);
?>
