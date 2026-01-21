<?php
include '../config.php';
include '../includes/activity_logger.php';

// Start session to get admin ID
if (session_status() == PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shift_ids = $_POST['shift_ids'] ?? [];
    $start_date = $_POST['start_date'] ?? date('Y-m-d');
    $end_date = $_POST['end_date'] ?? date('Y-m-d');
    
    if (empty($shift_ids)) {
        header("Location: schedule__viewer.php?start_date=$start_date&end_date=$end_date&error=" . urlencode("No shifts selected for deletion."));
        exit;
    }
    

    $conn->begin_transaction();
    
    try {
        $deleted_count = 0;
        $error_count = 0;
        
        foreach ($shift_ids as $shift_id) {

            $check_stmt = $conn->prepare("SELECT rs.id FROM roster_shifts rs 
                                        JOIN users u ON rs.staff_user_id = u.id 
                                        WHERE rs.id = ? AND u.role = 'coach'");
            $check_stmt->bind_param("i", $shift_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
  
                $delete_stmt = $conn->prepare("DELETE FROM roster_shifts WHERE id = ?");
                $delete_stmt->bind_param("i", $shift_id);
                
                if ($delete_stmt->execute()) {
                    $deleted_count++;
                } else {
                    $error_count++;
                }
            } else {
                $error_count++;
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Log activity: Deleted Coach Schedules (Batch)
        if ($deleted_count > 0) {
            $admin_id = $_SESSION['user_id'] ?? null;
            logActivity($conn, 'deleted_coach_schedule', "Deleted Coach Schedules: {$deleted_count} shift(s) deleted (Batch deletion)", $admin_id, null, 'schedule', ['count' => $deleted_count, 'type' => 'batch']);
        }
        
        // Prepare success message
        $message = "Batch deletion completed! ";
        if ($deleted_count > 0) {
            $message .= "$deleted_count shift(s) deleted. ";
        }
        if ($error_count > 0) {
            $message .= "$error_count shift(s) could not be deleted. ";
        }
        
        // Redirect back to schedule viewer
        header("Location: schedule__viewer.php?start_date=$start_date&end_date=$end_date&success=" . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        header("Location: schedule__viewer.php?start_date=$start_date&end_date=$end_date&error=" . urlencode("Error deleting shifts: " . $e->getMessage()));
        exit;
    }
} else {
    // Redirect if not POST request
    header("Location: schedule__viewer.php");
    exit;
}
?>






