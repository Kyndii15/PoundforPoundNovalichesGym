<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../config.php';

// Set Philippine timezone
date_default_timezone_set('Asia/Manila');

// Get current staff user ID from session
session_name('gym_admin_session');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$staff_id = $_SESSION['user_id'] ?? null;

// Set JSON response header
header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Debug: Log all incoming data
error_log("Walk-in conversion attempt: " . print_r($_POST, true));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert') {
    
    // Get and sanitize form data
    $walkin_id = intval($_POST['walkin_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $membership_plan = trim($_POST['membership_plan'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? '');
    
    // Get POS data (simplified)
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    
    error_log("Processing conversion for walkin_id: $walkin_id, email: $email");
    error_log("POS data - Total Amount: $total_amount");
    
    // Validate required fields
    if (empty($membership_plan) || empty($payment_method)) {
        $response['message'] = "Please fill in all required fields.";
        error_log("Validation failed: Missing required fields");
    } else {
        // Auto-generate email if not provided or if empty
        if (empty($email)) {
            // We'll generate the email later when we have the walk-in data
            $email = '';
            error_log("Email will be auto-generated after getting walk-in data");
        } else {
            // Validate email format if provided
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please enter a valid email address.";
                error_log("Validation failed: Invalid email format");
            }
        }
        
        // Auto-generate password if not provided or if empty
        if (empty($password)) {
            $password = generateTempPassword();
            error_log("Auto-generated password: $password");
        }
        
        if (empty($error)) {
            // Check if email already exists (case-insensitive comparison)
            $email_check = $conn->prepare("SELECT id, full_name FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
            if (!$email_check) {
                $error = "Database error: " . $conn->error;
                error_log("Database error: " . $conn->error);
            } else {
                $email_check->bind_param("s", $email);
                $email_check->execute();
                $email_result = $email_check->get_result();
                
                if ($email_result->num_rows > 0) {
                    // If auto-generated email exists, generate a new one
                    if (strpos($email, '@poundforpoundgym.com') !== false) {
                        $email = generateAutoEmail();
                        error_log("Regenerated email due to conflict: $email");
                    } else {
                        $existing_user = $email_result->fetch_assoc();
                        $error = "Email address already exists for user: " . htmlspecialchars($existing_user['full_name']) . ". Please use a different email address.";
                        error_log("Validation failed: Email already exists");
                    }
                }
            }
        }
        
        if (empty($error)) {
            // Get walk-in data
            $walkin_query = $conn->prepare("SELECT name, package, amount, date FROM walk_in_log WHERE id = ?");
            if (!$walkin_query) {
                $error = "Database error: " . $conn->error;
                error_log("Database error: " . $conn->error);
            } else {
                $walkin_query->bind_param("i", $walkin_id);
                $walkin_query->execute();
                $walkin_result = $walkin_query->get_result();
                $walkin_data = $walkin_result->fetch_assoc();
                
                error_log("Walk-in data retrieved: " . print_r($walkin_data, true));
                
                if ($walkin_data) {
                    // Auto-generate email if not provided
                    if (empty($email)) {
                        $email = generateAutoEmail($walkin_data['name']);
                        error_log("Auto-generated email: $email");
                    }
                    
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Use the password from form or auto-generated
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        
                        error_log("Using password: $password");
                        
                        // Create user account with email_verified = 1 for converted accounts
                        $user_stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, password_plain, role, email_verified, created_at) VALUES (?, ?, ?, ?, 'customer', 1, NOW())");
                        if (!$user_stmt) {
                            throw new Exception("User creation failed: " . $conn->error);
                        }
                        $user_stmt->bind_param("ssss", $walkin_data['name'], $email, $password_hash, $password);
                        if (!$user_stmt->execute()) {
                            throw new Exception("User creation execution failed: " . $user_stmt->error);
                        }
                        $new_user_id = $conn->insert_id;
                        
                        error_log("Created user with ID: $new_user_id");
                        
                        // Create member record
                        $member_stmt = $conn->prepare("INSERT INTO members (user_id, phone, address, joined_at) VALUES (?, ?, ?, NOW())");
                        if (!$member_stmt) {
                            throw new Exception("Member creation failed: " . $conn->error);
                        }
                        $member_stmt->bind_param("iss", $new_user_id, $phone, $address);
                        if (!$member_stmt->execute()) {
                            throw new Exception("Member creation execution failed: " . $member_stmt->error);
                        }
                        $new_member_id = $conn->insert_id;
                        
                        error_log("Created member with ID: $new_member_id");
                        
                        // Calculate duration and price based on plan name
                        $duration_months = 1; // default
                        $plan_price = 0;
                        
                        if (strpos($membership_plan, 'Package 1: Boxing') !== false) {
                            if (strpos($membership_plan, '1 Month') !== false) {
                                $duration_months = 1;
                                $plan_price = 2500;
                            } elseif (strpos($membership_plan, '2 Months') !== false) {
                                $duration_months = 2;
                                $plan_price = 4000;
                            } elseif (strpos($membership_plan, '3 Months') !== false) {
                                $duration_months = 3;
                                $plan_price = 6000;
                            }
                        } elseif (strpos($membership_plan, 'Package 2: Circuit Training') !== false) {
                            if (strpos($membership_plan, '1 Month') !== false) {
                                $duration_months = 1;
                                $plan_price = 1700;
                            } elseif (strpos($membership_plan, '3 Months') !== false) {
                                $duration_months = 3;
                                $plan_price = 2900;
                            } elseif (strpos($membership_plan, '6 Months') !== false) {
                                $duration_months = 6;
                                $plan_price = 5500;
                            } elseif (strpos($membership_plan, '1 Year') !== false) {
                                $duration_months = 12;
                                $plan_price = 9000;
                            }
                        } elseif (strpos($membership_plan, 'Package 3: Muay Thai') !== false) {
                            if (strpos($membership_plan, '1 Month') !== false) {
                                $duration_months = 1;
                                $plan_price = 3000;
                            } elseif (strpos($membership_plan, '2 Months') !== false) {
                                $duration_months = 2;
                                $plan_price = 5000;
                            } elseif (strpos($membership_plan, '3 Months') !== false) {
                                $duration_months = 3;
                                $plan_price = 7000;
                            }
                        }
                        
                        error_log("Duration calculated: $duration_months months, Plan price: $plan_price");
                        
                        // Create subscription
                        $subscription_stmt = $conn->prepare("INSERT INTO subscriptions (member_id, plan_name, start_date, expiry_date, status) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), 'active')");
                        if (!$subscription_stmt) {
                            throw new Exception("Subscription creation failed: " . $conn->error);
                        }
                        $subscription_stmt->bind_param("isi", $new_member_id, $membership_plan, $duration_months);
                        if (!$subscription_stmt->execute()) {
                            throw new Exception("Subscription creation execution failed: " . $subscription_stmt->error);
                        }
                        
                        error_log("Created subscription");
                        
                        // Create transaction record with the actual plan price
                        $transaction_stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, date, description, status, payment_method, plan_name, customer_name) VALUES (?, 'membership', ?, NOW(), 'Walk-in conversion to member', 'completed', ?, ?, ?)");
                        if (!$transaction_stmt) {
                            throw new Exception("Transaction creation failed: " . $conn->error);
                        }
                        $transaction_stmt->bind_param("issss", $new_user_id, $plan_price, $payment_method, $membership_plan, $walkin_data['name']);
                        if (!$transaction_stmt->execute()) {
                            throw new Exception("Transaction creation execution failed: " . $transaction_stmt->error);
                        }
                        
                        error_log("Created transaction record");
                        
                        // Record process history in pending_memberships table
                        $current_datetime = date('Y-m-d H:i:s');
                        $process_history_query = "INSERT INTO pending_memberships (user_id, plan_name, plan_price, payment_method, status, requested_at, processed_at, processed_by, notes) VALUES (?, ?, ?, ?, 'approved', ?, ?, ?, 'Walk-in converted to member')";
                        $process_history_stmt = $conn->prepare($process_history_query);
                        if ($process_history_stmt) {
                            $process_history_stmt->bind_param("isssssi", $new_user_id, $membership_plan, $plan_price, $payment_method, $current_datetime, $current_datetime, $staff_id);
                            $process_history_stmt->execute();
                            $process_history_stmt->close();
                            error_log("Process history recorded in pending_memberships");
                        }
                        
                        // Mark walk-in as converted
                        $update_walkin = $conn->prepare("UPDATE walk_in_log SET status = 'Converted' WHERE id = ?");
                        if (!$update_walkin) {
                            throw new Exception("Walk-in update failed: " . $conn->error);
                        }
                        $update_walkin->bind_param("i", $walkin_id);
                        if (!$update_walkin->execute()) {
                            throw new Exception("Walk-in update execution failed: " . $update_walkin->error);
                        }
                        
                        error_log("Updated walk-in status to Converted");
                        
                        // Commit transaction
                        $conn->commit();
                        
                        error_log("Transaction committed successfully");
                        
                        // Try to send email with credentials (optional)
                        $email_sent = false;
                        if (strpos($email, '@poundforpoundgym.com') === false) {
                            // Only send email if it's a real email address
                            $email_sent = sendMemberCredentials($email, $walkin_data['name'], $password, $membership_plan);
                            error_log("Email sent: " . ($email_sent ? 'Yes' : 'No'));
                        }
                        
                        // Create success message with credentials
                        $response['success'] = true;
                        $response['message'] = "Walk-in customer successfully converted to member!";
                        $response['credentials'] = [
                            'email' => $email,
                            'password' => $password,
                            'membership_plan' => $membership_plan,
                            'amount' => $plan_price,
                            'payment_method' => $payment_method,
                            'email_sent' => $email_sent
                        ];
                        
                    } catch (Exception $e) {
                        // Rollback transaction on error
                        $conn->rollback();
                        $response['message'] = "Failed to convert walk-in to member: " . $e->getMessage();
                        error_log("Conversion failed: " . $e->getMessage());
                    }
                } else {
                    $response['message'] = "Walk-in record not found.";
                    error_log("Walk-in record not found for ID: $walkin_id");
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

// Function to generate auto email
function generateAutoEmail($customerName = '') {
    if (!empty($customerName)) {
        // Clean the name: remove spaces, convert to lowercase, take first name only
        $cleanName = strtolower(preg_replace('/[^a-zA-Z]/', '', explode(' ', $customerName)[0]));
        // Generate 6-digit random number
        $randomNumber = rand(100000, 999999);
        return $cleanName . '.' . $randomNumber . '@poundforpound.com';
    } else {
        // Fallback if no customer name
        $randomNumber = rand(100000, 999999);
        return 'member.' . $randomNumber . '@poundforpound.com';
    }
}

// Function to send member credentials via email
function sendMemberCredentials($email, $name, $password, $membership_plan) {
    $subject = "Welcome to Pound for Pound Gym - Your Member Account";
    $message = "
    <html>
    <head>
        <title>Welcome to Pound for Pound Gym</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #5e63ff; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
            .credentials { background: #e8f4fd; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to Pound for Pound Gym!</h1>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($name) . "!</h2>
                <p>Your membership has been successfully activated. Here are your login credentials:</p>
                
                <div class='credentials'>
                    <h3>Login Information:</h3>
                    <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                    <p><strong>Temporary Password:</strong> " . htmlspecialchars($password) . "</p>
                    <p><strong>Membership Plan:</strong> " . htmlspecialchars($membership_plan) . "</p>
                </div>
                
                <p><strong>Important:</strong> Please log in to your account and change your password for security purposes.</p>
                <p>You can access your member portal at: <a href='http://localhost/Gym Website Ver.2/User/login.php'>Member Login</a></p>
                
                <p>Thank you for choosing Pound for Pound Gym!</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Pound for Pound Gym <noreply@poundforpoundgym.com>" . "\r\n";
    $headers .= "Reply-To: info@poundforpoundgym.com" . "\r\n";
    
    $result = mail($email, $subject, $message, $headers);
    error_log("Email sending result: " . ($result ? 'Success' : 'Failed'));
    return $result;
}

// Set default response if no success was set
if (!isset($response['success']) || !$response['success']) {
    if (empty($response['message'])) {
        $response['message'] = 'Unknown error occurred';
    }
}

// Return JSON response
echo json_encode($response);
exit;
?>