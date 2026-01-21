<?php
// Start session before any output
if (session_status() == PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_name('gym_admin_session');
        session_start();
    }
}

include '../config.php';

include 'navbar.php';

$filter = $_GET['role'] ?? '';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? '';

$sql = "
  SELECT 
    u.id,
    u.full_name,
    u.email,
    u.role,
    u.created_at,
    u.archived,
    TRIM(SUBSTRING_INDEX(u.full_name, ' ', -1)) as surname,
    TRIM(SUBSTRING_INDEX(u.full_name, ' ', 1)) as first_name
  FROM users u
  WHERE u.role IN ('manager', 'coach')
";

if ($filter) {
  $sql .= " AND u.role = '$filter'";
}

if ($search) {
  $search = mysqli_real_escape_string($conn, $search);
  $sql .= " AND (u.full_name LIKE '%$search%' OR u.email LIKE '%$search%')";
}

// Handle filtering by status (active/inactive)
if ($sort === 'active') {
  $sql .= " AND u.archived = 0";
} elseif ($sort === 'inactive') {
  $sql .= " AND u.archived = 1";
}

// Handle sorting
switch ($sort) {
  case 'name_asc':
    $sql .= " ORDER BY u.archived ASC, surname ASC, first_name ASC";
    break;
  case 'name_desc':
    $sql .= " ORDER BY u.archived ASC, surname DESC, first_name DESC";
    break;
  case 'recent':
    $sql .= " ORDER BY u.archived ASC, u.created_at DESC";
    break;
  case 'active':
    $sql .= " ORDER BY surname ASC, first_name ASC";
    break;
  case 'inactive':
    $sql .= " ORDER BY surname ASC, first_name ASC";
    break;
  default:
    $sql .= " ORDER BY u.archived ASC, surname ASC, first_name ASC";
    break;
}

