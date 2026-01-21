<?php
// Get converted walk-in details
include '../config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'details' => null];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $response['message'] = 'Invalid walk-in ID';
    echo json_encode($response);
    exit;
}

$walkin_id = intval($_GET['id']);

try {
    // Get walk-in details
    $walkin_query = $conn->prepare("SELECT * FROM walk_in_log WHERE id = ?");
    $walkin_query->bind_param("i", $walkin_id);
    $walkin_query->execute();
    $walkin_result = $walkin_query->get_result();
    $walkin_data = $walkin_result->fetch_assoc();
    
    if (!$walkin_data) {
        $response['message'] = 'Walk-in record not found';
        echo json_encode($response);
        exit;
    }
    
    // Get user details (converted account)
    $user_query = $conn->prepare("SELECT u.*, m.phone, m.address FROM users u 
                                 LEFT JOIN members m ON u.id = m.user_id 
                                 WHERE u.full_name = ? AND u.role = 'customer' AND u.email_verified = 1 
                                 ORDER BY u.created_at DESC LIMIT 1");
    $user_query->bind_param("s", $walkin_data['name']);
    $user_query->execute();
    $user_result = $user_query->get_result();
    $user_data = $user_result->fetch_assoc();
    
    if (!$user_data) {
        $response['message'] = 'Converted account not found';
        echo json_encode($response);
        exit;
    }
    
    // Get subscription details
    $subscription_query = $conn->prepare("SELECT s.* FROM subscriptions s 
                                         JOIN members m ON s.member_id = m.id 
                                         WHERE m.user_id = ? ORDER BY s.id DESC LIMIT 1");
    $subscription_query->bind_param("i", $user_data['id']);
    $subscription_query->execute();
    $subscription_result = $subscription_query->get_result();
    $subscription_data = $subscription_result->fetch_assoc();
    
    // Get transaction details
    $transaction_query = $conn->prepare("SELECT * FROM transactions 
                                        WHERE user_id = ? AND type = 'membership' 
                                        ORDER BY date DESC LIMIT 1");
    $transaction_query->bind_param("i", $user_data['id']);
    $transaction_query->execute();
    $transaction_result = $transaction_query->get_result();
    $transaction_data = $transaction_result->fetch_assoc();

    // Get payment (receipt/reference) details if available
    $payment_query = $conn->prepare("SELECT receipt_path, reference_number, payment_method, amount FROM payments 
                                    WHERE user_id = ? ORDER BY payment_date DESC, id DESC LIMIT 1");
    $payment_query->bind_param("i", $user_data['id']);
    $payment_query->execute();
    $payment_result = $payment_query->get_result();
    $payment_data = $payment_result->fetch_assoc();
    
    // Prepare response data
    $response['success'] = true;
    $response['details'] = [
        'full_name' => $user_data['full_name'],
        'email' => $user_data['email'],
        'password_plain' => $user_data['password_plain'] ?? 'Not available',
        'phone' => $user_data['phone'] ?? 'Not provided',
        'address' => $user_data['address'] ?? 'Not provided',
        'plan_name' => $subscription_data['plan_name'] ?? 'Not available',
        'payment_method' => $payment_data['payment_method'] ?? ($transaction_data['payment_method'] ?? 'Not available'),
        'amount' => $payment_data['amount'] ?? ($transaction_data['amount'] ?? $walkin_data['amount']),
        'receipt_path' => $payment_data['receipt_path'] ?? null,
        'reference_number' => $payment_data['reference_number'] ?? null,
        'conversion_date' => date('M d, Y g:i A', strtotime($user_data['created_at']))
    ];
    
} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>
