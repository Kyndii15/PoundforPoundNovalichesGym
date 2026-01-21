<?php
/**
 * Delete Coach Schedules for December 1-31
 * This script will delete all coach schedules from December 1 to December 31
 */

include '../config.php';
include '../includes/activity_logger.php';

// Start session to get admin ID
if (session_status() == PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

$admin_id = $_SESSION['user_id'] ?? null;

// Set the date range for December 1-31 (current year or specify year)
$year = date('Y'); // Current year, or change to specific year like 2024
$start_date = $year . '-12-01';
$end_date = $year . '-12-31';

// Check if this is a confirmation request
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';

if (!$confirmed) {
    // Show confirmation page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Delete December Schedules - Confirmation</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 600px;
                margin: 50px auto;
                padding: 20px;
                background: #0c1118;
                color: white;
            }
            .warning-box {
                background: rgba(220, 53, 69, 0.1);
                border: 2px solid #dc3545;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }
            .info-box {
                background: rgba(23, 162, 184, 0.1);
                border: 2px solid #17a2b8;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }
            .btn {
                padding: 12px 24px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 16px;
                margin: 10px 5px;
                text-decoration: none;
                display: inline-block;
            }
            .btn-danger {
                background: #dc3545;
                color: white;
            }
            .btn-danger:hover {
                background: #c82333;
            }
            .btn-secondary {
                background: #6c757d;
                color: white;
            }
            .btn-secondary:hover {
                background: #5a6268;
            }
            h1 {
                color: #dc3545;
            }
        </style>
    </head>
    <body>
        <h1>⚠️ Delete December Coach Schedules</h1>
        
        <div class="warning-box">
            <h2>Warning: This action cannot be undone!</h2>
            <p>You are about to delete <strong>ALL</strong> coach schedules from:</p>
            <p><strong><?= date('F j, Y', strtotime($start_date)) ?> to <?= date('F j, Y', strtotime($end_date)) ?></strong></p>
        </div>
        
        <?php
        // Count how many schedules will be deleted
        $count_query = "SELECT COUNT(*) as total 
                       FROM roster_shifts rs 
                       JOIN users u ON rs.staff_user_id = u.id 
                       WHERE u.role = 'coach' 
                       AND rs.shift_date >= ? 
                       AND rs.shift_date <= ?";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->bind_param("ss", $start_date, $end_date);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $count_data = $count_result->fetch_assoc();
        $total_schedules = $count_data['total'] ?? 0;
        $count_stmt->close();
        
        if ($total_schedules > 0) {
            // Get list of coaches affected
            $coaches_query = "SELECT DISTINCT u.id, u.full_name, COUNT(rs.id) as schedule_count
                             FROM roster_shifts rs 
                             JOIN users u ON rs.staff_user_id = u.id 
                             WHERE u.role = 'coach' 
                             AND rs.shift_date >= ? 
                             AND rs.shift_date <= ?
                             GROUP BY u.id, u.full_name
                             ORDER BY u.full_name";
            $coaches_stmt = $conn->prepare($coaches_query);
            $coaches_stmt->bind_param("ss", $start_date, $end_date);
            $coaches_stmt->execute();
            $coaches_result = $coaches_stmt->get_result();
            
            ?>
            <div class="info-box">
                <h3>Summary:</h3>
                <p><strong>Total schedules to be deleted: <?= $total_schedules ?></strong></p>
                <p><strong>Coaches affected:</strong></p>
                <ul>
                    <?php while ($coach = $coaches_result->fetch_assoc()): ?>
                        <li><?= htmlspecialchars($coach['full_name']) ?> - <?= $coach['schedule_count'] ?> schedule(s)</li>
                    <?php endwhile; ?>
                </ul>
            </div>
            <?php
            $coaches_stmt->close();
        } else {
            ?>
            <div class="info-box">
                <p>No schedules found for December 1-31, <?= $year ?>.</p>
            </div>
            <?php
        }
        ?>
        
        <?php if ($total_schedules > 0): ?>
            <div style="margin-top: 30px;">
                <a href="?confirm=yes" class="btn btn-danger" onclick="return confirm('Are you absolutely sure? This will permanently delete <?= $total_schedules ?> schedule(s).');">
                    Yes, Delete All December Schedules
                </a>
                <a href="schedule__viewer.php" class="btn btn-secondary">Cancel</a>
            </div>
        <?php else: ?>
            <div style="margin-top: 30px;">
                <a href="schedule__viewer.php" class="btn btn-secondary">Back to Schedule Viewer</a>
            </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

// Proceed with deletion
mysqli_begin_transaction($conn);

try {
    // Get schedule details before deletion for logging
    $schedules_query = "SELECT rs.*, u.full_name 
                       FROM roster_shifts rs 
                       JOIN users u ON rs.staff_user_id = u.id 
                       WHERE u.role = 'coach' 
                       AND rs.shift_date >= ? 
                       AND rs.shift_date <= ?
                       ORDER BY u.full_name, rs.shift_date, rs.start_time";
    $schedules_stmt = $conn->prepare($schedules_query);
    $schedules_stmt->bind_param("ss", $start_date, $end_date);
    $schedules_stmt->execute();
    $schedules_result = $schedules_stmt->get_result();
    
    $deleted_schedules = [];
    $coach_counts = [];
    
    while ($schedule = $schedules_result->fetch_assoc()) {
        $deleted_schedules[] = $schedule;
        $coach_name = $schedule['full_name'];
        if (!isset($coach_counts[$coach_name])) {
            $coach_counts[$coach_name] = 0;
        }
        $coach_counts[$coach_name]++;
    }
    $schedules_stmt->close();
    
    // Delete the schedules
    $delete_query = "DELETE rs FROM roster_shifts rs 
                    JOIN users u ON rs.staff_user_id = u.id 
                    WHERE u.role = 'coach' 
                    AND rs.shift_date >= ? 
                    AND rs.shift_date <= ?";
    $delete_stmt = $conn->prepare($delete_query);
    $delete_stmt->bind_param("ss", $start_date, $end_date);
    $delete_stmt->execute();
    $deleted_count = $delete_stmt->affected_rows;
    $delete_stmt->close();
    
    // Commit transaction
    mysqli_commit($conn);
    
    // Log activity: Deleted Coach Schedules (Batch)
    if ($deleted_count > 0) {
        $coach_list = implode(', ', array_keys($coach_counts));
        $description = "Deleted Coach Schedules: {$deleted_count} schedule(s) deleted for December 1-31, {$year} (Coaches: {$coach_list})";
        logActivity($conn, 'deleted_coach_schedule', $description, $admin_id, null, 'schedule', [
            'count' => $deleted_count,
            'date_range' => "{$start_date} to {$end_date}",
            'type' => 'batch_december',
            'coaches' => $coach_counts
        ]);
    }
    
    // Show success page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Deletion Complete</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 600px;
                margin: 50px auto;
                padding: 20px;
                background: #0c1118;
                color: white;
            }
            .success-box {
                background: rgba(40, 167, 69, 0.1);
                border: 2px solid #28a745;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }
            .btn {
                padding: 12px 24px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 16px;
                margin: 10px 5px;
                text-decoration: none;
                display: inline-block;
                background: #5e63ff;
                color: white;
            }
            .btn:hover {
                background: #4c52e8;
            }
        </style>
    </head>
    <body>
        <h1>✅ Deletion Complete</h1>
        
        <div class="success-box">
            <h2>Successfully Deleted <?= $deleted_count ?> Schedule(s)</h2>
            <p><strong>Date Range:</strong> <?= date('F j, Y', strtotime($start_date)) ?> to <?= date('F j, Y', strtotime($end_date)) ?></p>
            
            <?php if (!empty($coach_counts)): ?>
                <p><strong>Coaches Affected:</strong></p>
                <ul>
                    <?php foreach ($coach_counts as $coach_name => $count): ?>
                        <li><?= htmlspecialchars($coach_name) ?> - <?= $count ?> schedule(s) deleted</li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <p style="margin-top: 20px; color: #28a745;">
                <strong>All schedules have been permanently deleted from the database.</strong>
            </p>
        </div>
        
        <div style="margin-top: 30px;">
            <a href="schedule__viewer.php" class="btn">Back to Schedule Viewer</a>
        </div>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    // Rollback on error
    mysqli_rollback($conn);
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Error</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 600px;
                margin: 50px auto;
                padding: 20px;
                background: #0c1118;
                color: white;
            }
            .error-box {
                background: rgba(220, 53, 69, 0.1);
                border: 2px solid #dc3545;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }
        </style>
    </head>
    <body>
        <h1>❌ Error</h1>
        <div class="error-box">
            <p><strong>An error occurred while deleting schedules:</strong></p>
            <p><?= htmlspecialchars($e->getMessage()) ?></p>
        </div>
        <a href="schedule__viewer.php" style="color: #5e63ff;">Back to Schedule Viewer</a>
    </body>
    </html>
    <?php
}

mysqli_close($conn);
?>



