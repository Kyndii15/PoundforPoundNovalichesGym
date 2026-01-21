<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include '../config.php';
include '../includes/activity_logger.php';

// Start role-specific session to access session variables
session_name('gym_admin_session');
session_start();

include '../auth_check.php';

// Require admin role for activity logs access
requireRole('admin');

// Set Philippine timezone
date_default_timezone_set('Asia/Manila');

// Get filter parameters
$filter_date = isset($_GET['date']) ? trim($_GET['date']) : '';
$filter_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination settings
$records_per_page = 50;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Build WHERE clause for filters
$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($filter_date)) {
    $where_conditions[] = "DATE(al.created_at) = ?";
    $params[] = $filter_date;
    $param_types .= 's';
}

if (!empty($filter_type)) {
    $where_conditions[] = "al.activity_type = ?";
    $params[] = $filter_type;
    $param_types .= 's';
}

if (!empty($search_query)) {
    $where_conditions[] = "(al.description LIKE ? OR u.full_name LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'ss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count of activity logs with filters
$count_query = "SELECT COUNT(*) as total 
                FROM activity_log al
                LEFT JOIN users u ON al.user_id = u.id
                $where_clause";
                
if (!empty($params)) {
    $count_stmt = mysqli_prepare($conn, $count_query);
    mysqli_stmt_bind_param($count_stmt, $param_types, ...$params);
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
    $total_records = mysqli_fetch_assoc($count_result)['total'];
    mysqli_stmt_close($count_stmt);
} else {
    $count_result = mysqli_query($conn, $count_query);
    $total_records = mysqli_fetch_assoc($count_result)['total'];
}

$total_pages = ceil($total_records / $records_per_page);

// Fetch activity logs with pagination and filters
$query = "SELECT al.*, u.full_name as user_name, u.role as user_role
          FROM activity_log al
          LEFT JOIN users u ON al.user_id = u.id
          $where_clause
          ORDER BY al.created_at DESC
          LIMIT ? OFFSET ?";

// Add pagination parameters
$params[] = $records_per_page;
$params[] = $offset;
$param_types .= 'ii';

$stmt = mysqli_prepare($conn, $query);
if (!empty($param_types)) {
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
} else {
    mysqli_stmt_bind_param($stmt, "ii", $records_per_page, $offset);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$activity_logs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $activity_logs[] = $row;
}
mysqli_stmt_close($stmt);

// Get unique activity types for filter dropdown
$types_query = "SELECT DISTINCT activity_type FROM activity_log ORDER BY activity_type";
$types_result = mysqli_query($conn, $types_query);
$activity_types = [];
while ($type_row = mysqli_fetch_assoc($types_result)) {
    $activity_types[] = $type_row['activity_type'];
}

// Map activity types to user-friendly names
$type_labels = [
    'attendance_recording' => 'Attendance',
    'payment_transaction' => 'Payments',
    'walkin_recording' => 'Walk-ins',
    'new_coach_scheduling' => 'Schedules',
    'deleted_coach_schedule' => 'Schedules',
    'new_member' => 'Members',
    'processed_membership' => 'Memberships',
    'new_user_account' => 'User Accounts'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - Admin Dashboard</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/user.css">
    <script type="text/javascript" src="../java/admin.js?v=2.0" defer></script>
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
            color: var(--text-clr);
            margin: 0;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: #4c52e8;
        }

        .activity-logs-container {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--line-clr);
        }

        .activity-logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .activity-logs-header h2 {
            font-size: 1.25rem;
            color: var(--text-clr);
            margin: 0;
        }

        .total-count {
            color: var(--secondary-text-clr);
            font-size: 0.9rem;
        }

        .activity-logs-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .activity-log-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            border: 1px solid rgba(94, 99, 255, 0.08);
            transition: all 0.3s ease;
        }

        .activity-log-item:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(94, 99, 255, 0.2);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .activity-description {
            color: var(--text-clr);
            font-size: 0.95rem;
            font-weight: 500;
            line-height: 1.4;
        }

        .activity-meta {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--secondary-text-clr);
            flex-wrap: wrap;
        }

        .activity-separator {
            color: var(--secondary-text-clr);
            opacity: 0.5;
        }

        .activity-time,
        .activity-user {
            color: var(--secondary-text-clr);
        }

        .activity-type-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            background: rgba(94, 99, 255, 0.1);
            color: var(--accent);
        }

        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            background: var(--card-bg);
            color: var(--text-clr);
            border: 1px solid var(--line-clr);
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .pagination-btn:hover:not(.disabled):not(.active) {
            background: rgba(94, 99, 255, 0.1);
            border-color: var(--accent);
        }

        .pagination-btn.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--secondary-text-clr);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .filters-section {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--line-clr);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filters-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-clr);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.875rem;
            color: var(--secondary-text-clr);
            font-weight: 500;
        }

        .filter-input {
            padding: 0.625rem 1rem;
            background: #424242;
            border: 1px solid var(--line-clr);
            border-radius: 6px;
            color: var(--text-clr);
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .filter-select {
            padding: 0.625rem 1rem;
            background: #424242;
            border: 1px solid var(--line-clr);
            border-radius: 6px;
            color: var(--text-clr);
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--accent);
            background: rgba(94, 99, 255, 0.05);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--accent);
            background: #5a5a5a;
        }

        .filter-input::placeholder {
            color: var(--secondary-text-clr);
            opacity: 0.6;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        .filter-btn {
            padding: 0.625rem 1.5rem;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .filter-btn:hover {
            background: #4c52e8;
        }

        .filter-btn.secondary {
            background: transparent;
            color: var(--text-clr);
            border: 1px solid var(--line-clr);
            padding: 0.625rem 1.5rem;
        }

        .filter-btn.secondary:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--accent);
        }

        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--line-clr);
        }

        .active-filter-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            background: rgba(94, 99, 255, 0.1);
            color: var(--accent);
            border-radius: 4px;
            font-size: 0.8rem;
        }

        .active-filter-badge .remove-filter {
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .active-filter-badge .remove-filter:hover {
            opacity: 1;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .activity-log-item {
                padding: 0.75rem;
                gap: 0.75rem;
            }

            .activity-icon {
                width: 32px;
                height: 32px;
                font-size: 1rem;
            }

            .activity-description {
                font-size: 0.875rem;
            }

            .activity-meta {
                font-size: 0.75rem;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                width: 100%;
            }

            .filter-btn {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <main>
        <div class="page-header">
            <h1>Activity Logs</h1>
            <a href="profile.php" class="back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" height="18" width="18" viewBox="0 0 512 512">
                    <path fill="currentColor" d="M9.4 233.4c-12.5 12.5-12.5 32.8 0 45.3l128 128c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L109.3 288 480 288c17.7 0 32-14.3 32-32s-14.3-32-32-32l-370.7 0 73.4-73.4c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0l-128 128z"/>
                </svg>
                <span>Back to Profile</span>
            </a>
        </div>

        <div class="activity-logs-container">
            <div class="activity-logs-header">
                <div>
                    <h2>All Activity Logs</h2>
                    <p class="total-count">Total: <?= number_format($total_records) ?> records</p>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filters-section">
                <div class="filters-title">
                    <i class='bx bx-filter-alt'></i>
                    <span>Filters</span>
                </div>
                <form method="GET" action="activity_logs.php" id="filter-form">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label" for="filter-date">Date</label>
                            <input type="date" 
                                   id="filter-date" 
                                   name="date" 
                                   class="filter-input" 
                                   value="<?= htmlspecialchars($filter_date) ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="filter-type">Activity Type</label>
                            <select id="filter-type" name="type" class="filter-select">
                                <option value="">All Types</option>
                                <?php foreach ($activity_types as $type): 
                                    $label = isset($type_labels[$type]) ? $type_labels[$type] : ucfirst(str_replace('_', ' ', $type));
                                ?>
                                    <option value="<?= htmlspecialchars($type) ?>" <?= $filter_type === $type ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label" for="filter-search">Search</label>
                            <input type="text" 
                                   id="filter-search" 
                                   name="search" 
                                   class="filter-input" 
                                   placeholder="Search by description or user name..."
                                   value="<?= htmlspecialchars($search_query) ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">&nbsp;</label>
                            <div class="filter-actions">
                                <button type="submit" class="filter-btn">
                                    <i class='bx bx-search'></i> Apply Filters
                                </button>
                                <a href="activity_logs.php" class="filter-btn secondary" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">
                                    <i class='bx bx-x'></i> Clear
                                </a>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Active Filters Display -->
                <?php if (!empty($filter_date) || !empty($filter_type) || !empty($search_query)): ?>
                    <div class="active-filters">
                        <span style="font-size: 0.875rem; color: var(--secondary-text-clr);">Active filters:</span>
                        <?php if (!empty($filter_date)): 
                            $remove_date_params = array_filter($_GET, function($key) {
                                return $key !== 'date' && $key !== 'page';
                            }, ARRAY_FILTER_USE_KEY);
                            if (!empty($remove_date_params)) {
                                $remove_date_url = '?' . http_build_query($remove_date_params);
                            } else {
                                $remove_date_url = 'activity_logs.php';
                            }
                        ?>
                            <span class="active-filter-badge">
                                Date: <?= htmlspecialchars($filter_date) ?>
                                <a href="<?= $remove_date_url ?>" class="remove-filter">
                                    <i class='bx bx-x'></i>
                                </a>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($filter_type)): 
                            $type_label = isset($type_labels[$filter_type]) ? $type_labels[$filter_type] : ucfirst(str_replace('_', ' ', $filter_type));
                            $remove_type_params = array_filter($_GET, function($key) {
                                return $key !== 'type' && $key !== 'page';
                            }, ARRAY_FILTER_USE_KEY);
                            if (!empty($remove_type_params)) {
                                $remove_type_url = '?' . http_build_query($remove_type_params);
                            } else {
                                $remove_type_url = 'activity_logs.php';
                            }
                        ?>
                            <span class="active-filter-badge">
                                Type: <?= htmlspecialchars($type_label) ?>
                                <a href="<?= $remove_type_url ?>" class="remove-filter">
                                    <i class='bx bx-x'></i>
                                </a>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($search_query)): 
                            $remove_search_params = array_filter($_GET, function($key) {
                                return $key !== 'search' && $key !== 'page';
                            }, ARRAY_FILTER_USE_KEY);
                            if (!empty($remove_search_params)) {
                                $remove_search_url = '?' . http_build_query($remove_search_params);
                            } else {
                                $remove_search_url = 'activity_logs.php';
                            }
                        ?>
                            <span class="active-filter-badge">
                                Search: "<?= htmlspecialchars($search_query) ?>"
                                <a href="<?= $remove_search_url ?>" class="remove-filter">
                                    <i class='bx bx-x'></i>
                                </a>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($activity_logs)): ?>
                <div class="activity-logs-list">
                    <?php foreach ($activity_logs as $activity): ?>
                        <?php
                        // Determine icon and color based on activity type
                        $icon = 'bx-info-circle';
                        $icon_color = '#6c757d';
                        
                        switch($activity['activity_type']) {
                            case 'payment_transaction':
                                $icon = 'bx-credit-card';
                                $icon_color = '#28a745';
                                break;
                            case 'new_member':
                                $icon = 'bx-user-plus';
                                $icon_color = '#17a2b8';
                                break;
                            case 'new_user_account':
                                $icon = 'bx-user-circle';
                                $icon_color = '#6f42c1';
                                break;
                            case 'new_coach_scheduling':
                                $icon = 'bx-calendar-plus';
                                $icon_color = '#ffc107';
                                break;
                            case 'deleted_coach_schedule':
                                $icon = 'bx-calendar-x';
                                $icon_color = '#dc3545';
                                break;
                            case 'attendance_recording':
                                $icon = 'bx-check-circle';
                                $icon_color = '#17a2b8';
                                break;
                            case 'processed_membership':
                                $icon = 'bx-check-square';
                                $icon_color = '#28a745';
                                break;
                            case 'walkin_recording':
                                $icon = 'bx-walk';
                                $icon_color = '#17a2b8';
                                break;
                        }
                        
                        // Format timestamp in Philippine Time
                        date_default_timezone_set('Asia/Manila');
                        $timestamp = strtotime($activity['created_at']);
                        $activity_time = date('F j, Y \a\t g:i A', $timestamp);
                        
                        $performed_by = $activity['user_name'] ? "by {$activity['user_name']}" : "by System";
                        ?>
                        <div class="activity-log-item">
                            <div class="activity-icon" style="background: <?= $icon_color ?>20; color: <?= $icon_color ?>;">
                                <i class='bx <?= $icon ?>'></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-description"><?= htmlspecialchars($activity['description']) ?></div>
                                <div class="activity-meta">
                                    <span class="activity-time"><?= $activity_time ?></span>
                                    <span class="activity-separator">•</span>
                                    <span class="activity-user"><?= $performed_by ?></span>
                                    <?php if ($activity['activity_type']): ?>
                                        <span class="activity-separator">•</span>
                                        <span class="activity-type-badge"><?= htmlspecialchars($activity['activity_type']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): 
                    // Build query string for pagination (preserve filters)
                    $query_params = [];
                    if (!empty($filter_date)) $query_params['date'] = $filter_date;
                    if (!empty($filter_type)) $query_params['type'] = $filter_type;
                    if (!empty($search_query)) $query_params['search'] = $search_query;
                ?>
                    <div class="pagination-container">
                        <?php if ($current_page > 1): 
                            $prev_params = array_merge($query_params, ['page' => $current_page - 1]);
                        ?>
                            <a href="?<?= http_build_query($prev_params) ?>" class="pagination-btn">Previous</a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">Previous</span>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        if ($start_page > 1): 
                            $first_params = array_merge($query_params, ['page' => 1]);
                        ?>
                            <a href="?<?= http_build_query($first_params) ?>" class="pagination-btn">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="pagination-btn disabled">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): 
                            $page_params = array_merge($query_params, ['page' => $i]);
                        ?>
                            <a href="?<?= http_build_query($page_params) ?>" class="pagination-btn <?= $i == $current_page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): 
                            $last_params = array_merge($query_params, ['page' => $total_pages]);
                        ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="pagination-btn disabled">...</span>
                            <?php endif; ?>
                            <a href="?<?= http_build_query($last_params) ?>" class="pagination-btn"><?= $total_pages ?></a>
                        <?php endif; ?>

                        <?php if ($current_page < $total_pages): 
                            $next_params = array_merge($query_params, ['page' => $current_page + 1]);
                        ?>
                            <a href="?<?= http_build_query($next_params) ?>" class="pagination-btn">Next</a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">Next</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class='bx bx-info-circle'></i>
                    <p>No activity logs available yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

