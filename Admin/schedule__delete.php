<?php
include '../config.php';
include '../includes/activity_logger.php';

// Start session to get admin ID
if (session_status() == PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Get shift details before deletion for logging
    $shift_query = $conn->prepare("SELECT rs.*, u.full_name FROM roster_shifts rs JOIN users u ON rs.staff_user_id = u.id WHERE rs.id = ?");
    $shift_query->bind_param("i", $id);
    $shift_query->execute();
    $shift_result = $shift_query->get_result();
    $shift_data = $shift_result->fetch_assoc();

    $stmt = $conn->prepare("DELETE FROM roster_shifts WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        // Log activity: Deleted Coach Schedule
        if ($shift_data) {
            $admin_id = $_SESSION['user_id'] ?? null;
            $coach_name = $shift_data['full_name'] ?? 'Unknown';
            $shift_date = $shift_data['shift_date'] ?? '';
            $start_time = $shift_data['start_time'] ?? '';
            $end_time = $shift_data['end_time'] ?? '';
            logActivity($conn, 'deleted_coach_schedule', "Deleted Coach Schedule: {$coach_name} - Shift on {$shift_date} ({$start_time} - {$end_time})", $admin_id, $id, 'schedule', ['coach_name' => $coach_name, 'date' => $shift_date, 'time' => "{$start_time} - {$end_time}"]);
        }
        // Preserve the date filter parameters
        $redirect_params = [];
        
        // Check for start_date and end_date parameters
        if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
            $redirect_params[] = 'start_date=' . urlencode($_GET['start_date']);
        }
        if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
            $redirect_params[] = 'end_date=' . urlencode($_GET['end_date']);
        }
        
        // Add success message
        $redirect_params[] = 'msg=deleted';
        
        // Build redirect URL
        $redirect_url = 'schedule__viewer.php?' . implode('&', $redirect_params);
        
        header("Location: $redirect_url");
            } else {
        echo "Error deleting shift.";
    }

    $stmt->close();
} else {
    echo "Invalid request.";
}

$conn->close();
?>

