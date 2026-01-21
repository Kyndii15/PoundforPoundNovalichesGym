<?php
@include_once '../config.php';

@include_once '../includes/AttendanceSystem.php';

// Start admin session to get current user
session_name('gym_admin_session');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current user ID from session
$current_user_id = $_SESSION['user_id'] ?? null;

// Validate that user is logged in
if (!$current_user_id) {
    // Redirect to login if not authenticated
    header('Location: ../signin.php');
    exit;
}

// Initialize attendance system
$attendanceSystem = new AttendanceSystem($conn);

// Handle QR scan processing
$scan_result = null;
if ($_POST && isset($_POST['qr_token'])) {
    $qr_token = trim($_POST['qr_token']);
    $method = 'manual'; // Manual entry
    $scan_result = $attendanceSystem->processQRScan($qr_token, $current_user_id, $method);
}

// Get attendance statistics for different periods
$day_stats = $attendanceSystem->getPeriodAttendanceStats('day');
$week_stats = $attendanceSystem->getPeriodAttendanceStats('week');
$month_stats = $attendanceSystem->getPeriodAttendanceStats('month');

// Get today's attendance list
$today_attendance = $attendanceSystem->getDateAttendance(date('Y-m-d'), 50);

// Function to get package color based on plan name
function getPackageColor($planName) {
    if (empty($planName)) return '';
    
    if (stripos($planName, 'Package 1') !== false) {
        return '#1A43BF'; // Blue
    } elseif (stripos($planName, 'Package 2') !== false) {
        return '#FFE135'; // Yellow
    } elseif (stripos($planName, 'Package 3') !== false) {
        return '#03C03C'; // Green
    }
    
    return ''; // Default color if no package found
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Scanner - Gym Management</title>
    <link rel="stylesheet" href="../css/admin.css">
    <script type="text/javascript" src="../java/admin.js?v=2.0" defer></script>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <main>
        <h1>Attendance Scanner</h1>
        <p>Scan QR codes to record member attendance. One attendance record per day per member.</p>

        <!-- Scan Result Alert -->
        <?php if ($scan_result): ?>
        <div class="alert alert-<?= $scan_result['type'] ?>">
            <div class="alert-content">
                <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512">
                    <?php if ($scan_result['type'] === 'success'): ?>
                        <path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c70.7 0 128 57.3 128 128s-57.3 128-128 128s-128-57.3-128-128s57.3-128 128-128zM96.8 431.2c5.1-12.2 19.1-18.8 31.3-13.7c67.9 28.3 144.1 28.3 212 0c12.2-5.1 26.2 1.5 31.3 13.7c5.1 12.2-1.5 26.2-13.7 31.3c-82.6 34.4-175.7 34.4-258.3 0c-12.2-5.1-18.8-19.1-13.7-31.3z"/>
                    <?php elseif ($scan_result['type'] === 'warning'): ?>
                        <path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c70.7 0 128 57.3 128 128s-57.3 128-128 128s-128-57.3-128-128s57.3-128 128-128zM96.8 431.2c5.1-12.2 19.1-18.8 31.3-13.7c67.9 28.3 144.1 28.3 212 0c12.2-5.1 26.2 1.5 31.3 13.7c5.1 12.2-1.5 26.2-13.7 31.3c-82.6 34.4-175.7 34.4-258.3 0c-12.2-5.1-18.8-19.1-13.7-31.3z"/>
                    <?php else: ?>
                        <path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c70.7 0 128 57.3 128 128s-57.3 128-128 128s-128-57.3-128-128s57.3-128 128-128zM96.8 431.2c5.1-12.2 19.1-18.8 31.3-13.7c67.9 28.3 144.1 28.3 212 0c12.2-5.1 26.2 1.5 31.3 13.7c5.1 12.2-1.5 26.2-13.7 31.3c-82.6 34.4-175.7 34.4-258.3 0c-12.2-5.1-18.8-19.1-13.7-31.3z"/>
                    <?php endif; ?>
                </svg>
                <div class="scan-result-content">
                    <?php if (isset($scan_result['user_info']) && $scan_result['type'] === 'success'): ?>
                        <div class="scan-profile-section">
                            <?php 
                            $profile_photo = $scan_result['user_info']['profile_photo'] ?? null;
                            if ($profile_photo && file_exists(__DIR__ . '/../uploads/profile_photos/' . $profile_photo)): 
                            ?>
                                <img src="../uploads/profile_photos/<?= htmlspecialchars($profile_photo) ?>" alt="Profile" class="scan-profile-photo">
                            <?php else: ?>
                                <div class="scan-profile-placeholder">
                                    <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" viewBox="0 0 448 512">
                                        <path d="M224 256A128 128 0 1 0 224 0a128 128 0 1 0 0 256zm-45.7 48C79.8 304 0 383.8 0 482.3C0 498.7 13.3 512 29.7 512H418.3c16.4 0 29.7-13.3 29.7-29.7C448 383.8 368.2 304 269.7 304H178.3z"/>
                                    </svg>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="scan-result-info">
                        <h3><?= htmlspecialchars($scan_result['message']) ?></h3>
                        <?php if (isset($scan_result['user_info'])): ?>
                            <p class="scan-result-name"><?= htmlspecialchars($scan_result['user_info']['full_name']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- QR Scanner Section -->
        <div class="scanner-section">
            <div class="scanner-card">
                <div class="scanner-header">
                    <h2>QR Code Scanner</h2>
                </div>
                
                <div class="scanner-content">
                    <!-- QR Code Scanner -->
                    <div class="qr-scanner-section">
                        <div class="scanner-tabs">
                            <button class="tab-btn active" onclick="switchTab('camera')">Camera Scanner</button>
                            <button class="tab-btn" onclick="switchTab('manual')">Manual Entry</button>
                        </div>
                        
                        <!-- Camera Scanner Tab -->
                        <div id="camera-tab" class="tab-content active">
                            <div class="camera-container">
                                <div id="qr-reader" class="qr-reader"></div>
                                <div id="qr-reader-results" class="qr-results"></div>
                            </div>
                        </div>
                        
                        <!-- Manual Entry Tab -->
                        <div id="manual-tab" class="tab-content">
                            <form method="POST" class="qr-scan-form">
                                <div class="scan-input-group">
                                    <label for="qr_token">QR Code Token or Reference Number:</label>
                                    <div class="input-with-button">
                                        <input type="text" 
                                               id="qr_token" 
                                               name="qr_token" 
                                               placeholder="Enter QR code token, reference number, or scan QR code"
                                               required
                                               autocomplete="off">
                                        <button type="submit" class="btn btn-primary">
                                            Scan
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Scan Result Messages -->
                    <div id="scan-result" class="scan-result" style="display: none;"></div>
                </div>
            </div>
        </div>

        <!-- Attendance Statistics Dashboard -->
        <div class="dashboard-section">
            <div class="dashboard-card">
                <div class="dashboard-header">
                    <h2>Attendance Statistics</h2>
                    <span class="dashboard-date"><?= date('F j, Y') ?></span>
                </div>
                
                <div class="dashboard-stats">
                    <div class="stat-item">
                        <div class="stat-number"><?= $day_stats['total_checkins'] ?></div>
                        <div class="stat-label">Today's Check-ins</div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-number"><?= $week_stats['total_checkins'] ?></div>
                        <div class="stat-label">This Week's Check-ins</div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-number"><?= $month_stats['total_checkins'] ?></div>
                        <div class="stat-label">This Month's Check-ins</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Attendance List -->
        <div class="attendance-section">
            <div class="attendance-card">
                <div class="attendance-header">
                    <h2>Today's Attendance Records</h2>
                    <div class="attendance-filters">
                        <button class="btn btn-secondary btn-xs" onclick="refreshAttendance()">
                            <svg xmlns="http://www.w3.org/2000/svg" height="14" width="14" viewBox="0 0 512 512">
                                <path d="M463.5 224H472c13.3 0 24-10.7 24-24V72c0-9.7-5.8-18.5-14.8-22.2s-19.3-1.7-26.2 5.2L413.4 96.6c-87.6-86.5-228.7-86.2-315.8 1c-87.5 87.5-87.5 229.3 0 316.8s229.3 87.5 316.8 0c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0c-62.5 62.5-163.8 62.5-226.3 0s-62.5-163.8 0-226.3c62.2-62.2 162.7-62.5 225.3-1L327 183c-6.9 6.9-8.9 17.2-5.2 26.2s12.5 14.8 22.2 14.8H463.5z"/>
                            </svg>
                            Refresh
                        </button>
                        <button class="btn btn-secondary btn-xs" onclick="window.location.href='attendance_history.php'">
                            <svg xmlns="http://www.w3.org/2000/svg" height="14" width="14" viewBox="0 0 448 512">
                                <path d="M96 0C43 0 0 43 0 96V416c0 53 43 96 96 96H384h32c17.7 0 32-14.3 32-32s-14.3-32-32-32V384c17.7 0 32-14.3 32-32V32c0-17.7-14.3-32-32-32H384 96zm0 384H352v64H96c-17.7 0-32-14.3-32-32s14.3-32 32-32zm32-240c0-8.8 7.2-16 16-16H336c8.8 0 16 7.2 16 16s-7.2 16-16 16H144c-8.8 0-16-7.2-16-16zm16 48H336c8.8 0 16 7.2 16 16s-7.2 16-16 16H144c-8.8 0-16-7.2-16-16s7.2-16 16-16z"/>
                            </svg>
                            Attendance History
                        </button>
                    </div>
                </div>
                
                <div class="attendance-content">
                    <?php if (!empty($today_attendance)): ?>
                        <div class="attendance-table-container">
                            <table class="attendance-table">
                                <thead>
                                    <tr>
                                        <th>Profile Photo</th>
                                        <th>Member</th>
                                        <th>Time</th>
                                        <th>Method</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($today_attendance as $attendance): ?>
                                    <tr>
                                        <td>
                                            <?php 
                                            $profile_photo = $attendance['profile_photo'] ?? null;
                                            $plan_name = $attendance['plan_name'] ?? null;
                                            $package_color = getPackageColor($plan_name);
                                            $border_color = $package_color ?: 'var(--accent-clr)';
                                            if ($profile_photo && file_exists(__DIR__ . '/../uploads/profile_photos/' . $profile_photo)): 
                                            ?>
                                                <img src="../uploads/profile_photos/<?= htmlspecialchars($profile_photo) ?>" alt="Profile Photo" class="attendance-profile-photo" style="border-color: <?= htmlspecialchars($border_color) ?>;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="attendance-profile-placeholder" style="display: none; border-color: <?= htmlspecialchars($border_color) ?>;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 448 512">
                                                        <path d="M224 256A128 128 0 1 0 224 0a128 128 0 1 0 0 256zm-45.7 48C79.8 304 0 383.8 0 482.3C0 498.7 13.3 512 29.7 512H418.3c16.4 0 29.7-13.3 29.7-29.7C448 383.8 368.2 304 269.7 304H178.3z"/>
                                                    </svg>
                                                </div>
                                            <?php else: ?>
                                                <div class="attendance-profile-placeholder" style="border-color: <?= htmlspecialchars($border_color) ?>;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 448 512">
                                                        <path d="M224 256A128 128 0 1 0 224 0a128 128 0 1 0 0 256zm-45.7 48C79.8 304 0 383.8 0 482.3C0 498.7 13.3 512 29.7 512H418.3c16.4 0 29.7-13.3 29.7-29.7C448 383.8 368.2 304 269.7 304H178.3z"/>
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="member-info">
                                                <div class="member-name"><?= htmlspecialchars($attendance['full_name']) ?></div>
                                                <div class="member-email"><?= htmlspecialchars($attendance['email']) ?></div>
                                            </div>
                                        </td>
                                        <td><?php 
                                            // Display time in Philippine Time
                                            date_default_timezone_set('Asia/Manila');
                                            echo date('g:i A', strtotime($attendance['check_in_time'])); 
                                        ?></td>
                                        <td>
                                            <?php 
                                            $method = $attendance['check_in_method'] ?? 'qr_scan';
                                            if ($method === 'manual') {
                                                echo 'QR Reference';
                                            } else {
                                                echo 'QR Scanner';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <div class="staff-info">
                                                <?php if ($attendance['staff_name']): ?>
                                                    <div class="staff-name"><?= htmlspecialchars($attendance['staff_name']) ?></div>
                                                    <div class="staff-role"><?= ucfirst(htmlspecialchars($attendance['staff_role'])) ?></div>
                                                <?php else: ?>
                                                    <span class="no-staff">Self Check-in</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="attendance-table-container">
                            <table class="attendance-table">
                                <thead>
                                    <tr>
                                        <th>Profile Photo</th>
                                        <th>Member</th>
                                        <th>Time</th>
                                        <th>Method</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="5" class="no-data">
                                            <div class="no-data-content">
                                                <svg xmlns="http://www.w3.org/2000/svg" height="48" width="48" viewBox="0 0 512 512">
                                                    <path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c70.7 0 128 57.3 128 128s-57.3 128-128 128s-128-57.3-128-128s57.3-128 128-128zM96.8 431.2c5.1-12.2 19.1-18.8 31.3-13.7c67.9 28.3 144.1 28.3 212 0c12.2-5.1 26.2 1.5 31.3 13.7c5.1 12.2-1.5 26.2-13.7 31.3c-82.6 34.4-175.7 34.4-258.3 0c-12.2-5.1-18.8-19.1-13.7-31.3z"/>
                                                </svg>
                                                <p>No attendance records for today</p>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <style>
        /* Attendance Scanner Specific Styles */
        main {
            padding: 2rem;
            max-width: 1200px;
            margin: 0;
            width: 100%;
            box-sizing: border-box;
            overflow-x: hidden;
        }

        main h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-clr);
            margin-bottom: 0.5rem;
        }

        main p {
            color: var(--secondary-text-clr);
            margin-bottom: 2rem;
        }

        .scanner-section {
            margin-bottom: 2rem;
        }

        .scanner-card {
            background: var(--base-clr);
            padding: 2rem 3rem;
            border: 1px solid var(--line-clr);
            min-height: 200px;
        }

        .scanner-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--line-clr);
        }

        .scanner-header h2 {
            margin: 0;
            color: var(--accent-clr);
            font-size: 1.5rem;
        }

        .qr-scan-form {
            margin-bottom: 2rem;
        }

        .scan-input-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .scan-input-group label {
            font-weight: 600;
            color: var(--text-clr);
        }

        .input-with-button {
            display: flex;
            gap: 0.5rem;
        }

        .input-with-button input {
            flex: 1;
            padding: 0.75rem;
            border: 1px solid var(--line-clr);
            border-radius: 6px;
            background: var(--base-clr);
            color: var(--text-clr);
            font-size: 1rem;
        }

        .input-with-button input:focus {
            outline: none;
            border-color: var(--accent-clr);
        }

        .btn {
            padding: 0.875rem 1.75rem;
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            min-width: 130px;
            justify-content: center;
        }

        .btn-primary {
            background: var(--accent-clr);
            color: white;
        }

        .btn-primary:hover {
            background: #4c52d4;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-sm {
            padding: 0.6rem 1.2rem;
            font-size: 0.85rem;
            letter-spacing: 0.6px;
            min-width: 110px;
        }

        .btn-xs {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
            letter-spacing: 0.4px;
            min-width: 80px;
            gap: 0.4rem;
        }

        .dashboard-section {
            margin-bottom: 2rem;
        }

        .dashboard-card {
            background: var(--base-clr);
            padding: 2rem;
            border: 1px solid var(--line-clr);
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--line-clr);
        }

        .dashboard-header h2 {
            margin: 0;
            color: var(--accent-clr);
            font-size: 1.5rem;
        }

        .dashboard-date {
            color: var(--secondary-text-clr);
            font-size: 0.875rem;
        }

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .stat-item {
            text-align: center;
            padding: 1.5rem 2.5rem;
            background: rgba(94, 99, 255, 0.05);
            border-radius: 8px;
            border: 1px solid rgba(94, 99, 255, 0.1);
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-clr);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--secondary-text-clr);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .attendance-section {
            margin-bottom: 2rem;
        }

        .attendance-card {
            background: var(--base-clr);
            padding: 2rem;
            border: 1px solid var(--line-clr);
        }

        .attendance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--line-clr);
        }

        .attendance-header h2 {
            margin: 0;
            color: var(--accent-clr);
            font-size: 1.5rem;
        }

        .attendance-filters {
            display: flex;
            gap: 0.5rem;
        }

        .attendance-table-container {
            overflow-x: auto;
            width: 100%;
            max-width: none;
        }

        .attendance-table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
        }
        
        /* Desktop column widths */
        @media (min-width: 769px) {
            .attendance-table {
                table-layout: auto;
            }
        }

        .attendance-table th,
        .attendance-table td {
            padding: 0.75rem 1rem;
            border: 1px solid var(--line-clr);
            text-align: left;
            font-size: 0.875rem;
        }

        .attendance-table th {
            background: #2a2b36;
            color: var(--accent-clr);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-table td {
            color: var(--text-clr);
        }

        .attendance-table tbody tr:hover {
            background: rgba(94, 99, 255, 0.05);
        }

        .attendance-table tbody tr.active {
            background: rgba(40, 167, 69, 0.05);
        }

        .member-info {
            display: flex;
            flex-direction: column;
        }

        .member-name {
            font-weight: 600;
            color: var(--text-clr);
        }

        .member-email {
            font-size: 0.75rem;
            color: var(--secondary-text-clr);
        }

        .attendance-profile-photo {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-clr);
            display: block;
            margin: 0 auto;
        }

        .attendance-profile-placeholder {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(94, 99, 255, 0.1);
            border: 2px solid var(--accent-clr);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            color: var(--accent-clr);
        }

        .attendance-profile-placeholder svg {
            width: 24px;
            height: 24px;
            fill: currentColor;
        }



        .staff-info {
            display: flex;
            flex-direction: column;
        }

        .staff-name {
            font-weight: 600;
            color: var(--text-clr);
            font-size: 0.875rem;
        }

        .staff-role {
            font-size: 0.75rem;
            color: var(--secondary-text-clr);
            margin-top: 0.25rem;
            text-transform: capitalize;
        }

        .no-staff {
            font-size: 0.75rem;
            color: var(--secondary-text-clr);
            font-style: italic;
        }

        .in-progress {
            color: #ffc107;
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--secondary-text-clr);
        }

        .empty-state svg {
            fill: currentColor;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            margin: 0 0 0.5rem 0;
            color: var(--text-clr);
        }

        .empty-state p {
            margin: 0;
        }

        .no-data {
            text-align: center;
            padding: 3rem 1rem;
        }

        .no-data-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            color: var(--secondary-text-clr);
        }

        .no-data-content svg {
            opacity: 0.5;
        }

        .no-data-content p {
            margin: 0;
            font-size: 1rem;
        }

        .alert {
            margin-bottom: 2rem;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-color: rgba(40, 167, 69, 0.3);
            color: #28a745;
        }

        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            border-color: rgba(255, 193, 7, 0.3);
            color: #ffc107;
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            border-color: rgba(220, 53, 69, 0.3);
            color: #dc3545;
        }

        .alert-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-content svg {
            fill: currentColor;
            flex-shrink: 0;
        }

        .alert-content h3 {
            margin: 0 0 0.25rem 0;
            font-size: 1rem;
        }

        .alert-content p {
            margin: 0;
            font-size: 0.875rem;
            opacity: 0.9;
        }

        @media (max-width: 768px) {
            main {
                padding: 0.75rem;
                overflow-x: hidden;
            }
            
            main h1 {
                font-size: 1.1rem;
                margin-bottom: 0.75rem;
            }
            
            .scanner-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .input-with-button {
                flex-direction: column;
            }

            .dashboard-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .attendance-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .attendance-table-container {
                overflow-x: visible;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            
            .attendance-table {
                min-width: 0;
                width: 100%;
                font-size: 0.7rem;
                table-layout: fixed;
                margin: 0;
                border-spacing: 0;
            }
            
            .attendance-table th,
            .attendance-table td {
                padding: 0.4rem 0.3rem;
                font-size: 0.7rem;
                text-overflow: ellipsis;
            }
            
            /* Mobile column widths */
            .attendance-table th:nth-child(1),
            .attendance-table td:nth-child(1) { width: 12%; } /* Profile Photo */
            .attendance-table th:nth-child(2),
            .attendance-table td:nth-child(2) { width: 25%; } /* Member */
            .attendance-table th:nth-child(3),
            .attendance-table td:nth-child(3) { width: 15%; font-size: 0.65rem; } /* Time */
            .attendance-table th:nth-child(4),
            .attendance-table td:nth-child(4) { width: 18%; font-size: 0.65rem; } /* Method */
            .attendance-table th:nth-child(5),
            .attendance-table td:nth-child(5) { width: 30%; } /* Recorded By */
            
            .attendance-profile-photo,
            .attendance-profile-placeholder {
                width: 35px;
                height: 35px;
            }
            
            .attendance-profile-placeholder svg {
                width: 18px;
                height: 18px;
            }
            
            .member-name {
                font-size: 0.75rem;
            }
            
            .member-email {
                font-size: 0.65rem;
            }
            
            .staff-name {
                font-size: 0.75rem;
            }
            
            .staff-role {
                font-size: 0.65rem;
            }
        }
        
        @media (max-width: 480px) {
            main h1 {
                font-size: 1rem;
            }
            
            .attendance-table {
                font-size: 0.65rem;
            }
            
            .attendance-table th,
            .attendance-table td {
                padding: 0.35rem 0.25rem;
                font-size: 0.65rem;
            }
            
            .attendance-table th:nth-child(1),
            .attendance-table td:nth-child(1) { width: 12%; } /* Profile Photo */
            .attendance-table th:nth-child(2),
            .attendance-table td:nth-child(2) { width: 25%; font-size: 0.6rem; } /* Member */
            .attendance-table th:nth-child(3),
            .attendance-table td:nth-child(3) { width: 15%; font-size: 0.55rem; } /* Time */
            .attendance-table th:nth-child(4),
            .attendance-table td:nth-child(4) { width: 18%; font-size: 0.55rem; } /* Method */
            .attendance-table th:nth-child(5),
            .attendance-table td:nth-child(5) { width: 30%; } /* Recorded By */
            
            .attendance-profile-photo,
            .attendance-profile-placeholder {
                width: 30px;
                height: 30px;
            }
            
            .attendance-profile-placeholder svg {
                width: 16px;
                height: 16px;
            }
            
            .member-name {
                font-size: 0.7rem;
            }
            
            .member-email {
                font-size: 0.6rem;
            }
            
            .staff-name {
                font-size: 0.7rem;
            }
            
            .staff-role {
                font-size: 0.6rem;
            }
        }

        /* QR Scanner Styles */
        .qr-scanner-section {
            margin-top: 1rem;
        }

        .scanner-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--line-clr);
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--secondary-text-clr);
            font-weight: 500;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            color: var(--accent-clr);
            border-bottom-color: var(--accent-clr);
        }

        .tab-btn:hover {
            color: var(--text-clr);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .camera-container {
            text-align: center;
        }

        .qr-reader {
            max-width: 400px;
            margin: 0 auto;
        }

        .qr-results {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--hover-clr);
            border-radius: 6px;
            border: 1px solid var(--line-clr);
        }

        .scan-result {
            margin-top: 1rem;
            padding: 1rem;
            border-radius: 6px;
            font-weight: 500;
        }

        .scan-result.success {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.3);
            color: #28a745;
        }

        .scan-result.error {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #dc3545;
        }

        .scan-result-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .scan-profile-section {
            position: relative;
            flex-shrink: 0;
        }

        .scan-profile-photo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(40, 167, 69, 0.5);
        }

        .scan-profile-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(40, 167, 69, 0.2);
            border: 3px solid rgba(40, 167, 69, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #28a745;
        }

        .scan-result-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .scan-result-message {
            font-weight: 600;
            font-size: 1rem;
        }

        .scan-result-name {
            font-size: 0.9rem;
            opacity: 0.9;
            font-weight: 500;
        }
    </style>

    <!-- Include html5-qrcode library -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    
    <script>
        let html5QrcodeScanner = null;
        const scannedBy = <?= $current_user_id ?>; // Current admin user ID

        function refreshAttendance() {
            location.reload();
        }

        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
            
            // Initialize or stop scanner based on tab
            if (tabName === 'camera') {
                initQRScanner();
            } else {
                stopQRScanner();
            }
        }

        function initQRScanner() {
            if (html5QrcodeScanner) {
                return; // Already initialized
            }

            console.log("Initializing QR scanner...");
            html5QrcodeScanner = new Html5Qrcode("qr-reader");
            
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0,
                disableFlip: false
            };

            html5QrcodeScanner.start(
                { facingMode: "environment" }, // Use back camera
                config,
                onScanSuccess,
                onScanFailure
            ).then(() => {
                console.log("QR scanner started successfully");
                showScanResult('success', 'Camera scanner ready. Point at a QR code to scan.');
                console.log("Scanner configuration:", config);
            }).catch(err => {
                console.error("Error starting QR scanner:", err);
                showScanResult('error', 'Failed to start camera. Please check permissions and try again.');
                console.error("Full error details:", err);
            });
        }

        function stopQRScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    html5QrcodeScanner.clear();
                    html5QrcodeScanner = null;
                }).catch(err => {
                    console.error("Error stopping QR scanner:", err);
                });
            }
        }

        function onScanSuccess(decodedText, decodedResult) {
            console.log("QR Code detected:", decodedText);
            console.log("Decoded result:", decodedResult);
            console.log("QR token being sent:", decodedText);
            
            // Stop the scanner temporarily
            stopQRScanner();
            
            // Process the QR code
            processQRCode(decodedText);
        }

        function onScanFailure(error) {
            // This is called for every scan attempt, so we don't need to show errors
            // console.log("QR scan failed:", error);
        }

        function processQRCode(qrToken) {
            console.log("Processing QR code:", qrToken);
            
            const formData = new FormData();
            formData.append('qr_token', qrToken);
            formData.append('scanned_by', scannedBy);
            formData.append('method', 'qr_scan'); // Camera scan method

            showScanResult('success', 'Processing QR code...');

            fetch('../scan__process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log("Response status:", response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                // Get response as text first to check if it's valid JSON
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Server returned invalid response. Please check server logs.');
                    }
                });
            })
            .then(data => {
                console.log("Response data:", data);
                if (data.status === 'success') {
                    showScanResult('success', data.message, data.member_name, data.profile_photo);
                    // Refresh the page after 3 seconds to update attendance list
                    setTimeout(() => {
                        location.reload();
                    }, 3000);
                } else {
                    showScanResult('error', data.message || 'An error occurred');
                    // Restart scanner after 3 seconds
                    setTimeout(() => {
                        initQRScanner();
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Error processing QR code:', error);
                showScanResult('error', 'Error: ' + error.message);
                // Restart scanner after 3 seconds
                setTimeout(() => {
                    initQRScanner();
                }, 3000);
            });
        }

        function showScanResult(type, message, memberName = null, profilePhoto = null) {
            const resultDiv = document.getElementById('scan-result');
            resultDiv.className = `scan-result ${type}`;
            
            if (type === 'success' && memberName) {
                // Create profile display card
                let photoHtml = '';
                if (profilePhoto) {
                    photoHtml = `<img src="../uploads/profile_photos/${profilePhoto}" alt="Profile" class="scan-profile-photo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">`;
                }
                
                resultDiv.innerHTML = `
                    <div class="scan-result-content">
                        <div class="scan-profile-section">
                            ${photoHtml}
                            <div class="scan-profile-placeholder" style="${profilePhoto ? 'display:none;' : 'display:flex;'}">
                                <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" viewBox="0 0 448 512">
                                    <path d="M224 256A128 128 0 1 0 224 0a128 128 0 1 0 0 256zm-45.7 48C79.8 304 0 383.8 0 482.3C0 498.7 13.3 512 29.7 512H418.3c16.4 0 29.7-13.3 29.7-29.7C448 383.8 368.2 304 269.7 304H178.3z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="scan-result-info">
                            <div class="scan-result-message">${message}</div>
                            <div class="scan-result-name">${memberName}</div>
                        </div>
                    </div>
                `;
            } else {
                resultDiv.textContent = message;
            }
            
            resultDiv.style.display = 'block';
            
            // Hide result after 5 seconds (or 3 seconds for success)
            setTimeout(() => {
                resultDiv.style.display = 'none';
            }, type === 'success' ? 3000 : 5000);
        }

        // Auto-focus on QR input for manual entry
        document.addEventListener('DOMContentLoaded', function() {
            const qrInput = document.getElementById('qr_token');
            if (qrInput) {
                qrInput.focus();
            }
            
            // Initialize camera scanner if camera tab is active
            const cameraTab = document.getElementById('camera-tab');
            if (cameraTab && cameraTab.classList.contains('active')) {
                initQRScanner();
            }
        });

        // Clean up scanner when page is unloaded
        window.addEventListener('beforeunload', function() {
            stopQRScanner();
        });

        // Clear scan result after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>
