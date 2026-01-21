<?php
include '../config.php';
include '../includes/membership_plans_helper.php';

header('Content-Type: application/json');

// Get all plans grouped by package type (same as User/membership.php)
$grouped_plans = getMembershipPlansGrouped($conn);

// Convert grouped plans to flat array with package info for easier frontend handling
$plans_with_package_info = [];

foreach ($grouped_plans as $package_key => $package_group) {
    $package_info = getPackageInfo($conn, $package_group['package_type']);
    
    foreach ($package_group['plans'] as $plan) {
        $plan['package_info'] = $package_info; // Include package info with each plan
        $plans_with_package_info[] = $plan;
    }
}

echo json_encode([
    'success' => true,
    'plans' => $plans_with_package_info,
    'grouped' => $grouped_plans // Also include grouped structure for easier frontend grouping
]);
?>


