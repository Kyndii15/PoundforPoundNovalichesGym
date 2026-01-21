<?php
include '../config.php';
include '../includes/activity_logger.php';

// Start session to get admin ID
if (session_status() == PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staff_user_id = isset($_POST['staff_user_id']) ? intval($_POST['staff_user_id']) : 0;
    $shift_date_start = $_POST['shift_date_start'] ?? '';
    $shift_date_end = $_POST['shift_date_end'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $role = 'coach'; // Fixed to coach role

    // Basic validation (you can add more)
    if (empty($staff_user_id) || $staff_user_id <= 0 || empty($shift_date_start) || empty($shift_date_end) || empty($start_time) || empty($end_time)) {
        die("All fields are required.");
    }
    
    // Validate that the staff_user_id exists and is a coach
    $validate_coach = $conn->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'coach' AND archived = 0");
    $validate_coach->bind_param("i", $staff_user_id);
    $validate_coach->execute();
    $coach_result = $validate_coach->get_result();
    
    if ($coach_result->num_rows === 0) {
        error_log("Invalid coach ID submitted: $staff_user_id");
        die("Invalid coach selected. Please select a valid coach.");
    }
    
    $coach_data = $coach_result->fetch_assoc();
    $coach_name = $coach_data['full_name'];
    $validated_staff_user_id = $coach_data['id']; // Use the validated ID
    $validate_coach->close();
    
    // Log for debugging
    error_log("Schedule Assignment - Coach ID: $validated_staff_user_id, Coach Name: $coach_name, Date Range: $shift_date_start to $shift_date_end, Time: $start_time - $end_time");

    // Validate date range
    if ($shift_date_start > $shift_date_end) {
        die("End date must be after or equal to start date.");
    }

    // Validate time
    if ($start_time >= $end_time) {
        die("End time must be after start time.");
    }

    // Validate time range (7:00 AM to 10:00 PM)
    if ($start_time < '07:00' || $start_time > '22:00') {
        die("Start time must be between 7:00 AM and 10:00 PM (Philippine Time).");
    }

    if ($end_time < '07:00' || $end_time > '22:00') {
        die("End time must be between 7:00 AM and 10:00 PM (Philippine Time).");
    }

    // Start transaction
    $conn->begin_transaction();
    
    try {
        $success_count = 0;
        $duplicate_count = 0;
        $error_count = 0;
        
        // Create date range
        $current_date = $shift_date_start;
        $end_date = $shift_date_end;
        
        // Get excluded days from form (if any)
        $exclude_days = isset($_POST['exclude_days']) ? $_POST['exclude_days'] : [];
        
        while ($current_date <= $end_date) {
            // Check if this day should be excluded
            $day_of_week = date('w', strtotime($current_date)); // 0=Sun, 1=Mon, ..., 6=Sat
            
            // Skip this date if it's in the exclusion list
            if (in_array($day_of_week, $exclude_days)) {
                // Move to next date without creating shift
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                continue;
            }
            
            // Check for existing shift on this date and time for this coach
            $check_stmt = $conn->prepare("SELECT id FROM roster_shifts WHERE staff_user_id = ? AND shift_date = ? AND ((start_time <= ? AND end_time > ?) OR (start_time < ? AND end_time >= ?) OR (start_time >= ? AND end_time <= ?))");
            $check_stmt->bind_param("isssssss", $validated_staff_user_id, $current_date, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time);
            $check_stmt->execute();
            $existing_shift = $check_stmt->get_result();
            
            if ($existing_shift->num_rows > 0) {
                // Skip this date - shift already exists
                $duplicate_count++;
            } else {
                // Insert new shift - use validated staff_user_id
                $stmt = $conn->prepare("INSERT INTO roster_shifts (staff_user_id, shift_date, start_time, end_time, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("issss", $validated_staff_user_id, $current_date, $start_time, $end_time, $role);
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
            
            // Move to next date
            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
        }
        
        // Commit transaction
        $conn->commit();
        
        // Log activity: New Coach Scheduling
        if ($success_count > 0) {
            $admin_id = $_SESSION['user_id'] ?? null;
            logActivity($conn, 'new_coach_scheduling', "New Coach Scheduling: {$coach_name} - {$success_count} shift(s) scheduled from {$shift_date_start} to {$shift_date_end} ({$start_time} - {$end_time})", $admin_id, $validated_staff_user_id, 'schedule', ['coach_name' => $coach_name, 'count' => $success_count, 'date_range' => "{$shift_date_start} to {$shift_date_end}", 'time' => "{$start_time} - {$end_time}"]);
        }
        
        // Prepare success message
        $message = "Shifts created successfully! ";
        if ($success_count > 0) {
            $message .= "$success_count shift(s) added. ";
        }
        if ($duplicate_count > 0) {
            $message .= "$duplicate_count duplicate(s) skipped. ";
        }
        if ($error_count > 0) {
            $message .= "$error_count error(s) occurred. ";
        }
        
        // Redirect to schedule viewer with shift count
        header("Location: schedule__viewer.php?shifts_created=$success_count&success=" . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo "Error saving shifts: " . $e->getMessage();
    }
}
?>

