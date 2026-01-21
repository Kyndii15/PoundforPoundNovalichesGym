<?php
include '../config.php';

// Auto-check for membership expiry notifications (runs hourly, prevents duplicate emails per subscription)
if (file_exists(__DIR__ . '/../includes/auto_check_expiry_notifications.php')) {
    @include_once __DIR__ . '/../includes/auto_check_expiry_notifications.php';
}

include 'navbar.php';

$filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Priority: 1) Active subscription with future expiry, 2) Most recent subscription
$sql = "
  SELECT 
    u.id,
    u.full_name,
    u.email,
    u.created_at,
    u.archived,
    m.joined_at,
    m.status as original_status,
    m.phone,
    m.address,
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
    TRIM(SUBSTRING_INDEX(u.full_name, ' ', CHAR_LENGTH(u.full_name) - CHAR_LENGTH(REPLACE(u.full_name, ' ', '')))) as first_and_middle_name,
    CASE 
      WHEN (
        SELECT COUNT(*) 
        FROM attendance_log al 
        WHERE al.user_id = u.id 
        AND al.date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
      ) > 0 THEN 'active'
      ELSE 'inactive'
    END as status
  FROM users u
  INNER JOIN members m ON u.id = m.user_id
  WHERE u.role = 'customer'
";

if ($filter) {
  if ($filter === 'active') {
    $sql .= " AND EXISTS (
      SELECT 1 FROM subscriptions s1 
      INNER JOIN members m1 ON s1.member_id = m1.id 
      WHERE m1.user_id = u.id
      AND s1.status = 'active' 
      AND s1.expiry_date > CURDATE()
      AND s1.plan_name IS NOT NULL
    )";
  } elseif ($filter === 'expired') {
    $sql .= " AND (
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
      ) IS NULL 
      OR COALESCE(
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
      ) <= CURDATE() 
      OR COALESCE(
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
      ) != 'active'
    )";
  }
}

if ($search) {
  $search = mysqli_real_escape_string($conn, $search);
  $sql .= " AND (u.full_name LIKE '%$search%' OR u.email LIKE '%$search%' OR m.phone LIKE '%$search%')";
}

$sql .= " ORDER BY u.archived ASC, surname ASC, first_and_middle_name ASC";

