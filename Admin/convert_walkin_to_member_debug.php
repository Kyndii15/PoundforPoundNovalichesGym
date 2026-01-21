<?php
include '../config.php';

// Set Philippine timezone
date_default_timezone_set('Asia/Manila');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$error = '';
$success = '';
$debug_info = [];

// Log all POST data for debugging
$debug_info['post_data'] = $_POST;
$debug_info['request_method'] = $_SERVER['REQUEST_METHOD'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert') {
    $walkin_id = intval($_POST['walkin_id']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $membership_plan = trim($_POST['membership_plan']);
    $payment_method = trim($_POST['payment_method']);
    
    $debug_info['processed_data'] = [
        'walkin_id' => $walkin_id,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'membership_plan' => $membership_plan,
        'payment_method' => $payment_method
    ];
    
    // Validate required fields
    if (empty($email) || empty($membership_plan) || empty($payment_method)) {
        $error = "Please fill in all required fields.";
        $debug_info['validation_error'] = "Missing required fields";
    } else {
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
            $debug_info['validation_error'] = "Invalid email format";
        } else {
            // Check if email already exists
            $email_check = $conn->prepare("SELECT id, full_name FROM users WHERE email = ?");
            if (!$email_check) {
                $error = "Database error: " . $conn->error;
                $debug_info['db_error'] = $conn->error;
            } else {
                $email_check->bind_param("s", $email);
                $email_check->execute();
                $email_result = $email_check->get_result();
                
                if ($email_result->num_rows > 0) {
                    $existing_user = $email_result->fetch_assoc();
                    $error = "Email address already exists for user: " . htmlspecialchars($existing_user['full_name']) . ". Please use a different email address.";
                    $debug_info['duplicate_email'] = $existing_user;
                } else {
                    // Get walk-in data
                    $walkin_query = $conn->prepare("SELECT name, package, amount, date FROM walk_in_log WHERE id = ?");
                    if (!$walkin_query) {
                        $error = "Database error: " . $conn->error;
                        $debug_info['db_error'] = $conn->error;
                    } else {
                        $walkin_query->bind_param("i", $walkin_id);
                        $walkin_query->execute();
                        $walkin_result = $walkin_query->get_result();
                        $walkin_data = $walkin_result->fetch_assoc();
                        
                        $debug_info['walkin_data'] = $walkin_data;
                        
                        if ($walkin_data) {
                            // Start transaction
                            $conn->begin_transaction();
                            
                            try {
                                // Generate a temporary password
                                $temp_password = generateTempPassword();
                                $password_hash = password_hash($temp_password, PASSWORD_DEFAULT);
                                
                                $debug_info['temp_password'] = $temp_password;
                                
                                // Create user account
                                $user_stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role, created_at) VALUES (?, ?, ?, 'customer', NOW())");
                                if (!$user_stmt) {
                                    throw new Exception("User creation failed: " . $conn->error);
                                }
                                $user_stmt->bind_param("sss", $walkin_data['name'], $email, $password_hash);
                                $user_stmt->execute();
                                $new_user_id = $conn->insert_id;
                                
                                $debug_info['new_user_id'] = $new_user_id;
                                
                                // Create member record
                                $member_stmt = $conn->prepare("INSERT INTO members (user_id, phone, address, joined_at) VALUES (?, ?, ?, NOW())");
                                if (!$member_stmt) {
                                    throw new Exception("Member creation failed: " . $conn->error);
                                }
                                $member_stmt->bind_param("iss", $new_user_id, $phone, $address);
                                $member_stmt->execute();
                                $new_member_id = $conn->insert_id;
                                
                                $debug_info['new_member_id'] = $new_member_id;
                                
                                // Create subscription
                                $subscription_stmt = $conn->prepare("INSERT INTO subscriptions (member_id, plan_name, start_date, expiry_date, status) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), 'active')");
                                
                                // Calculate duration based on plan name
                                $duration_months = 1; // default
                                if (strpos($membership_plan, '2 Months') !== false) $duration_months = 2;
                                elseif (strpos($membership_plan, '3 Months') !== false) $duration_months = 3;
                                elseif (strpos($membership_plan, '6 Months') !== false) $duration_months = 6;
                                elseif (strpos($membership_plan, '1 Year') !== false) $duration_months = 12;
                                
                                $debug_info['duration_months'] = $duration_months;
                                
                                if (!$subscription_stmt) {
                                    throw new Exception("Subscription creation failed: " . $conn->error);
                                }
                                $subscription_stmt->bind_param("isi", $new_member_id, $membership_plan, $duration_months);
                                $subscription_stmt->execute();
                                
                                // Create transaction record
                                $transaction_stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, date, description, status, payment_method, plan_name, customer_name) VALUES (?, 'membership', ?, NOW(), 'Walk-in conversion to member', 'completed', ?, ?, ?)");
                                if (!$transaction_stmt) {
                                    throw new Exception("Transaction creation failed: " . $conn->error);
                                }
                                $transaction_stmt->bind_param("issss", $new_user_id, $walkin_data['amount'], $payment_method, $membership_plan, $walkin_data['name']);
                                $transaction_stmt->execute();
                                
                                // Mark walk-in as converted
                                $update_walkin = $conn->prepare("UPDATE walk_in_log SET status = 'Converted' WHERE id = ?");
                                if (!$update_walkin) {
                                    throw new Exception("Walk-in update failed: " . $conn->error);
                                }
                                $update_walkin->bind_param("i", $walkin_id);
                                $update_walkin->execute();
                                
                                // Commit transaction
                                $conn->commit();
                                
                                $debug_info['transaction_committed'] = true;
                                
                                // Always send email with credentials
                                $email_sent = sendMemberCredentials($email, $walkin_data['name'], $temp_password, $membership_plan);
                                $debug_info['email_sent'] = $email_sent;
                                
                                if ($email_sent) {
                                    $success = "Walk-in customer successfully converted to member! Login credentials have been sent to " . htmlspecialchars($email) . ".";
                                } else {
                                    $success = "Walk-in customer successfully converted to member! However, email could not be sent to " . htmlspecialchars($email) . ". Please provide login credentials manually.";
                                }
                                
                            } catch (Exception $e) {
                                // Rollback transaction on error
                                $conn->rollback();
                                $error = "Failed to convert walk-in to member: " . $e->getMessage();
                                $debug_info['exception'] = $e->getMessage();
                            }
                        } else {
                            $error = "Walk-in record not found.";
                            $debug_info['walkin_not_found'] = true;
                        }
                    }
                }
            }
        }
    }
}

