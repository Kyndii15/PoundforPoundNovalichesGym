<?php
include '../config.php';
include '../includes/membership_plans_helper.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid member ID']);
    exit;
}

$member_id = intval($_GET['id']);

// Get member details with membership information and last attendance
// Priority: 1) Active subscription with future expiry, 2) Most recent subscription
$query = "
    SELECT 
        u.id,
        u.full_name,
        u.email,
        u.archived,
        u.created_at,
        m.id as member_id,
        m.joined_at,
        m.status as original_status,
        m.phone,
        m.address,
        COALESCE(
            (SELECT s1.id FROM subscriptions s1 
             INNER JOIN members m1 ON s1.member_id = m1.id 
             WHERE m1.user_id = u.id
             AND s1.status = 'active' 
             AND s1.expiry_date > CURDATE() 
             ORDER BY s1.id DESC LIMIT 1),
            (SELECT s2.id FROM subscriptions s2 
             INNER JOIN members m2 ON s2.member_id = m2.id 
             WHERE m2.user_id = u.id
             ORDER BY s2.id DESC LIMIT 1)
        ) as subscription_id,
        COALESCE(
            (SELECT s1.plan_name FROM subscriptions s1 
             INNER JOIN members m1 ON s1.member_id = m1.id 
             WHERE m1.user_id = u.id
             AND s1.status = 'active' 
             AND s1.expiry_date > CURDATE() 
             ORDER BY s1.id DESC LIMIT 1),
            (SELECT s2.plan_name FROM subscriptions s2 
             INNER JOIN members m2 ON s2.member_id = m2.id 
             WHERE m2.user_id = u.id
             ORDER BY s2.id DESC LIMIT 1)
        ) as plan_name,
        COALESCE(
            (SELECT s1.expiry_date FROM subscriptions s1 
             INNER JOIN members m1 ON s1.member_id = m1.id 
             WHERE m1.user_id = u.id
             AND s1.status = 'active' 
             AND s1.expiry_date > CURDATE() 
             ORDER BY s1.id DESC LIMIT 1),
            (SELECT s2.expiry_date FROM subscriptions s2 
             INNER JOIN members m2 ON s2.member_id = m2.id 
             WHERE m2.user_id = u.id
             ORDER BY s2.id DESC LIMIT 1)
        ) as expiry_date,
        COALESCE(
            (SELECT s1.status FROM subscriptions s1 
             INNER JOIN members m1 ON s1.member_id = m1.id 
             WHERE m1.user_id = u.id
             AND s1.status = 'active' 
             AND s1.expiry_date > CURDATE() 
             ORDER BY s1.id DESC LIMIT 1),
            (SELECT s2.status FROM subscriptions s2 
             INNER JOIN members m2 ON s2.member_id = m2.id 
             WHERE m2.user_id = u.id
             ORDER BY s2.id DESC LIMIT 1)
        ) as subscription_status,
        TRIM(SUBSTRING_INDEX(u.full_name, ' ', -1)) as surname,
        TRIM(SUBSTRING_INDEX(u.full_name, ' ', 1)) as first_name,
        (SELECT MAX(a.check_in_time) FROM attendance a WHERE a.user_id = u.id) as last_attendance_date
    FROM users u
    INNER JOIN members m ON u.id = m.user_id
    WHERE u.id = ? AND u.role = 'customer'
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $member = $result->fetch_assoc();
    
    // Get current plan price from membership_plans table if plan exists
    $current_plan_price = 0;
    $remaining_days = 0;
    $has_active_plan = false;
    
    if ($member['plan_name'] && $member['subscription_id']) {
        // Try to get plan price using the helper function (more reliable)
        $plan_by_name = getMembershipPlanByName($conn, $member['plan_name']);
        if ($plan_by_name && isset($plan_by_name['price'])) {
            $current_plan_price = floatval($plan_by_name['price']);
        } else {
            // Fallback: try to match by plan name pattern
            $plan_name_lower = strtolower($member['plan_name']);
            
            // Check for Boxing plans
            if (strpos($plan_name_lower, 'boxing') !== false) {
                if (strpos($plan_name_lower, '1 month') !== false || strpos($plan_name_lower, '(1') !== false) $current_plan_price = 2500;
                elseif (strpos($plan_name_lower, '2 month') !== false || strpos($plan_name_lower, '(2') !== false) $current_plan_price = 4000;
                elseif (strpos($plan_name_lower, '3 month') !== false || strpos($plan_name_lower, '(3') !== false) $current_plan_price = 6000;
            }
            // Check for Circuit Training plans
            elseif (strpos($plan_name_lower, 'circuit') !== false) {
                if (strpos($plan_name_lower, '1 month') !== false || strpos($plan_name_lower, '(1') !== false) $current_plan_price = 1700;
                elseif (strpos($plan_name_lower, '3 month') !== false || strpos($plan_name_lower, '(3') !== false) $current_plan_price = 2900;
                elseif (strpos($plan_name_lower, '6 month') !== false || strpos($plan_name_lower, '(6') !== false) $current_plan_price = 5500;
                elseif (strpos($plan_name_lower, '1 year') !== false || strpos($plan_name_lower, '12 month') !== false) $current_plan_price = 9000;
            }
            // Check for Muay Thai plans
            elseif (strpos($plan_name_lower, 'muay thai') !== false || strpos($plan_name_lower, 'muay') !== false) {
                if (strpos($plan_name_lower, '1 month') !== false || strpos($plan_name_lower, '(1') !== false) $current_plan_price = 3000;
                elseif (strpos($plan_name_lower, '2 month') !== false || strpos($plan_name_lower, '(2') !== false) $current_plan_price = 5000;
                elseif (strpos($plan_name_lower, '3 month') !== false || strpos($plan_name_lower, '(3') !== false) $current_plan_price = 7000;
            }
        }
        
        // Calculate remaining days
        if ($member['expiry_date']) {
            $expiry_timestamp = strtotime($member['expiry_date']);
            $current_timestamp = time();
            $remaining_days = max(0, floor(($expiry_timestamp - $current_timestamp) / 86400));
            
            // Check if has active plan - must be active status AND not expired
            // This matches the table view logic: status == 'active' AND expiry_date > current time
            if ($member['subscription_status'] === 'active' && $expiry_timestamp > $current_timestamp) {
                $has_active_plan = true;
            }
        }
    }
    
    // Only show plan if it's active and not expired (consistent with table view)
    // If plan is expired or inactive, clear plan data to show "None"
    if (!$has_active_plan) {
        $member['plan_name'] = null;
        $member['expiry_date'] = null;
        $member['subscription_id'] = null;
        $member['subscription_status'] = null;
        $current_plan_price = 0;
        $remaining_days = 0;
    }
    
    $member['current_plan_price'] = $current_plan_price;
    $member['remaining_days'] = $remaining_days;
    $member['has_active_plan'] = $has_active_plan;
    
    // Format dates - use created_at for accurate account creation time
    $joined_timestamp = $member['created_at'] ?? $member['joined_at'];
    $member['joined_date'] = date('F j, Y \a\t g:i A', strtotime($joined_timestamp));
    $member['expiry_date'] = $member['expiry_date'] ? date('F j, Y', strtotime($member['expiry_date'])) : null;
    $member['last_attendance'] = $member['last_attendance_date'] ? date('F j, Y \a\t g:i A', strtotime($member['last_attendance_date'])) : 'Never';
    
    // Determine member status
    if ($member['archived']) {
        $member['status'] = 'archived';
    } elseif ($member['subscription_status'] === 'active' && $member['expiry_date'] && strtotime($member['expiry_date']) > time()) {
        $member['status'] = 'active';
    } else {
        $member['status'] = 'inactive';
    }
    
    echo json_encode([
        'success' => true,
        'member' => $member
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Member not found']);
}
?>