$result = mysqli_query($conn, $sql);

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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Member Management</title>
  <style>
    :root {
      --bg-clr: #0c1118;
      --accent-clr: #5b61ff;
      --line-clr: #3c3d4a;
      --text-clr: #ffffff;
      --secondary-text-clr: #d1d1d1;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: "Poppins", sans-serif;
      background-color: var(--bg-clr);
      color: var(--text-clr);
    }

    main {
      padding: 20px;
    }

    h1 {
      margin-bottom: 20px;
      font-size: 1.8rem;
    }

    .actions {
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 15px;
      flex-wrap: wrap;
    }

    .actions .btn {
      padding: 10px 16px;
      background: var(--accent-clr);
      color: white;
      text-decoration: none;
      border-radius: 6px;
      transition: background 0.3s ease;
    }

    .actions .btn:hover {
      background: #6d73ff;
    }

    /* Bulk Actions Styling */
    .bulk-actions {
      margin-bottom: 20px;
      padding: 15px;
      background: #1a1b23;
      border: 1px solid var(--line-clr);
      border-radius: 8px;
      display: none;
    }

    .bulk-actions.show {
      display: block;
    }

    .bulk-actions .bulk-info {
      color: var(--secondary-text-clr);
      margin-bottom: 10px;
      font-size: 0.9rem;
    }

    .bulk-actions .bulk-buttons {
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .bulk-actions .btn-bulk {
      padding: 8px 16px;
      background: #dc3545;
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-size: 0.9rem;
      transition: background 0.3s ease;
    }

    .bulk-actions .btn-bulk:hover {
      background: #c82333;
    }

    .bulk-actions .btn-bulk:not(:disabled) {
      background: #dc3545;
    }

    .bulk-actions .btn-bulk:disabled {
      background: #6c757d;
      cursor: not-allowed;
    }

    /* Checkbox Styling */
    .checkbox-cell {
      width: 40px;
      text-align: center;
    }

    .checkbox-cell input[type="checkbox"] {
      width: 18px;
      height: 18px;
      cursor: pointer;
    }

    /* Search Bar Styling */
    .search-container {
      margin-bottom: 20px;
    }

    .search-form {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .search-input-group {
      position: relative;
      display: flex;
      align-items: center;
      background: #1a1b23;
      border: 1px solid var(--line-clr);
      border-radius: 8px;
      overflow: hidden;
      min-width: 500px;
    }

    .search-input-group input {
      flex: 1;
      padding: 12px 16px;
      background: transparent;
      border: none;
      color: var(--text-clr);
      font-size: 14px;
      outline: none;
    }

    .search-input-group input::placeholder {
      color: var(--secondary-text-clr);
    }

    .search-btn {
      padding: 12px 16px;
      background: var(--accent-clr);
      border: none;
      color: white;
      cursor: pointer;
      transition: background 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .search-btn:hover {
      background: #6d73ff;
    }

    .clear-search {
      color: var(--accent-clr);
      text-decoration: none;
      font-size: 14px;
      padding: 8px 12px;
      border: 1px solid var(--accent-clr);
      border-radius: 6px;
      transition: all 0.3s ease;
    }

    .clear-search:hover {
      background: var(--accent-clr);
      color: white;
    }

    .actions label {
      color: var(--text-clr);
      font-size: 0.95rem;
      font-weight: 500;
      margin-right: 8px;
    }

    .actions select {
      padding: 8px;
      background: #1e1f27;
      color: white;
      border: 1px solid var(--line-clr);
      border-radius: 4px;
    }

    .table-wrapper {
      overflow-x: auto;
      width: 100%;
      max-width: 100%;
      -webkit-overflow-scrolling: touch;
      box-sizing: border-box;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      background: #1c1d25;
    }

    thead th {
      background: #2a2b36;
      padding: 12px;
      border: 1px solid var(--line-clr);
      font-size: 0.95rem;
      text-align: center;
    }

    tbody td {
      padding: 12px;
      border: 1px solid var(--line-clr);
      text-align: center;
      font-size: 0.95rem;
    }

    tbody tr:hover {
      background-color: #2d2e3b;
    }

    a.action-link {
      color: #4fc3f7;
      text-decoration: none;
      margin: 0 4px;
    }

    a.action-link:hover {
      text-decoration: underline;
    }

    .archived {
      color: #f76c6c;
    }

    .unarchive {
      color: #81c784;
    }

    /* Action Button Styles */
    .action-btn {
      display: inline-block;
      width: 70px;
      text-align: center;
      padding: 4px 8px;
      border-radius: 4px;
      text-decoration: none;
      font-weight: 500;
      margin-right: 5px;
      font-size: 0.85rem;
      transition: all 0.3s ease;
      box-sizing: border-box;
    }

    .view-btn {
      background: var(--accent-clr);
      color: white;
    }

    .view-btn:hover {
      background: #6d73ff;
      transform: translateY(-1px);
    }

    .archive-btn {
      background: crimson;
      color: white;
    }

    .archive-btn:hover {
      background: #b71c1c;
      transform: translateY(-1px);
    }

    .unarchive-btn {
      background: #28a745;
      color: white;
      width: 80px;
    }

    .unarchive-btn:hover {
      background: #1e7e34;
      transform: translateY(-1px);
    }

    .empty-row {
      padding: 20px;
      text-align: center;
      color: var(--text-clr);
    }

    /* Make Actions column wider - Desktop only */
    @media (min-width: 769px) {
      th:nth-child(6), td:nth-child(6) {
        width: 200px;
        min-width: 200px;
      }

      /* Full Name column styling - Desktop only */
      th:nth-child(2), td:nth-child(2) {
        width: 300px;
        min-width: 300px;
        text-align: center;
        padding-left: 15px;
      }
    }

    /* Make table more compact */
    thead th {
      padding: 8px 12px;
      font-size: 0.9rem;
    }

    tbody td {
      padding: 8px 12px;
      font-size: 0.9rem;
    }

    @media (max-width: 768px) {
      /* Mobile Styles */
      body {
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
      }

      body::-webkit-scrollbar {
        display: none;
      }

      main {
        padding: 0.75rem;
        width: 100%;
        max-width: 100vw;
        box-sizing: border-box;
        overflow-x: hidden;
        margin: 0;
        scrollbar-width: none;
        -ms-overflow-style: none;
      }

      main::-webkit-scrollbar {
        display: none;
      }

      h1 {
        font-size: 1.1rem;
        margin-bottom: 0.75rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
        padding-right: 0;
      }

      .search-container {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        margin-bottom: 0.75rem;
      }

      .search-form {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .search-input-group {
        min-width: 100%;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .actions {
        flex-direction: column;
        align-items: flex-start;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        margin-bottom: 0.75rem;
        gap: 0.5rem;
      }

      .actions form {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .bulk-actions {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        margin-bottom: 0.75rem;
        padding: 0.75rem;
      }

      .bulk-actions .bulk-buttons {
        flex-direction: column;
        width: 100%;
      }

      .bulk-actions .btn-bulk {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .table-wrapper {
        overflow-x: visible;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      table {
        min-width: 0;
        width: 100%;
        font-size: 0.7rem;
        box-sizing: border-box;
        table-layout: fixed;
        margin: 0;
        border-spacing: 0;
      }

      thead th, tbody td {
        padding: 0.4rem 0.3rem;
        font-size: 0.7rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
        text-overflow: ellipsis;
      }

      /* Column width distribution for mobile */
      .checkbox-cell {
        width: 8%;
        min-width: 30px;
      }

      th:nth-child(2), td:nth-child(2) {
        width: 18%;
        text-align: center;
        padding-left: 0.3rem;
      }

      th:nth-child(3), td:nth-child(3) {
        width: 20%;
      }

      th:nth-child(4), td:nth-child(4) {
        width: 22%;
        font-size: 0.65rem;
      }

      th:nth-child(5), td:nth-child(5) {
        width: 15%;
        font-size: 0.65rem;
      }

      th:nth-child(6), td:nth-child(6) {
        width: 17%;
      }

      .action-btn {
        width: auto;
        min-width: 50px;
        font-size: 0.65rem;
        padding: 3px 5px;
        margin-right: 2px;
      }

      /* Ensure all text elements don't overflow */
      h1,
      .search-input-group input,
      .actions label,
      thead th,
      tbody td {
        word-wrap: break-word;
        overflow-wrap: break-word;
        max-width: 100%;
      }
    }

    @media (max-width: 480px) {
      /* Small Mobile Styles */
      body {
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
      }

      body::-webkit-scrollbar {
        display: none;
      }

      main {
        padding: 0.75rem;
        width: 100%;
        max-width: 100vw;
        box-sizing: border-box;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
      }

      main::-webkit-scrollbar {
        display: none;
      }

      main h1 {
        font-size: 1rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
        padding-right: 0;
      }

      .search-input-group {
        min-width: 100%;
      }

      .search-input-group input {
        font-size: 0.875rem;
        padding: 10px 12px;
      }

      .search-btn {
        padding: 10px 12px;
      }

      .actions .btn {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        text-align: center;
      }

      .actions select {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .table-wrapper {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        overflow-x: visible;
      }

      table {
        min-width: 0;
        width: 100%;
        font-size: 0.65rem;
        table-layout: fixed;
        margin: 0;
        border-spacing: 0;
      }

      thead th, tbody td {
        padding: 0.35rem 0.25rem;
        font-size: 0.65rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
        text-overflow: ellipsis;
      }

      /* Column width distribution for small mobile */
      .checkbox-cell {
        width: 8%;
        min-width: 25px;
      }

      th:nth-child(2), td:nth-child(2) {
        width: 18%;
        font-size: 0.6rem;
        text-align: center;
        padding-left: 0.25rem;
      }

      th:nth-child(3), td:nth-child(3) {
        width: 20%;
        font-size: 0.6rem;
      }

      th:nth-child(4), td:nth-child(4) {
        width: 22%;
        font-size: 0.55rem;
      }

      th:nth-child(5), td:nth-child(5) {
        width: 15%;
        font-size: 0.55rem;
      }

      th:nth-child(6), td:nth-child(6) {
        width: 17%;
      }

      .action-btn {
        font-size: 0.6rem;
        padding: 2px 4px;
        min-width: 45px;
      }

      .empty-row {
        padding: 1.5rem;
        font-size: 0.875rem;
        word-wrap: break-word;
      }
    }
  </style>
</head>
<body>
  <main>
    <h1>Member Management</h1>

    <div class="search-container">
      <div class="search-form">
        <div class="search-input-group">
          <input type="text" name="search" id="search" placeholder="Search by name, email, or phone..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
          <button type="button" class="search-btn" onclick="clearSearch()">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.35-4.35"></path>
            </svg>
          </button>
        </div>
        <div style="display: flex; align-items: center; gap: 10px; margin-left: 10px;">
          <span id="searchResults" style="font-size: 14px; color: var(--secondary-text-clr);"></span>
        </div>
      </div>
    </div>

    <div class="actions">
      <a href="member__form.php" class="btn" target="_self">Add New Member</a>
      <form method="GET">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <label for="status">Filter by Status:</label>
        <select name="status" id="status" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="active" <?= $filter == 'active' ? 'selected' : '' ?>>Active</option>
          <option value="expired" <?= $filter == 'expired' ? 'selected' : '' ?>>Expired</option>
        </select>
      </form>
    </div>

    <!-- Bulk Actions Section -->
    <div id="bulkActions" class="bulk-actions">
      <div class="bulk-info">
        <span id="selectedCount">0</span> member(s) selected
      </div>
      <div class="bulk-buttons">
        <button type="button" class="btn-bulk" onclick="bulkArchive()" id="bulkArchiveBtn" disabled>
          Archive Selected
        </button>
        <button type="button" class="btn-bulk" onclick="bulkUnarchive()" id="bulkUnarchiveBtn" disabled style="background: #28a745;">
          Unarchive Selected
        </button>
        <button type="button" class="btn-bulk" onclick="clearSelection()" style="background: #6c757d;">
          Clear Selection
        </button>
      </div>
    </div>

    <div class="table-wrapper" style="overflow-x: auto;">
      <table>
        <thead>
          <tr>
            <th class="checkbox-cell">
              <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
            </th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Membership Plan</th>
            <th>Joined</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="memberTableBody">
          <?php if (mysqli_num_rows($result) > 0): ?>
            <?php while($member = mysqli_fetch_assoc($result)): ?>
              <tr class="member-row" data-name="<?= htmlspecialchars(strtolower($member['surname'] . ' ' . $member['first_and_middle_name'])) ?>" data-email="<?= htmlspecialchars(strtolower($member['email'])) ?>" data-phone="<?= htmlspecialchars(strtolower($member['phone'] ?? '')) ?>">
                <td class="checkbox-cell">
                  <input type="checkbox" class="member-checkbox" value="<?= $member['id'] ?>" onchange="updateBulkActions()" data-archived="<?= $member['archived'] ? 'true' : 'false' ?>">
                </td>
                <td><?= htmlspecialchars($member['surname'] . ', ' . $member['first_and_middle_name']) ?></td>
                <td><?= htmlspecialchars($member['email']) ?></td>
                <td>
                  <?php if ($member['plan_name'] && $member['subscription_status'] == 'active' && strtotime($member['expiry_date']) > time()): ?>
                    <?php $packageColor = getPackageColor($member['plan_name']); ?>
                    <div style="font-weight: 500; color: <?= $packageColor ?: 'var(--accent-clr)' ?>;"><?= htmlspecialchars($member['plan_name']) ?></div>
                    <div style="font-size: 0.8em; color: var(--secondary-text-clr);">
                      Expires: <?= date('M j, Y', strtotime($member['expiry_date'])) ?>
                    </div>
                  <?php else: ?>
                    <span style="color: var(--secondary-text-clr);">None</span>
                  <?php endif; ?>
                </td>
                <td><?= date('F j, Y \a\t g:i A', strtotime($member['created_at'])) ?></td>
                <td>
                  <a href="#" onclick="viewMember(<?= $member['id'] ?>); return false;"
                    class="action-btn view-btn">
                    View
                  </a>
                  <?php if (!$member['archived']): ?>
                    <a href="user__actions.php?action=archive&id=<?= $member['id'] ?>"
                      onclick="return confirm('Archive this member?');"
                      class="action-btn archive-btn">
                      Archive
                    </a>
                  <?php else: ?>
                    <a href="user__actions.php?action=unarchive&id=<?= $member['id'] ?>"
                      onclick="return confirm('Unarchive this member?');"
                      class="action-btn unarchive-btn">
                      Unarchive
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="6" class="empty-row">No members found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>

  <!-- Member Profile Modal -->
  <div id="memberModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(4px);">
    <div class="modal-content" style="background: linear-gradient(135deg, #1c1d25 0%, #2a2b36 100%); margin: 2% auto; padding: 0; border-radius: 16px; width: 95%; max-width: 900px; height: 90vh; box-shadow: 0 20px 60px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column;">
      <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); background: linear-gradient(135deg, #2a2b36 0%, #1c1d25 100%); border-radius: 16px 16px 0 0; flex-shrink: 0;">
        <h2 style="margin: 0; color: var(--accent-clr); font-size: 1.3rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
          <i class='bx bx-user' style="font-size: 1.3rem;"></i>
          Member Profile
        </h2>
        <span class="close" onclick="closeMemberModal()" style="color: #aaa; font-size: 24px; font-weight: bold; cursor: pointer; padding: 4px; border-radius: 50%; transition: all 0.3s ease; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='rgba(255,255,255,0.1)'; this.style.color='white';" onmouseout="this.style.background='transparent'; this.style.color='#aaa';">&times;</span>
      </div>
      <div id="memberContent" style="padding: 20px; flex: 1; overflow: hidden;">
        <!-- Member details will be loaded here -->
      </div>
    </div>
  </div>

  <script>
    function viewMember(memberId) {
      // Show loading
      document.getElementById('memberContent').innerHTML = '<div style="text-align: center; padding: 40px; color: #ccc; font-size: 1.1rem;"><i class="bx bx-loader-alt bx-spin" style="font-size: 2rem; margin-bottom: 15px; display: block; color: var(--accent-clr);"></i>Loading member details...</div>';
      document.getElementById('memberModal').style.display = 'block';
      
      // Fetch member details
      fetch(`get_member_details.php?id=${memberId}`)
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            const member = data.member;
            document.getElementById('memberContent').innerHTML = `
              <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; height: 100%;">
                <!-- Personal Info Card -->
                <div style="background: rgba(255,255,255,0.05); border-radius: 12px; padding: 12px; border: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column;">
                  <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <i class='bx bx-user-circle' style="font-size: 1.2rem; color: var(--accent-clr);"></i>
                    <h3 style="color: var(--accent-clr); margin: 0; font-size: 0.95rem; font-weight: 600;">Personal Info</h3>
                  </div>
                  <div style="display: flex; flex-direction: column; gap: 8px; flex: 1;">
                    <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Full Name</div>
                      <div style="color: white; font-size: 0.85rem; font-weight: 600;">${member.surname}, ${member.first_name}</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Email</div>
                      <div style="color: white; font-size: 0.85rem;">${member.email}</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Phone</div>
                      <div style="color: white; font-size: 0.85rem;">${member.phone || 'N/A'}</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Address</div>
                      <div style="color: white; font-size: 0.85rem;">${member.address || 'N/A'}</div>
                    </div>
                  </div>
                </div>
                
                <!-- Membership Info Card -->
                <div style="background: rgba(255,255,255,0.05); border-radius: 12px; padding: 12px; border: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column;">
                  <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <i class='bx bx-id-card' style="font-size: 1.2rem; color: #4caf50;"></i>
                    <h3 style="color: #4caf50; margin: 0; font-size: 0.95rem; font-weight: 600;">Membership</h3>
                  </div>
                  <div style="display: flex; flex-direction: column; gap: 8px; flex: 1;">
                    <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Plan</div>
                      <div style="font-size: 0.85rem; font-weight: 600;" id="memberPlanName">${member.plan_name || 'None'}</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Joined</div>
                      <div style="color: white; font-size: 0.85rem;">${member.joined_date}</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Expires</div>
                      <div style="color: white; font-size: 0.85rem;">${member.expiry_date || 'N/A'}</div>
                    </div>
                    ${member.remaining_days > 0 ? `<div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Remaining Days</div>
                      <div style="color: white; font-size: 0.85rem; font-weight: 600;">${member.remaining_days} days</div>
                    </div>` : ''}
                    <div style="margin-top: auto; padding-top: 12px;">
                      <button onclick="openPlanSelectionModal(${member.id}, ${member.member_id}, ${member.subscription_id || 'null'}, ${member.has_active_plan ? 'true' : 'false'}, '${member.plan_name || ''}', ${member.current_plan_price || 0}, ${member.remaining_days || 0})" 
                              style="width: 100%; padding: 10px; background: ${member.has_active_plan ? 'linear-gradient(135deg, #ff9800 0%, #f57c00 100%)' : 'linear-gradient(135deg, #4caf50 0%, #388e3c 100%)'}; color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.2);" 
                              onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(0,0,0,0.3)';" 
                              onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0,0,0,0.2)';">
                        <i class='bx ${member.has_active_plan ? 'bx-refresh' : 'bx-plus-circle'}' style="margin-right: 6px;"></i>
                        ${member.has_active_plan ? 'Change Plan' : 'Avail Plan'}
                      </button>
                    </div>
                  </div>
                </div>
                
                <!-- Activity Info Card -->
                <div style="background: rgba(255,255,255,0.05); border-radius: 12px; padding: 12px; border: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column;">
                  <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <i class='bx bx-calendar-check' style="font-size: 1.2rem; color: #ff9800;"></i>
                    <h3 style="color: #ff9800; margin: 0; font-size: 0.95rem; font-weight: 600;">Activity</h3>
                  </div>
                  <div style="display: flex; flex-direction: column; gap: 8px; flex: 1;">
                    <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Last Visit</div>
                      <div style="font-size: 0.85rem; font-weight: 600; color: ${member.last_attendance === 'Never' ? '#ff9800' : '#4caf50'};">${member.last_attendance}</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.05); border-radius: 6px; padding: 8px;">
                      <div style="color: #b0b3c1; font-size: 0.75rem; font-weight: 500; margin-bottom: 2px;">Member Since</div>
                      <div style="color: white; font-size: 0.85rem; font-weight: 600;">${new Date(member.created_at || member.joined_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</div>
                    </div>
                  </div>
                </div>
              </div>
            `;
            // Apply package color to plan name in modal
            setTimeout(function() {
              const planNameElement = document.getElementById('memberPlanName');
              if (planNameElement && member.plan_name) {
                const planName = member.plan_name.toLowerCase();
                let packageColor = '';
                if (planName.includes('package 1')) {
                  packageColor = '#1A43BF';
                } else if (planName.includes('package 2')) {
                  packageColor = '#FFE135';
                } else if (planName.includes('package 3')) {
                  packageColor = '#03C03C';
                }
                if (packageColor) {
                  planNameElement.style.color = packageColor;
                } else {
                  planNameElement.style.color = 'white';
                }
              }
            }, 10);
          } else {
            document.getElementById('memberContent').innerHTML = '<div style="text-align: center; padding: 40px; color: #f44336; font-size: 1.1rem;"><i class="bx bx-error-circle" style="font-size: 2rem; margin-bottom: 15px; display: block;"></i>Error loading member details.</div>';
          }
        })
        .catch(error => {
          document.getElementById('memberContent').innerHTML = '<div style="text-align: center; padding: 40px; color: #f44336; font-size: 1.1rem;"><i class="bx bx-error-circle" style="font-size: 2rem; margin-bottom: 15px; display: block;"></i>Error loading member details.</div>';
        });
    }

    function closeMemberModal() {
      document.getElementById('memberModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('memberModal');
      if (event.target == modal) {
        closeMemberModal();
      }
    }

    const searchInput = document.getElementById('search');
    const memberTableBody = document.getElementById('memberTableBody');
    const searchResults = document.getElementById('searchResults');
    const allMemberRows = document.querySelectorAll('.member-row');

    // Live search functionality - filters table in real-time
    searchInput.addEventListener('input', function() {
      const searchTerm = this.value.toLowerCase().trim();
      let visibleCount = 0;
      
      allMemberRows.forEach(row => {
        const name = row.getAttribute('data-name');
        const email = row.getAttribute('data-email');
        const phone = row.getAttribute('data-phone');
        
        if (searchTerm === '' || name.includes(searchTerm) || email.includes(searchTerm) || phone.includes(searchTerm)) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });
      
      // Update search results counter
      if (searchTerm === '') {
        searchResults.textContent = '';
      } else {
        searchResults.textContent = `${visibleCount} result(s) found`;
      }
      
      // Show "No results" message if no matches
      const noResultsRow = memberTableBody.querySelector('.no-results-row');
      if (noResultsRow) {
        noResultsRow.remove();
      }
      
      if (visibleCount === 0 && searchTerm !== '') {
        const noResultsRow = document.createElement('tr');
        noResultsRow.className = 'no-results-row';
        noResultsRow.innerHTML = '<td colspan="6" class="empty-row">No members found matching your search</td>';
        memberTableBody.appendChild(noResultsRow);
      }
    });

    function clearSearch() {
      searchInput.value = '';
      searchInput.dispatchEvent(new Event('input'));
    }

    // Bulk Actions Functions
    function toggleSelectAll() {
      const selectAllCheckbox = document.getElementById('selectAll');
      const memberCheckboxes = document.querySelectorAll('.member-checkbox:not([disabled])');
      
      memberCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
      });
      
      updateBulkActions();
    }

    function updateBulkActions() {
      const selectedCheckboxes = document.querySelectorAll('.member-checkbox:checked');
      const bulkActions = document.getElementById('bulkActions');
      const selectedCount = document.getElementById('selectedCount');
      const bulkArchiveBtn = document.getElementById('bulkArchiveBtn');
      const bulkUnarchiveBtn = document.getElementById('bulkUnarchiveBtn');
      const selectAllCheckbox = document.getElementById('selectAll');
      
      const count = selectedCheckboxes.length;
      selectedCount.textContent = count;
      
      if (count > 0) {
        bulkActions.classList.add('show');
        
        // Check if any selected members are archived
        let hasArchived = false;
        let hasActive = false;
        
        selectedCheckboxes.forEach(checkbox => {
          const isArchived = checkbox.getAttribute('data-archived') === 'true';
          if (isArchived) {
            hasArchived = true;
          } else {
            hasActive = true;
          }
        });
        
        // Enable/disable buttons based on selection
        bulkArchiveBtn.disabled = !hasActive;
        bulkUnarchiveBtn.disabled = !hasArchived;
      } else {
        bulkActions.classList.remove('show');
        bulkArchiveBtn.disabled = true;
        bulkUnarchiveBtn.disabled = true;
      }
      
      // Update select all checkbox state
      const totalCheckboxes = document.querySelectorAll('.member-checkbox');
      if (count === 0) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = false;
      } else if (count === totalCheckboxes.length) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = true;
      } else {
        selectAllCheckbox.indeterminate = true;
      }
    }

    function clearSelection() {
      const selectAllCheckbox = document.getElementById('selectAll');
      const memberCheckboxes = document.querySelectorAll('.member-checkbox');
      
      selectAllCheckbox.checked = false;
      selectAllCheckbox.indeterminate = false;
      memberCheckboxes.forEach(checkbox => {
        checkbox.checked = false;
      });
      
      updateBulkActions();
    }

    function bulkArchive() {
      const selectedCheckboxes = document.querySelectorAll('.member-checkbox:checked');
      const memberIds = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
      
      if (memberIds.length === 0) {
        alert('Please select at least one member to archive.');
        return;
      }
      
      const confirmMessage = `Are you sure you want to archive ${memberIds.length} member(s)? This action cannot be undone.`;
      if (confirm(confirmMessage)) {
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'user__actions.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'bulk_archive';
        form.appendChild(actionInput);
        
        const idsInput = document.createElement('input');
        idsInput.type = 'hidden';
        idsInput.name = 'user_ids';
        idsInput.value = memberIds.join(',');
        form.appendChild(idsInput);
        
        document.body.appendChild(form);
        form.submit();
      }
    }

    function bulkUnarchive() {
      const selectedCheckboxes = document.querySelectorAll('.member-checkbox:checked');
      const memberIds = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
      
      if (memberIds.length === 0) {
        alert('Please select at least one member to unarchive.');
        return;
      }
      
      const confirmMessage = `Are you sure you want to unarchive ${memberIds.length} member(s)?`;
      if (confirm(confirmMessage)) {
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'user__actions.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'bulk_unarchive';
        form.appendChild(actionInput);
        
        const idsInput = document.createElement('input');
        idsInput.type = 'hidden';
        idsInput.name = 'user_ids';
        idsInput.value = memberIds.join(',');
        form.appendChild(idsInput);
        
        document.body.appendChild(form);
        form.submit();
      }
    }

    // Plan Selection Modal Functions
    let currentMemberData = null;

    function openPlanSelectionModal(userId, memberId, subscriptionId, hasActivePlan, currentPlanName, currentPlanPrice, remainingDays) {
      // Ensure currentPlanPrice is a number, not a string
      const parsedCurrentPlanPrice = parseFloat(currentPlanPrice) || 0;
      
      currentMemberData = {
        userId: userId,
        memberId: memberId,
        subscriptionId: subscriptionId,
        hasActivePlan: hasActivePlan,
        currentPlanName: currentPlanName,
        currentPlanPrice: parsedCurrentPlanPrice, // Store as parsed number
        remainingDays: parseInt(remainingDays) || 0
      };

      document.getElementById('planSelectionModal').style.display = 'block';
      document.getElementById('planSelectionContent').innerHTML = '<div style="text-align: center; padding: 40px; color: #ccc;"><i class="bx bx-loader-alt bx-spin" style="font-size: 2rem; margin-bottom: 15px; display: block; color: var(--accent-clr);"></i>Loading plans...</div>';

      // Fetch available plans
      fetch('get_available_plans.php')
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            displayPlans(data.plans);
          } else {
            document.getElementById('planSelectionContent').innerHTML = '<div style="text-align: center; padding: 40px; color: #f44336;">Error loading plans: ' + (data.message || 'Unknown error') + '</div>';
          }
        })
        .catch(error => {
          document.getElementById('planSelectionContent').innerHTML = '<div style="text-align: center; padding: 40px; color: #f44336;">Error loading plans. Please try again.</div>';
        });
    }

    function displayPlans(plans) {
      // Group all plans by package type (show all plans, not just one from each)
      const groupedPlans = {};
      plans.forEach(plan => {
        const packageType = plan.package_type || 'Other';
        if (!groupedPlans[packageType]) {
          groupedPlans[packageType] = {
            plans: [],
            package_info: plan.package_info || { title: packageType, inclusions: [] }
          };
        }
        groupedPlans[packageType].plans.push(plan);
      });

      let plansHtml = '';

      if (currentMemberData.hasActivePlan) {
        plansHtml += `
          <div style="background: rgba(255,152,0,0.1); border: 1px solid rgba(255,152,0,0.3); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <div style="color: #ff9800; font-weight: 600; margin-bottom: 8px;"><i class='bx bx-info-circle' style="margin-right: 6px;"></i>Current Plan</div>
            <div style="color: white; font-size: 0.9rem;">${currentMemberData.currentPlanName}</div>
            <div style="color: #b0b3c1; font-size: 0.8rem; margin-top: 4px;">Remaining Days: ${currentMemberData.remainingDays} days</div>
          </div>
        `;
      }

      // Display all package groups with all their plans
      Object.keys(groupedPlans).forEach(packageKey => {
        const packageGroup = groupedPlans[packageKey];
        const packageType = packageGroup.plans[0].package_type;
        const packageInfo = packageGroup.package_info;
        
        plansHtml += `
          <div style="margin-bottom: 30px;">
            <div style="margin-bottom: 15px;">
              <h3 style="color: var(--accent-clr); font-size: 1.2rem; font-weight: 600; margin: 0 0 8px 0;">${packageInfo.title || packageType}</h3>
              ${packageInfo.inclusions && packageInfo.inclusions.length > 0 ? `
                <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                  ${packageInfo.inclusions.map(inclusion => `
                    <span style="background: rgba(74,82,232,0.15); color: #5e63ff; padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; border: 1px solid rgba(74,82,232,0.3);">
                      ${inclusion}
                    </span>
                  `).join('')}
                </div>
              ` : ''}
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
        `;

        packageGroup.plans.forEach(plan => {
          const isCurrentPlan = currentMemberData.currentPlanName === plan.plan_name;
          const priceDiff = currentMemberData.hasActivePlan ? (plan.price - currentMemberData.currentPlanPrice) : plan.price;
          
          plansHtml += `
            <div style="background: rgba(255,255,255,0.05); border: 2px solid ${isCurrentPlan ? 'rgba(255,152,0,0.5)' : 'rgba(255,255,255,0.1)'}; border-radius: 12px; padding: 15px; cursor: ${isCurrentPlan ? 'not-allowed' : 'pointer'}; transition: all 0.3s ease; ${isCurrentPlan ? 'opacity: 0.6;' : ''}" 
                 ${!isCurrentPlan ? `onclick="selectPlan(${plan.id}, '${plan.plan_name.replace(/'/g, "\\'")}', ${plan.price})" onmouseover="this.style.borderColor='rgba(74,82,232,0.5)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.borderColor='rgba(255,255,255,0.1)'; this.style.transform='translateY(0)';"` : ''}>
              <div style="color: #b0b3c1; font-size: 0.75rem; margin-bottom: 6px;">${plan.duration_months || 1} Month${(plan.duration_months || 1) > 1 ? 's' : ''}</div>
              <div style="color: white; font-size: 0.85rem; margin-bottom: 4px; font-weight: 500;">${plan.plan_name}</div>
              <div style="color: #4caf50; font-size: 1.1rem; font-weight: 600; margin: 10px 0;">₱${plan.price.toLocaleString()}</div>
              ${currentMemberData.hasActivePlan ? `
                <div style="color: #b0b3c1; font-size: 0.75rem; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.1);">
                  ${priceDiff > 0 ? `Additional: ₱${priceDiff.toLocaleString()}` : 'No additional payment'}
                </div>
              ` : ''}
              ${isCurrentPlan ? '<div style="color: #ff9800; font-size: 0.75rem; margin-top: 8px;"><i class="bx bx-check-circle"></i> Current Plan</div>' : ''}
            </div>
          `;
        });

        plansHtml += `
            </div>
          </div>
        `;
      });

      document.getElementById('planSelectionContent').innerHTML = plansHtml;
    }

    function selectPlan(planId, planName, planPrice) {
      if (!currentMemberData) return;

      // ALWAYS subtract current plan price from new plan price to get the amount to pay
      // Example: Boxing (2,500) to Muay Thai (3,000) = 3,000 - 2,500 = 500 to pay
      const currentPlanPrice = parseFloat(currentMemberData.currentPlanPrice) || 0;
      const newPlanPrice = parseFloat(planPrice);
      
      // Debug: Log values to console (can be removed later)
      console.log('Current Plan Price:', currentPlanPrice);
      console.log('New Plan Price:', newPlanPrice);
      
      // Calculate the difference: New Plan Price - Current Plan Price
      const amountToPay = newPlanPrice - currentPlanPrice;
      
      console.log('Amount to Pay (difference):', amountToPay);
      
      // Always show payment modal, but set amount to 0 if new plan is cheaper (no refunds)
      const displayAmount = Math.max(0, amountToPay); // Never show negative, always 0 or positive
      
      // Show payment modal
      document.getElementById('planPaymentModal').style.display = 'block';
      document.getElementById('paymentPlanName').textContent = planName;
      
      // Display breakdown - ALWAYS showing the difference (remaining balance), not full price
      document.getElementById('paymentNewPlanPrice').textContent = '₱' + newPlanPrice.toLocaleString();
      document.getElementById('paymentCurrentPlanPrice').textContent = currentPlanPrice > 0 ? '₱' + currentPlanPrice.toLocaleString() : '₱0';
      document.getElementById('paymentPriceDiff').textContent = '₱' + displayAmount.toLocaleString(); // Show 0 if negative (no refunds)
      
      // Show/hide remaining days section based on whether it's a plan change or new availment
      const remainingDaysSection = document.getElementById('paymentRemainingDaysSection');
      if (currentMemberData.hasActivePlan) {
        // Plan change - show remaining days
        if (remainingDaysSection) {
          remainingDaysSection.style.display = 'block';
        }
        document.getElementById('paymentRemainingDays').textContent = currentMemberData.remainingDays + ' days';
      } else {
        // New plan availment - hide remaining days
        if (remainingDaysSection) {
          remainingDaysSection.style.display = 'none';
        }
      }
      
      // Store values in hidden fields
      document.getElementById('selectedPlanId').value = planId;
      document.getElementById('selectedPlanName').value = planName;
      document.getElementById('selectedPlanPrice').value = planPrice;
      document.getElementById('selectedCurrentPlanPrice').value = currentPlanPrice;
      document.getElementById('selectedPriceDiff').value = displayAmount; // Store 0 if negative (no refunds)
      
      // Disable payment method and receipt sections if amount is 0
      const paymentMethodSelect = document.getElementById('planPaymentMethod');
      const paymentMethodSection = paymentMethodSelect ? paymentMethodSelect.closest('div') : null;
      const cashReceiptSection = document.getElementById('cashReceiptSection');
      const gcashReferenceSection = document.getElementById('gcashReferenceSection');
      const gcashScreenshotSection = document.getElementById('gcashScreenshotSection');
      
      if (displayAmount === 0) {
        // Hide and disable payment method and receipt sections
        if (paymentMethodSection) {
          paymentMethodSection.style.display = 'none';
        }
        if (paymentMethodSelect) {
          paymentMethodSelect.disabled = true;
        }
        if (cashReceiptSection) {
          cashReceiptSection.style.display = 'none';
        }
        if (gcashReferenceSection) {
          gcashReferenceSection.style.display = 'none';
        }
        if (gcashScreenshotSection) {
          gcashScreenshotSection.style.display = 'none';
        }
      } else {
        // Show and enable payment method and receipt sections
        if (paymentMethodSection) {
          paymentMethodSection.style.display = 'block';
        }
        if (paymentMethodSelect) {
          paymentMethodSelect.disabled = false;
        }
        // Initialize payment method sections (show cash receipt by default)
        toggleGCashReference();
      }
    }

    function closePlanSelectionModal() {
      document.getElementById('planSelectionModal').style.display = 'none';
      currentMemberData = null;
    }

    function closePlanPaymentModal() {
      document.getElementById('planPaymentModal').style.display = 'none';
      
      // Reset payment method and receipt sections when closing
      const paymentMethodSelect = document.getElementById('planPaymentMethod');
      const paymentMethodSection = paymentMethodSelect ? paymentMethodSelect.closest('div') : null;
      const cashReceiptSection = document.getElementById('cashReceiptSection');
      const gcashReferenceSection = document.getElementById('gcashReferenceSection');
      const gcashScreenshotSection = document.getElementById('gcashScreenshotSection');
      const remainingDaysSection = document.getElementById('paymentRemainingDaysSection');
      
      // Reset to default state (show and enable)
      if (paymentMethodSection) {
        paymentMethodSection.style.display = 'block';
      }
      if (paymentMethodSelect) {
        paymentMethodSelect.disabled = false;
        paymentMethodSelect.value = 'cash'; // Reset to default
      }
      if (cashReceiptSection) {
        cashReceiptSection.style.display = 'none'; // Will be shown by toggleGCashReference if needed
      }
      if (gcashReferenceSection) {
        gcashReferenceSection.style.display = 'none';
      }
      if (gcashScreenshotSection) {
        gcashScreenshotSection.style.display = 'none';
      }
      if (remainingDaysSection) {
        remainingDaysSection.style.display = 'block'; // Reset to show (will be hidden by selectPlan if needed)
      }
      
      // Clear file inputs
      const cashReceipt = document.getElementById('planCashReceipt');
      const gcashScreenshot = document.getElementById('planGCashScreenshot');
      const gcashRef = document.getElementById('planGCashReference');
      if (cashReceipt) cashReceipt.value = '';
      if (gcashScreenshot) gcashScreenshot.value = '';
      if (gcashRef) gcashRef.value = '';
    }

    function processPlanChange(planId, planName, planPrice, amountPaid) {
      const formData = new FormData();
      formData.append('action', currentMemberData.hasActivePlan ? 'change_plan' : 'avail_plan');
      formData.append('user_id', currentMemberData.userId);
      formData.append('member_id', currentMemberData.memberId);
      formData.append('subscription_id', currentMemberData.subscriptionId || '');
      formData.append('plan_id', planId);
      formData.append('plan_name', planName);
      formData.append('plan_price', planPrice);
      formData.append('current_plan_price', currentMemberData.currentPlanPrice);
      formData.append('remaining_days', currentMemberData.remainingDays);
      formData.append('amount_paid', amountPaid);
      const paymentMethod = document.getElementById('planPaymentMethod') ? document.getElementById('planPaymentMethod').value : 'cash';
      formData.append('payment_method', paymentMethod);

      // Add receipt file based on payment method
      if (paymentMethod === 'cash') {
        const cashReceipt = document.getElementById('planCashReceipt');
        if (cashReceipt && cashReceipt.files.length > 0) {
          formData.append('cash_receipt', cashReceipt.files[0]);
        }
      } else if (paymentMethod === 'gcash') {
        const gcashRef = document.getElementById('planGCashReference');
        if (gcashRef && gcashRef.value) {
          formData.append('gcash_reference', gcashRef.value);
        }
        const gcashScreenshot = document.getElementById('planGCashScreenshot');
        if (gcashScreenshot && gcashScreenshot.files.length > 0) {
          formData.append('gcash_screenshot', gcashScreenshot.files[0]);
        }
      }

      // Show loading
      const submitBtn = document.getElementById('processPlanChangeBtn');
      const originalText = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing...';

      fetch('process_plan_change.php', {
        method: 'POST',
        body: formData
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert(data.message || 'Plan changed successfully!');
            closePlanPaymentModal();
            closePlanSelectionModal();
            closeMemberModal();
            location.reload();
          } else {
            alert('Error: ' + (data.message || 'Failed to process plan change'));
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
          }
        })
        .catch(error => {
          alert('Error processing plan change. Please try again.');
          submitBtn.disabled = false;
          submitBtn.textContent = originalText;
        });
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
      const memberModal = document.getElementById('memberModal');
      const planModal = document.getElementById('planSelectionModal');
      const paymentModal = document.getElementById('planPaymentModal');
      
      if (event.target == memberModal) {
        closeMemberModal();
      }
      if (event.target == planModal) {
        closePlanSelectionModal();
      }
      if (event.target == paymentModal) {
        closePlanPaymentModal();
      }
    }
  </script>

  <!-- Plan Selection Modal -->
  <div id="planSelectionModal" class="modal" style="display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(4px);">
    <div class="modal-content" style="background: linear-gradient(135deg, #1c1d25 0%, #2a2b36 100%); margin: 3% auto; padding: 0; border-radius: 16px; width: 90%; max-width: 800px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.7); border: 1px solid rgba(255,255,255,0.1);">
      <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); background: linear-gradient(135deg, #2a2b36 0%, #1c1d25 100%); border-radius: 16px 16px 0 0; position: sticky; top: 0; z-index: 10;">
        <h2 style="margin: 0; color: var(--accent-clr); font-size: 1.3rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
          <i class='bx bx-package' style="font-size: 1.3rem;"></i>
          Select Membership Plan
        </h2>
        <span class="close" onclick="closePlanSelectionModal()" style="color: #aaa; font-size: 24px; font-weight: bold; cursor: pointer; padding: 4px; border-radius: 50%; transition: all 0.3s ease; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='rgba(255,255,255,0.1)'; this.style.color='white';" onmouseout="this.style.background='transparent'; this.style.color='#aaa';">&times;</span>
      </div>
      <div id="planSelectionContent" style="padding: 20px;">
        <!-- Plans will be loaded here -->
      </div>
    </div>
  </div>

  <!-- Plan Payment Modal -->
  <div id="planPaymentModal" class="modal" style="display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(4px); overflow-y: auto;">
    <div class="modal-content" style="background: linear-gradient(135deg, #1c1d25 0%, #2a2b36 100%); margin: 3% auto; padding: 0; border-radius: 16px; width: 90%; max-width: 500px; max-height: 90vh; box-shadow: 0 20px 60px rgba(0,0,0,0.7); border: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column;">
      <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); background: linear-gradient(135deg, #2a2b36 0%, #1c1d25 100%); border-radius: 16px 16px 0 0; flex-shrink: 0;">
        <h2 style="margin: 0; color: var(--accent-clr); font-size: 1.3rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
          <i class='bx bx-credit-card' style="font-size: 1.3rem;"></i>
          Payment Required
        </h2>
        <span class="close" onclick="closePlanPaymentModal()" style="color: #aaa; font-size: 24px; font-weight: bold; cursor: pointer; padding: 4px; border-radius: 50%; transition: all 0.3s ease; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='rgba(255,255,255,0.1)'; this.style.color='white';" onmouseout="this.style.background='transparent'; this.style.color='#aaa';">&times;</span>
      </div>
      <div style="padding: 20px; overflow-y: auto; flex: 1;">
        <div style="background: rgba(255,255,255,0.05); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
          <div style="color: #b0b3c1; font-size: 0.85rem; margin-bottom: 8px;">New Plan</div>
          <div style="color: white; font-size: 1rem; font-weight: 600;" id="paymentPlanName"></div>
        </div>
        <div style="background: rgba(255,255,255,0.05); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
          <div style="color: #b0b3c1; font-size: 0.85rem; margin-bottom: 12px;">Payment Breakdown</div>
          <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
            <span style="color: #b0b3c1; font-size: 0.85rem;">New Plan Price:</span>
            <span style="color: white; font-size: 0.9rem; font-weight: 600;" id="paymentNewPlanPrice"></span>
          </div>
          <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
            <span style="color: #b0b3c1; font-size: 0.85rem;">Current Plan Price:</span>
            <span style="color: white; font-size: 0.9rem; font-weight: 600;" id="paymentCurrentPlanPrice"></span>
          </div>
          <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 8px; margin-top: 8px; display: flex; justify-content: space-between;">
            <span style="color: #4caf50; font-size: 0.9rem; font-weight: 600;">Amount to Pay:</span>
            <span style="color: #4caf50; font-size: 1.3rem; font-weight: 700;" id="paymentPriceDiff"></span>
          </div>
        </div>
        <div id="paymentRemainingDaysSection" style="background: rgba(255,255,255,0.05); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
          <div style="color: #b0b3c1; font-size: 0.85rem; margin-bottom: 8px;">Remaining Days (will be preserved)</div>
          <div style="color: white; font-size: 1rem; font-weight: 600;" id="paymentRemainingDays"></div>
        </div>
        <div style="margin-bottom: 20px;">
          <label style="display: block; color: #b0b3c1; font-size: 0.85rem; margin-bottom: 8px;">Payment Method *</label>
          <select id="planPaymentMethod" style="width: 100%; padding: 10px; background: white; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: black; font-size: 0.9rem;" onchange="toggleGCashReference()">
            <option value="cash" style="color: black;">Cash</option>
            <option value="gcash" style="color: black;">GCash</option>
          </select>
        </div>
        <div id="cashReceiptSection" style="display: none; margin-bottom: 20px;">
          <label style="display: block; color: #b0b3c1; font-size: 0.85rem; margin-bottom: 8px;">Upload Receipt *</label>
          <input type="file" id="planCashReceipt" name="cash_receipt" accept="image/*,.pdf" style="width: 100%; padding: 10px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: white; font-size: 0.9rem;">
          <div id="cashReceiptInfo" style="margin-top: 8px; font-size: 0.8rem; color: #4caf50; display: none;"></div>
        </div>
        <div id="gcashReferenceSection" style="display: none; margin-bottom: 20px;">
          <label style="display: block; color: #b0b3c1; font-size: 0.85rem; margin-bottom: 8px;">GCash Reference Number *</label>
          <input type="text" id="planGCashReference" placeholder="Enter 13-digit GCash reference" maxlength="13" pattern="[0-9]{13}" oninput="this.value = this.value.replace(/[^0-9]/g, ''); validatePlanGCashReference();" style="width: 100%; padding: 10px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: white; font-size: 0.9rem; transition: border-color 0.3s ease;">
          <div id="planGCashReferenceValidation" style="display: none; margin-top: 8px; font-size: 0.8rem; padding: 6px 10px; border-radius: 4px;"></div>
        </div>
        <div id="gcashScreenshotSection" style="display: none; margin-bottom: 20px;">
          <label style="display: block; color: #b0b3c1; font-size: 0.85rem; margin-bottom: 8px;">Upload GCash Screenshot *</label>
          <input type="file" id="planGCashScreenshot" name="gcash_screenshot" accept="image/*,.pdf" style="width: 100%; padding: 10px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: white; font-size: 0.9rem;">
          <div id="gcashScreenshotInfo" style="margin-top: 8px; font-size: 0.8rem; color: #4caf50; display: none;"></div>
        </div>
        <input type="hidden" id="selectedPlanId">
        <input type="hidden" id="selectedPlanName">
        <input type="hidden" id="selectedPlanPrice">
        <input type="hidden" id="selectedCurrentPlanPrice">
        <input type="hidden" id="selectedPriceDiff">
        <div style="display: flex; gap: 10px;">
          <button onclick="closePlanPaymentModal()" style="flex: 1; padding: 12px; background: rgba(255,255,255,0.1); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">Cancel</button>
          <button id="processPlanChangeBtn" onclick="processPlanPayment()" style="flex: 1; padding: 12px; background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">Process Payment</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    function toggleGCashReference() {
      const paymentMethod = document.getElementById('planPaymentMethod').value;
      const cashReceiptSection = document.getElementById('cashReceiptSection');
      const gcashReferenceSection = document.getElementById('gcashReferenceSection');
      const gcashScreenshotSection = document.getElementById('gcashScreenshotSection');
      
      if (paymentMethod === 'gcash') {
        cashReceiptSection.style.display = 'none';
        gcashReferenceSection.style.display = 'block';
        gcashScreenshotSection.style.display = 'block';
        document.getElementById('cashReceiptInfo').style.display = 'none';
        document.getElementById('cashReceiptInfo').textContent = '';
        // Reset validation when switching to GCash
        const gcashRefInput = document.getElementById('planGCashReference');
        if (gcashRefInput) {
          gcashRefInput.value = '';
          validatePlanGCashReference();
        }
      } else {
        cashReceiptSection.style.display = 'block';
        gcashReferenceSection.style.display = 'none';
        gcashScreenshotSection.style.display = 'none';
        document.getElementById('gcashScreenshotInfo').style.display = 'none';
        document.getElementById('gcashScreenshotInfo').textContent = '';
        // Hide validation when switching away from GCash
        const validationDiv = document.getElementById('planGCashReferenceValidation');
        if (validationDiv) {
          validationDiv.style.display = 'none';
        }
      }
    }

    // Validate GCash reference for plan change
    function validatePlanGCashReference() {
      const referenceInput = document.getElementById('planGCashReference');
      const validationDiv = document.getElementById('planGCashReferenceValidation');
      
      if (!referenceInput || !validationDiv) return;
      
      const reference = referenceInput.value.trim();
      
      // Reset styles
      referenceInput.style.borderColor = 'rgba(255,255,255,0.1)';
      validationDiv.style.display = 'none';
      
      // If empty, don't show validation
      if (!reference) {
        return;
      }
      
      // Check if contains only numbers (extra safety check)
      if (!/^[0-9]+$/.test(reference)) {
        validationDiv.style.display = 'block';
        validationDiv.style.background = 'rgba(220, 53, 69, 0.1)';
        validationDiv.style.color = '#dc3545';
        validationDiv.style.border = '1px solid rgba(220, 53, 69, 0.3)';
        validationDiv.textContent = 'GCash reference number must contain only numbers.';
        referenceInput.style.borderColor = '#dc3545';
        return;
      }
      
      // Check length - must be exactly 13 characters
      if (reference.length !== 13) {
        validationDiv.style.display = 'block';
        validationDiv.style.background = 'rgba(220, 53, 69, 0.1)';
        validationDiv.style.color = '#dc3545';
        validationDiv.style.border = '1px solid rgba(220, 53, 69, 0.3)';
        validationDiv.textContent = 'GCash reference number must be exactly 13 characters long.';
        referenceInput.style.borderColor = '#dc3545';
        return;
      }
      
      // If valid length, check availability via AJAX
      validationDiv.style.display = 'block';
      validationDiv.style.background = 'rgba(255, 193, 7, 0.1)';
      validationDiv.style.color = '#ffc107';
      validationDiv.style.border = '1px solid rgba(255, 193, 7, 0.3)';
      validationDiv.innerHTML = '<i class="bx bx-loader-alt bx-spin" style="margin-right: 0.25rem;"></i>Checking reference availability...';
      
      const formData = new FormData();
      formData.append('gcash_reference', reference);
      
      fetch('../check_gcash_reference_availability.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        return response.text().then(text => {
          try {
            return JSON.parse(text);
          } catch (e) {
            console.error('Invalid JSON response:', text);
            throw new Error('Invalid JSON response from server');
          }
        });
      })
      .then(data => {
        if (data.available) {
          // Mark input green when validated successfully, hide validation message
          validationDiv.style.display = 'none';
          referenceInput.style.borderColor = '#4caf50';
          referenceInput.classList.add('valid');
          referenceInput.classList.remove('invalid');
        } else {
          // Show error message - duplicate found, payment cannot proceed
          validationDiv.style.display = 'block';
          validationDiv.style.background = 'rgba(220, 53, 69, 0.1)';
          validationDiv.style.color = '#dc3545';
          validationDiv.style.border = '1px solid rgba(220, 53, 69, 0.3)';
          validationDiv.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>' + (data.message || 'This GCash reference number is already used.');
          referenceInput.style.borderColor = '#dc3545';
          referenceInput.classList.add('invalid');
          referenceInput.classList.remove('valid');
        }
      })
      .catch(error => {
        console.error('Error checking GCash reference:', error);
        // On error, just make it green if it's 13 digits (assume valid)
        validationDiv.style.display = 'none';
        referenceInput.style.borderColor = '#4caf50';
      });
    }

    // File upload handlers
    document.addEventListener('DOMContentLoaded', function() {
      const cashReceiptInput = document.getElementById('planCashReceipt');
      const gcashScreenshotInput = document.getElementById('planGCashScreenshot');
      
      if (cashReceiptInput) {
        cashReceiptInput.addEventListener('change', function(e) {
          const file = e.target.files[0];
          const infoDiv = document.getElementById('cashReceiptInfo');
          if (file) {
            infoDiv.style.display = 'block';
            infoDiv.innerHTML = '<i class="bx bx-check-circle"></i> ' + file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
          } else {
            infoDiv.style.display = 'none';
          }
        });
      }
      
      if (gcashScreenshotInput) {
        gcashScreenshotInput.addEventListener('change', function(e) {
          const file = e.target.files[0];
          const infoDiv = document.getElementById('gcashScreenshotInfo');
          if (file) {
            infoDiv.style.display = 'block';
            infoDiv.innerHTML = '<i class="bx bx-check-circle"></i> ' + file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
          } else {
            infoDiv.style.display = 'none';
          }
        });
      }
    });

    function processPlanPayment() {
      const paymentMethod = document.getElementById('planPaymentMethod').value;
      const planId = document.getElementById('selectedPlanId').value;
      const planName = document.getElementById('selectedPlanName').value;
      const planPrice = parseFloat(document.getElementById('selectedPlanPrice').value);
      // Get the calculated price difference from hidden field (more reliable than parsing text)
      const priceDiff = parseFloat(document.getElementById('selectedPriceDiff').value);

      // Only validate payment method and receipt if amount to pay is greater than 0
      if (priceDiff > 0) {
        if (paymentMethod === 'cash') {
          const cashReceipt = document.getElementById('planCashReceipt');
          if (!cashReceipt || !cashReceipt.files || cashReceipt.files.length === 0) {
            alert('Please upload a receipt.');
            return;
          }
        } else if (paymentMethod === 'gcash') {
          const gcashRefInput = document.getElementById('planGCashReference');
          const gcashRef = gcashRefInput ? gcashRefInput.value.trim() : '';
          if (!gcashRef || gcashRef.length !== 13) {
            alert('Please enter a valid 13-digit GCash reference number.');
            return;
          }
          // Check if GCash reference is validated (not duplicate)
          if (gcashRefInput && !gcashRefInput.classList.contains('valid')) {
            alert('Please wait for the GCash reference number to be validated. If it shows an error, the reference number is already used in the system.');
            return;
          }
          const gcashScreenshot = document.getElementById('planGCashScreenshot');
          if (!gcashScreenshot || !gcashScreenshot.files || gcashScreenshot.files.length === 0) {
            alert('Please upload a GCash screenshot.');
            return;
          }
        }
      }

      processPlanChange(planId, planName, planPrice, priceDiff);
    }
  </script>
</body>
</html>
