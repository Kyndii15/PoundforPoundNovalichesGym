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
    header('Location: ../signin.php');
    exit;
}

// Initialize attendance system
$attendanceSystem = new AttendanceSystem($conn);

// Get date range from GET parameters (default to last 30 days)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$search_query = $_GET['search'] ?? '';

// Build query for attendance history
$where_conditions = ["DATE(a.check_in_time) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];
$param_types = "ss";

// Add search condition if provided
if (!empty($search_query)) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.email LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ss";
}

$where_clause = implode(" AND ", $where_conditions);

$query = "SELECT a.*, u.full_name, u.email, u.profile_photo,
                 s.full_name as staff_name, s.role as staff_role,
                 a.check_in_method,
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
                 ) as plan_name
          FROM attendance a 
          JOIN users u ON a.user_id = u.id 
          LEFT JOIN users s ON a.scanned_by = s.id 
          WHERE {$where_clause}
          ORDER BY a.check_in_time DESC 
          LIMIT 500";

$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $attendance_history = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $attendance_history[] = $row;
    }
    mysqli_stmt_close($stmt);
} else {
    $attendance_history = [];
}

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
    <title>Attendance History - Gym Management</title>
    <link rel="stylesheet" href="../css/admin.css">
    <script type="text/javascript" src="../java/admin.js?v=2.0" defer></script>
    <style>
        main {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            margin: 0;
            font-size: 2rem;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--accent-clr);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .back-button:hover {
            background: var(--hover-clr);
            transform: translateY(-2px);
        }

        .filters-section {
            background: var(--base-clr);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            border: 1px solid var(--line-clr);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-clr);
        }

        .filter-group input,
        .filter-group button {
            padding: 0.75rem;
            border: 1px solid var(--line-clr);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-clr);
            font-size: 1rem;
        }

        .filter-group input:focus {
            outline: none;
            border-color: var(--accent-clr);
        }

        .filter-group button {
            background: var(--accent-clr);
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .filter-group button:hover {
            background: var(--hover-clr);
        }

        .attendance-section {
            background: var(--base-clr);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--line-clr);
        }

        .attendance-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--line-clr);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .attendance-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .attendance-table-container {
            overflow-x: auto;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }

        /* Mobile Card Layout */
        .attendance-cards {
            display: none;
        }

        .attendance-card {
            background: var(--card-bg);
            border: 1px solid var(--line-clr);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .attendance-card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--line-clr);
        }

        .attendance-card-body {
            display: grid;
            gap: 0.75rem;
        }

        .attendance-card-row {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .attendance-card-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-card-value {
            font-size: 0.9rem;
            color: var(--text-clr);
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        /* Desktop: Show table, hide cards */
        @media (min-width: 769px) {
            .attendance-table-container {
                display: block;
            }

            .attendance-cards {
                display: none;
            }
        }

        .attendance-table thead {
            background: var(--card-bg);
        }

        .attendance-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-clr);
            border-bottom: 2px solid var(--line-clr);
            white-space: nowrap;
        }

        .attendance-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--line-clr);
            color: var(--text-clr);
        }

        .attendance-table tbody tr:hover {
            background: var(--card-bg);
        }

        .attendance-profile-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid;
        }

        .attendance-profile-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid;
        }

        .attendance-profile-placeholder svg {
            width: 20px;
            height: 20px;
            fill: var(--text-muted);
        }

        .member-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .member-name {
            font-weight: 600;
            color: var(--text-clr);
        }

        .member-email {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .staff-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .staff-name {
            font-weight: 500;
            color: var(--text-clr);
        }

        .staff-role {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .no-staff {
            color: var(--text-muted);
            font-style: italic;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        @media (max-width: 768px) {
            main {
                padding: 1rem;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                overflow-x: hidden;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                margin-bottom: 1.5rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
                word-wrap: break-word;
                overflow-wrap: break-word;
                width: 100%;
            }

            .back-button {
                width: 100%;
                max-width: 100%;
                justify-content: center;
                box-sizing: border-box;
                padding: 0.875rem 1rem;
                font-size: 0.9rem;
            }

            .filters-section {
                padding: 1rem;
                margin-bottom: 1.5rem;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            .filters-form {
                grid-template-columns: 1fr;
                gap: 1rem;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            .filter-group {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            .filter-group input,
            .filter-group button {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            .filter-group button {
                padding: 0.875rem;
                font-size: 0.95rem;
            }

            .attendance-section {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            .attendance-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
                padding: 1rem;
            }

            .attendance-header h2 {
                font-size: 1.25rem;
                word-wrap: break-word;
                overflow-wrap: break-word;
                width: 100%;
            }

            .attendance-header span {
                font-size: 0.875rem;
                word-wrap: break-word;
            }

            .attendance-table-container {
                display: none;
            }

            .attendance-cards {
                display: block;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            .attendance-card {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            .attendance-card-header {
                flex-wrap: wrap;
            }

            .attendance-profile-photo,
            .attendance-profile-placeholder {
                flex-shrink: 0;
            }

            .attendance-profile-photo,
            .attendance-profile-placeholder {
                width: 35px;
                height: 35px;
            }

            .member-name {
                font-size: 0.9rem;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }

            .member-email {
                font-size: 0.75rem;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }

            .staff-name {
                font-size: 0.85rem;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }

            .staff-role {
                font-size: 0.75rem;
                word-wrap: break-word;
            }

            .no-data {
                padding: 2rem 1rem;
                font-size: 0.9rem;
                word-wrap: break-word;
            }
        }

        @media (max-width: 480px) {
            main {
                padding: 0.75rem;
            }

            .page-header h1 {
                font-size: 1.25rem;
            }

            .back-button {
                padding: 0.75rem;
                font-size: 0.875rem;
            }

            .filters-section {
                padding: 0.875rem;
            }

            .filter-group label {
                font-size: 0.85rem;
            }

            .filter-group input,
            .filter-group button {
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .attendance-header {
                padding: 0.875rem;
            }

            .attendance-header h2 {
                font-size: 1.125rem;
            }

            .attendance-card {
                padding: 0.875rem;
            }

            .attendance-card-header {
                margin-bottom: 0.875rem;
                padding-bottom: 0.625rem;
            }

            .attendance-card-body {
                gap: 0.625rem;
            }

            .attendance-card-label {
                font-size: 0.7rem;
            }

            .attendance-card-value {
                font-size: 0.85rem;
            }

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
                font-size: 0.85rem;
            }

            .member-email {
                font-size: 0.7rem;
            }

            .staff-name {
                font-size: 0.8rem;
            }

            .staff-role {
                font-size: 0.7rem;
            }

            .no-data {
                padding: 1.5rem 0.75rem;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <main>
        <div class="page-header">
            <h1>Membership Attendance History</h1>
            <a href="attendance_scanner.php" class="back-button">
                <svg xmlns="http://www.w3.org/2000/svg" height="16" width="16" viewBox="0 0 512 512">
                    <path d="M463.5 224H472c13.3 0 24-10.7 24-24V72c0-9.7-5.8-18.5-14.8-22.2s-19.3-1.7-26.2 5.2L413.4 96.6c-87.6-86.5-228.7-86.2-315.8 1c-87.5 87.5-87.5 229.3 0 316.8s229.3 87.5 316.8 0c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0c-62.5 62.5-163.8 62.5-226.3 0s-62.5-163.8 0-226.3c62.2-62.2 162.7-62.5 225.3-1L327 183c-6.9 6.9-8.9 17.2-5.2 26.2s12.5 14.8 22.2 14.8H463.5z"/>
                </svg>
                Back to Scanner
            </a>
        </div>

        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required>
                </div>
                <div class="filter-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required>
                </div>
                <div class="filter-group">
                    <label for="search">Search Member</label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Name or email...">
                </div>
                <div class="filter-group">
                    <button type="submit">Filter</button>
                </div>
            </form>
        </div>

        <div class="attendance-section">
            <div class="attendance-header">
                <h2>Attendance Records</h2>
                <span style="color: var(--text-muted);"><?= count($attendance_history) ?> record(s) found</span>
            </div>
            
            <div class="attendance-table-container">
                <?php if (!empty($attendance_history)): ?>
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>Profile Photo</th>
                                <th>Member</th>
                                <th>Date</th>
                                <th>Check-in Time</th>
                                <th>Method</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_history as $attendance): ?>
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
                                <td><?= date('M j, Y', strtotime($attendance['check_in_time'])) ?></td>
                                <td><?= date('g:i A', strtotime($attendance['check_in_time'])) ?></td>
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
                <?php else: ?>
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>Profile Photo</th>
                                <th>Member</th>
                                <th>Date</th>
                                <th>Check-in Time</th>
                                <th>Method</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="6" class="no-data">
                                    No attendance records found for the selected date range.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Mobile Card Layout -->
            <div class="attendance-cards">
                <?php if (!empty($attendance_history)): ?>
                    <?php foreach ($attendance_history as $attendance): 
                        $profile_photo = $attendance['profile_photo'] ?? null;
                        $plan_name = $attendance['plan_name'] ?? null;
                        $package_color = getPackageColor($plan_name);
                        $border_color = $package_color ?: 'var(--accent-clr)';
                        $method = $attendance['check_in_method'] ?? 'qr_scan';
                        $method_text = ($method === 'manual') ? 'QR Reference' : 'QR Scanner';
                    ?>
                    <div class="attendance-card">
                        <div class="attendance-card-header">
                            <?php if ($profile_photo && file_exists(__DIR__ . '/../uploads/profile_photos/' . $profile_photo)): ?>
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
                            <div class="member-info">
                                <div class="member-name"><?= htmlspecialchars($attendance['full_name']) ?></div>
                                <div class="member-email"><?= htmlspecialchars($attendance['email']) ?></div>
                            </div>
                        </div>
                        <div class="attendance-card-body">
                            <div class="attendance-card-row">
                                <div class="attendance-card-label">Date</div>
                                <div class="attendance-card-value"><?= date('M j, Y', strtotime($attendance['check_in_time'])) ?></div>
                            </div>
                            <div class="attendance-card-row">
                                <div class="attendance-card-label">Check-in Time</div>
                                <div class="attendance-card-value"><?= date('g:i A', strtotime($attendance['check_in_time'])) ?></div>
                            </div>
                            <div class="attendance-card-row">
                                <div class="attendance-card-label">Method</div>
                                <div class="attendance-card-value"><?= htmlspecialchars($method_text) ?></div>
                            </div>
                            <div class="attendance-card-row">
                                <div class="attendance-card-label">Recorded By</div>
                                <div class="attendance-card-value">
                                    <?php if ($attendance['staff_name']): ?>
                                        <?= htmlspecialchars($attendance['staff_name']) ?> (<?= ucfirst(htmlspecialchars($attendance['staff_role'])) ?>)
                                    <?php else: ?>
                                        Self Check-in
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="attendance-card">
                        <div class="attendance-card-body">
                            <div class="no-data">
                                No attendance records found for the selected date range.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>

