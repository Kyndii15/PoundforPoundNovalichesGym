<?php
header('Content-Type: application/json');
include '../config.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}


$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

$staff_id = $input['staff_id'] ?? null;
$start_date = $input['start_date'] ?? null;
$end_date = $input['end_date'] ?? null;
$start_time = $input['start_time'] ?? null;
$end_time = $input['end_time'] ?? null;


if (!$staff_id || !$start_date || !$end_date || !$start_time || !$end_time) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}


if ($start_date > $end_date) {
    echo json_encode([
        'has_conflict' => false,
        'conflicts' => [],
        'error' => 'End date must be after or equal to start date'
    ]);
    exit;
}

// Validate time range
if ($start_time >= $end_time) {
    echo json_encode([
        'has_conflict' => false,
        'conflicts' => [],
        'error' => 'End time must be after start time'
    ]);
    exit;
}

try {
    $conflicts = [];
    $current_date = $start_date;
    
    // Check each date in the range
    while ($current_date <= $end_date) {
        // Check for existing shifts on this date that overlap with the proposed time
        // Only check for the SAME coach - multiple coaches can work simultaneously
        // Overlap occurs when: existing_start < new_end AND existing_end > new_start
        $check_query = "
            SELECT rs.id, rs.start_time, rs.end_time, u.full_name 
            FROM roster_shifts rs 
            JOIN users u ON rs.staff_user_id = u.id 
            WHERE rs.staff_user_id = ? 
            AND rs.shift_date = ? 
            AND u.role = 'coach'
            AND (
                (rs.start_time < ? AND rs.end_time > ?)  -- Overlap: existing shift overlaps with new shift
            )
        ";
        
        // Parameters: staff_id, current_date, new_end_time, new_start_time
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("isss", $staff_id, $current_date, $end_time, $start_time);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $conflicts[] = [
                'date' => $current_date,
                'existing_start' => $row['start_time'],
                'existing_end' => $row['end_time'],
                'coach_name' => $row['full_name'],
                'conflict_type' => 'time_overlap'
            ];
        }
        
        // Move to next date
        $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
    }
    
    // Note: Removed "other_coach_assigned" check - multiple coaches can work simultaneously
    // Only the same coach having overlapping times is considered a conflict
    
    echo json_encode([
        'has_conflict' => count($conflicts) > 0,
        'conflicts' => $conflicts,
        'total_conflicts' => count($conflicts)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>


