$result = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>User Management</title>
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
      overflow-x: hidden;
    }

    main {
      padding: 20px;
      width: 100%;
      max-width: 100%;
      overflow-x: hidden;
      box-sizing: border-box;
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
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
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
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
    }

    .search-form {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
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
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
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
      min-width: 800px;
      box-sizing: border-box;
    }

    thead th {
      background: #2a2b36;
      padding: 12px;
      border: 1px solid var(--line-clr);
      font-size: 0.95rem;
      text-align: center;
      word-wrap: break-word;
      overflow-wrap: break-word;
    }

    tbody td {
      padding: 12px;
      border: 1px solid var(--line-clr);
      text-align: center;
      font-size: 0.95rem;
      word-wrap: break-word;
      overflow-wrap: break-word;
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

    .edit-btn {
      background: var(--accent-clr);
      color: white;
    }

    .edit-btn:hover {
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

    /* Responsive Styles for Mobile and Tablet */
    @media (max-width: 992px) {
      /* Tablet Styles */
      main {
        width: 100%;
        max-width: 100%;
        padding: 1.25rem;
        box-sizing: border-box;
        overflow-x: hidden;
      }

      main h1 {
        font-size: 1.5rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .search-input-group {
        min-width: 400px;
      }

      table {
        min-width: 700px;
      }
    }

    @media (max-width: 768px) {
      /* Mobile Styles */
      body {
        overflow-x: hidden;
        /* Hide scrollbar */
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
      }

      body::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
      }

      main {
        padding: 0.75rem;
        width: 100%;
        max-width: 100vw;
        box-sizing: border-box;
        overflow-x: hidden;
        margin: 0;
        /* Hide scrollbar */
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
      }

      main::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
      }

      main h1 {
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
      }

      th:nth-child(3), td:nth-child(3) {
        width: 22%;
      }

      th:nth-child(4), td:nth-child(4) {
        width: 12%;
      }

      th:nth-child(5), td:nth-child(5) {
        width: 15%;
        font-size: 0.65rem;
      }

      th:nth-child(6), td:nth-child(6) {
        width: 15%;
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
        /* Hide scrollbar */
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
      }

      body::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
      }

      main {
        padding: 0.75rem;
        width: 100%;
        max-width: 100vw;
        box-sizing: border-box;
        overflow-x: hidden;
        /* Hide scrollbar */
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
      }

      main::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
      }

      main h1 {
        font-size: 1.125rem;
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
      }

      th:nth-child(3), td:nth-child(3) {
        width: 22%;
        font-size: 0.6rem;
      }

      th:nth-child(4), td:nth-child(4) {
        width: 12%;
        font-size: 0.6rem;
      }

      th:nth-child(5), td:nth-child(5) {
        width: 15%;
        font-size: 0.55rem;
      }

      th:nth-child(6), td:nth-child(6) {
        width: 15%;
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
    <h1>User Management</h1>
    
    <?php
    // Display success message if exists
    if (isset($_SESSION['success_message'])) {
        echo '<div class="alert alert-success" style="background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512" style="margin-right: 0.5rem; vertical-align: middle;"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c70.7 0 128 57.3 128 128s-57.3 128-128 128s-128-57.3-128-128s57.3-128 128-128zM96.8 431.2c5.1-12.2 19.1-18.8 31.3-13.7c67.9 28.3 144.1 28.3 212 0c12.2-5.1 26.2 1.5 31.3 13.7c5.1 12.2-1.5 26.2-13.7 31.3c-82.6 34.4-175.7 34.4-258.3 0c-12.2-5.1-18.8-19.1-13.7-31.3z"/></svg>';
        echo $_SESSION['success_message'];
        echo '</div>';
        unset($_SESSION['success_message']);
    }
    ?>

    <div class="search-container">
      <div class="search-form">
        <div class="search-input-group">
          <input type="text" name="search" id="search" placeholder="Search by name or email..." value="<?= htmlspecialchars($search) ?>">
          <button type="button" class="search-btn" onclick="performSearch()">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.35-4.35"></path>
            </svg>
          </button>
        </div>
        <?php if ($search): ?>
          <a href="user__list.php<?php 
            $params = [];
            if ($filter) $params[] = 'role=' . $filter;
            if ($sort) $params[] = 'sort=' . $sort;
            echo $params ? '?' . implode('&', $params) : '';
          ?>" class="clear-search">Clear Search</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="actions">
      <a href="user__form.php" class="btn" target="_self">Add New User</a>
      <form method="GET">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <label for="role">Role Filter:</label>
        <select name="role" id="role" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="manager" <?= $filter == 'manager' ? 'selected' : '' ?>>Manager</option>
          <option value="coach" <?= $filter == 'coach' ? 'selected' : '' ?>>Coach</option>
        </select>
      </form>
      <form method="GET">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="role" value="<?= htmlspecialchars($filter) ?>">
        <label for="sort">Sort Filter:</label>
        <select name="sort" id="sort" onchange="this.form.submit()">
          <option value="">Default</option>
          <option value="name_asc" <?= $sort == 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
          <option value="name_desc" <?= $sort == 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
          <option value="recent" <?= $sort == 'recent' ? 'selected' : '' ?>>Recently Added</option>
          <option value="active" <?= $sort == 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $sort == 'inactive' ? 'selected' : '' ?>>Archived</option>
        </select>
      </form>
    </div>

    <!-- Bulk Actions Section -->
    <div id="bulkActions" class="bulk-actions">
      <div class="bulk-info">
        <span id="selectedCount">0</span> user(s) selected
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

    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th class="checkbox-cell">
              <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
            </th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Joined</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (mysqli_num_rows($result) > 0): ?>
            <?php while($user = mysqli_fetch_assoc($result)): ?>
              <tr>
                <td class="checkbox-cell">
                  <input type="checkbox" class="user-checkbox" value="<?= $user['id'] ?>" onchange="updateBulkActions()" data-archived="<?= $user['archived'] ? 'true' : 'false' ?>">
                </td>
                <td><?= htmlspecialchars($user['surname'] . ', ' . $user['first_name']) ?></td>
                <td><?= htmlspecialchars($user['email']) ?></td>
                <td><?= ucfirst($user['role']) ?></td>
                <td><?= date('F j, Y \a\t g:i A', strtotime($user['created_at'] . ' +8 hours')) ?></td>
                <td>
                    <a href="user__form.php?id=<?= $user['id'] ?>"
                     class="action-btn edit-btn">
                      Edit
                    </a>
                    <?php if (!$user['archived']): ?>
                      <a href="user__actions.php?action=archive&id=<?= $user['id'] ?>"
                        onclick="return confirm('Archive this user?');"
                       class="action-btn archive-btn">
                        Archive
                      </a>
                    <?php else: ?>
                      <a href="user__actions.php?action=unarchive&id=<?= $user['id'] ?>"
                        onclick="return confirm('Unarchive this user?');"
                       class="action-btn unarchive-btn">
                        Unarchive
                      </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="6" class="empty-row">No users found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>

  <script>
    let searchTimeout;
    const searchInput = document.getElementById('search');
    const currentFilter = '<?= $filter ?>';
    const currentSort = '<?= $sort ?>';

    // Live search functionality
    searchInput.addEventListener('input', function() {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        performSearch();
      }, 300); // 300ms delay to avoid too many requests
    });

    function performSearch() {
      const searchTerm = searchInput.value.trim();
      const url = new URL(window.location.href);
      
      if (searchTerm) {
        url.searchParams.set('search', searchTerm);
      } else {
        url.searchParams.delete('search');
      }
      
      // Preserve current filter
      if (currentFilter) {
        url.searchParams.set('role', currentFilter);
      } else {
        url.searchParams.delete('role');
      }
      
      // Preserve current sort
      if (currentSort) {
        url.searchParams.set('sort', currentSort);
      } else {
        url.searchParams.delete('sort');
      }
      
      // Update URL and reload page
      window.location.href = url.toString();
    }

    // Handle Enter key
    searchInput.addEventListener('keypress', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        performSearch();
      }
    });

    // Bulk Actions Functions
    function toggleSelectAll() {
      const selectAllCheckbox = document.getElementById('selectAll');
      const userCheckboxes = document.querySelectorAll('.user-checkbox:not([disabled])');
      
      userCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
      });
      
      updateBulkActions();
    }

    function updateBulkActions() {
      const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
      const bulkActions = document.getElementById('bulkActions');
      const selectedCount = document.getElementById('selectedCount');
      const bulkArchiveBtn = document.getElementById('bulkArchiveBtn');
      const bulkUnarchiveBtn = document.getElementById('bulkUnarchiveBtn');
      const selectAllCheckbox = document.getElementById('selectAll');
      
      const count = selectedCheckboxes.length;
      selectedCount.textContent = count;
      
      if (count > 0) {
        bulkActions.classList.add('show');
        
        // Check if any selected users are archived
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
      const totalCheckboxes = document.querySelectorAll('.user-checkbox');
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
      const userCheckboxes = document.querySelectorAll('.user-checkbox');
      
      selectAllCheckbox.checked = false;
      selectAllCheckbox.indeterminate = false;
      userCheckboxes.forEach(checkbox => {
        checkbox.checked = false;
      });
      
      updateBulkActions();
    }

    function bulkArchive() {
      const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
      const userIds = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
      
      if (userIds.length === 0) {
        alert('Please select at least one user to archive.');
        return;
      }
      
      const confirmMessage = `Are you sure you want to archive ${userIds.length} user(s)? This action cannot be undone.`;
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
        idsInput.value = userIds.join(',');
        form.appendChild(idsInput);
        
        document.body.appendChild(form);
        form.submit();
      }
    }

    function bulkUnarchive() {
      const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
      const userIds = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
      
      if (userIds.length === 0) {
        alert('Please select at least one user to unarchive.');
        return;
      }
      
      const confirmMessage = `Are you sure you want to unarchive ${userIds.length} user(s)?`;
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
        idsInput.value = userIds.join(',');
        form.appendChild(idsInput);
        
        document.body.appendChild(form);
        form.submit();
      }
    }
  </script>
</body>
</html>