// Function to generate temporary password
function generateTempPassword($length = 8) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

// Function to send member credentials via email
function sendMemberCredentials($email, $name, $password, $membership_plan) {
    $subject = "Welcome to Pound for Pound Gym - Your Member Account";
    $message = "
    <html>
    <head>
        <title>Welcome to Pound for Pound Gym</title>
    </head>
    <body>
        <h2>Welcome to Pound for Pound Gym, " . htmlspecialchars($name) . "!</h2>
        <p>Your membership has been successfully activated. Here are your login credentials:</p>
        <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
        <p><strong>Temporary Password:</strong> " . htmlspecialchars($password) . "</p>
        <p><strong>Membership Plan:</strong> " . htmlspecialchars($membership_plan) . "</p>
        <p>Please log in to your account and change your password for security purposes.</p>
        <p>Thank you for choosing Pound for Pound Gym!</p>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Pound for Pound Gym <noreply@poundforpoundgym.com>" . "\r\n";
    
    return mail($email, $subject, $message, $headers);
}

// Display debug information
echo "<h2>Debug Information</h2>";
echo "<pre>";
print_r($debug_info);
echo "</pre>";

if ($error) {
    echo "<h3>Error: " . htmlspecialchars($error) . "</h3>";
} elseif ($success) {
    echo "<h3>Success: " . htmlspecialchars($success) . "</h3>";
}

// Don't redirect in debug mode
// header("Location: walkin__log.php");
?>




