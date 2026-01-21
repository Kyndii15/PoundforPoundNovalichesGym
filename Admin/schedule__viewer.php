<?php
include '../config.php';

include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Schedule Viewer</title>
  <style>
    :root {
      --accent-clr: #5e63ff;
      --line-clr: #42434a;
    }

    body {
      margin: 0;
      font-family: Poppins, sans-serif;
      background: #0c1118;
      color: white;
    }

    main {
      padding: 2rem;
    }

    h1 {
      margin-bottom: 0.5rem;
    }

    .form-card {
      background: #1c1d25;
      padding: 1.5rem;
      border-radius: 10px;
      margin-bottom: 2rem;
    }

    form {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 20px;
      align-items: center;
      justify-content: space-between;
    }

    .filter-controls {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
    }

    input[type="date"], select, input[type="text"] {
      padding: 10px 14px;
      border-radius: 6px;
      border: 1px solid var(--line-clr);
      background: #2a2b36;
      color: white;
      font-size: 1rem;
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
      color: white;
      font-size: 14px;
      outline: none;
    }

    .search-input-group input::placeholder {
      color: #d1d1d1;
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

    .date-input-group {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .date-input-group label {
      font-size: 0.9rem;
      color: #b0b0b0;
      font-weight: 500;
      white-space: nowrap;
    }

    button {
      background: var(--accent-clr);
      color: white;
      padding: 10px 15px;
      border: none;
      border-radius: 6px;
      font-weight: 500;
      font-size: 1rem;
      cursor: pointer;
      transition: background 0.3s ease;
    }

    .btn {
      background: #5e63ff;
      color: white;
      padding: 10px 15px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 500;
      font-size: 1rem;
      display: inline-block;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
      background: #1c1d25;
      color: white;
    }

    th, td {
      padding: 12px;
      border: 1px solid var(--line-clr);
      text-align: center;
    }

    th {
      background: #2a2b36;
    }

    /* Coach Accordion Styles */
    .coach-list {
      margin-top: 20px;
    }

    .coach-item {
      background: #1c1d25;
      border: 1px solid var(--line-clr);
      border-radius: 8px;
      margin-bottom: 10px;
      overflow: hidden;
    }

    .coach-header {
      padding: 15px 20px;
      background: #2a2b36;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: space-between;
      transition: background 0.3s ease;
      user-select: none;
    }

    .coach-header:hover {
      background: #333444;
    }

    .coach-header.active {
      background: #3a3b4a;
    }

    .coach-name-header {
      font-size: 1.1rem;
      font-weight: 600;
      color: white;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .coach-toggle {
      font-size: 1.5rem;
      color: var(--accent-clr);
      transition: transform 0.3s ease;
    }

    .coach-header.active .coach-toggle {
      transform: rotate(90deg);
    }

    .coach-schedules {
      display: none;
      padding: 0;
    }

    .coach-schedules.active {
      display: block;
    }

    .coach-schedules table {
      margin: 0;
      border-radius: 0;
    }

    .coach-schedules table thead th {
      background: #2a2b36;
    }

    a {
      color: var(--accent-clr);
      text-decoration: none;
      margin: 0 4px;
    }

    a[href*="delete"] {
      background: crimson;
      color: white;
      padding: 6px 12px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 500;
    }
  </style>
</head>
<body>
<main>
  <h1>Schedule Viewer</h1>
  <p>Your Weekly Gym Calendar.</p>

  <div class="form-card">
    <?php if (isset($_GET['success'])): ?>
      <div style="background: rgba(94, 99, 255, 0.1); border: 1px solid var(--accent-clr); color: var(--accent-clr); padding: 12px 16px; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; box-shadow: 0 2px 8px rgba(94, 99, 255, 0.1);">
        <?php echo htmlspecialchars($_GET['success']); ?>
      </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
      <div style="background: rgba(94, 99, 255, 0.1); border: 1px solid var(--accent-clr); color: var(--accent-clr); padding: 12px 16px; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; box-shadow: 0 2px 8px rgba(94, 99, 255, 0.1);">
        Shift deleted successfully!
      </div>
    <?php endif; ?>
    
    <form method="GET">
      <div class="filter-controls">
        <div class="date-input-group">
          <label for="start_date">From:</label>
          <input type="date" id="start_date" name="start_date" value="<?php echo isset($_GET['shifts_created']) ? date('Y-m-d') : ($_GET['start_date'] ?? date('Y-m-d')); ?>" required>
        </div>
        <div class="date-input-group">
          <label for="end_date">To:</label>
          <input type="date" id="end_date" name="end_date" value="<?php echo isset($_GET['shifts_created']) ? date('Y-m-d') : ($_GET['end_date'] ?? date('Y-m-d')); ?>" required>
        </div>
        <button type="submit">Filter</button>
      </div>

      <!-- Add New Shift Button -->
      <a href="roster__form.php" class="btn" target="_self">+ Add New Shift</a>
    </form>

    <!-- Search Bar -->
    <div class="search-container">
      <div class="search-form">
        <div class="search-input-group">
          <input type="text" id="searchInput" placeholder="Search by coach name, date, or time..." onkeyup="filterTable()">
          <button type="button" class="search-btn" onclick="filterTable()">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.35-4.35"></path>
            </svg>
          </button>
        </div>
        <a href="#" class="clear-search" id="clearSearchBtn" onclick="clearSearch(); return false;" style="display: none;">Clear Search</a>
      </div>
    </div>

    <!-- Batch Actions -->
    <div class="batch-actions" style="margin-bottom: 1rem; display: none;" id="batchActions">
      <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #2a2b36; border-radius: 6px; border: 1px solid var(--line-clr);">
        <span style="color: #b0b0b0; font-size: 0.9rem;" id="selectedCount">0 selected</span>
        <button type="button" onclick="batchDelete()" style="background: #ef4444; color: white; padding: 8px 12px; border: none; border-radius: 4px; font-size: 0.9rem; cursor: pointer;">
          Delete Selected
        </button>
        <button type="button" onclick="clearSelection()" style="background: #6b7280; color: white; padding: 8px 12px; border: none; border-radius: 4px; font-size: 0.9rem; cursor: pointer;">
          Clear Selection
        </button>
      </div>
    </div>

    <div class="coach-list" id="coachList">
      <?php
      // If coming from shift assignment, show only today's shifts
      if (isset($_GET['shifts_created'])) {
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
      } else {
        $start_date = $_GET['start_date'] ?? date('Y-m-d');
        $end_date = $_GET['end_date'] ?? date('Y-m-d');
      }

      // Get all coaches with their schedules
      $sql = "SELECT roster_shifts.*, users.full_name, users.id as user_id FROM roster_shifts 
              JOIN users ON roster_shifts.staff_user_id = users.id 
              WHERE users.role = 'coach' AND shift_date >= ? AND shift_date <= ?
              ORDER BY users.full_name ASC, shift_date ASC, start_time ASC";
      $params = [$start_date, $end_date];

      $stmt = $conn->prepare($sql);
      $stmt->bind_param("ss", $params[0], $params[1]);
      $stmt->execute();
      $result = $stmt->get_result();

      // Group schedules by coach
      $coaches = [];
      while ($row = $result->fetch_assoc()) {
        $coach_name = $row['full_name'];
        $coach_id = $row['user_id'];
        if (!isset($coaches[$coach_id])) {
          $coaches[$coach_id] = [
            'name' => $coach_name,
            'schedules' => []
          ];
        }
        $coaches[$coach_id]['schedules'][] = $row;
      }

      if (!empty($coaches)):
        foreach ($coaches as $coach_id => $coach_data):
          $coach_name = $coach_data['name'];
          $schedules = $coach_data['schedules'];
          $coach_key = 'coach-' . $coach_id;
      ?>
        <div class="coach-item" data-coach-name="<?php echo strtolower(htmlspecialchars($coach_name)); ?>">
          <div class="coach-header" onclick="toggleCoach('<?php echo $coach_key; ?>')">
            <div class="coach-name-header">
              <span class="coach-toggle">›</span>
              <span><?php echo htmlspecialchars($coach_name); ?></span>
              <span style="color: #b0b0b0; font-size: 0.9rem; font-weight: normal;">(<?php echo count($schedules); ?> shift<?php echo count($schedules) != 1 ? 's' : ''; ?>)</span>
            </div>
          </div>
          <div class="coach-schedules" id="<?php echo $coach_key; ?>">
            <div style="overflow-x: auto;">
              <table>
                <thead>
                  <tr>
                    <th style="width: 50px;">
                      <input type="checkbox" class="coach-select-all" data-coach="<?php echo $coach_key; ?>" onchange="toggleCoachShifts(this, '<?php echo $coach_key; ?>')">
                    </th>
                    <th>Date</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($schedules as $schedule): 
                    $display_date = date('F j, Y', strtotime($schedule['shift_date']));
                    $display_start_time = date("g:i A", strtotime($schedule['start_time']));
                    $display_end_time = date("g:i A", strtotime($schedule['end_time']));
                  ?>
                    <tr class="schedule-row">
                      <td>
                        <input type="checkbox" class="shift-checkbox" value="<?php echo $schedule['id']; ?>" data-coach="<?php echo $coach_key; ?>" onchange="updateSelection()">
                      </td>
                      <td class="shift-date"><?php echo $display_date; ?></td>
                      <td class="start-time"><?php echo $display_start_time; ?></td>
                      <td class="end-time"><?php echo $display_end_time; ?></td>
                      <td>
                        <a href="schedule__edit.php?id=<?php echo $schedule['id']; ?>"
                          style="display: inline-block; min-width: 70px; text-align: center; padding: 6px 12px; background: var(--accent-clr); color: white; border-radius: 6px; text-decoration: none; font-weight: 500; margin-right: 5px;">
                          Edit
                        </a>
                        <a href="schedule__delete.php?id=<?php echo $schedule['id']; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" 
                          onclick="return confirm('Are you sure you want to delete this shift?');"
                          style="display: inline-block; min-width: 70px; text-align: center; padding: 6px 12px; background: crimson; color: white; border-radius: 6px; text-decoration: none; font-weight: 500;">
                          Delete
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php
        endforeach;
      else:
      ?>
        <div style="padding: 20px; text-align: center; color: #b0b0b0; background: #1c1d25; border: 1px solid var(--line-clr); border-radius: 8px;">
          No shifts scheduled for the selected date range (<?php echo date('M j', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date)); ?>).
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
function toggleCoach(coachKey) {
  const coachSchedules = document.getElementById(coachKey);
  const coachHeader = coachSchedules.previousElementSibling;
  
  if (coachSchedules.classList.contains('active')) {
    coachSchedules.classList.remove('active');
    coachHeader.classList.remove('active');
  } else {
    coachSchedules.classList.add('active');
    coachHeader.classList.add('active');
  }
}

function toggleCoachShifts(checkbox, coachKey) {
  const coachSchedules = document.getElementById(coachKey);
  const checkboxes = coachSchedules.querySelectorAll('.shift-checkbox');
  checkboxes.forEach(cb => {
    cb.checked = checkbox.checked;
  });
  updateSelection();
}

function filterTable() {
  const input = document.getElementById('searchInput');
  const filter = input.value.toLowerCase();
  const coachItems = document.querySelectorAll('.coach-item');
  const clearSearchBtn = document.getElementById('clearSearchBtn');
  
  let visibleCount = 0;
  
  coachItems.forEach(coachItem => {
    const coachName = coachItem.getAttribute('data-coach-name') || '';
    const scheduleRows = coachItem.querySelectorAll('.schedule-row');
    let hasMatch = false;
    
    // Check if coach name matches
    if (coachName.includes(filter)) {
      hasMatch = true;
      // Auto-expand matching coaches
      const coachKey = coachItem.querySelector('.coach-schedules')?.id;
      if (coachKey) {
        const coachSchedules = document.getElementById(coachKey);
        const coachHeader = coachSchedules.previousElementSibling;
        if (!coachSchedules.classList.contains('active')) {
          coachSchedules.classList.add('active');
          coachHeader.classList.add('active');
        }
      }
    }
    
    // Check schedule rows
    scheduleRows.forEach(row => {
      const shiftDate = row.querySelector('.shift-date')?.textContent.toLowerCase() || '';
      const startTime = row.querySelector('.start-time')?.textContent.toLowerCase() || '';
      const endTime = row.querySelector('.end-time')?.textContent.toLowerCase() || '';
      
      if (shiftDate.includes(filter) || startTime.includes(filter) || endTime.includes(filter)) {
        hasMatch = true;
        row.style.display = '';
        // Auto-expand if schedule matches
        const coachKey = row.closest('.coach-schedules')?.id;
        if (coachKey) {
          const coachSchedules = document.getElementById(coachKey);
          const coachHeader = coachSchedules.previousElementSibling;
          if (!coachSchedules.classList.contains('active')) {
            coachSchedules.classList.add('active');
            coachHeader.classList.add('active');
          }
        }
      } else if (filter !== '') {
        row.style.display = 'none';
      } else {
        row.style.display = '';
      }
    });
    
    if (hasMatch || filter === '') {
      coachItem.style.display = '';
      visibleCount++;
    } else {
      coachItem.style.display = 'none';
    }
  });
  
  // Show/hide clear search button
  if (filter !== '') {
    clearSearchBtn.style.display = 'block';
  } else {
    clearSearchBtn.style.display = 'none';
  }
}

function clearSearch() {
  const input = document.getElementById('searchInput');
  input.value = '';
  filterTable();
}

// Note: toggleAll function removed as we no longer have a main select-all checkbox

function updateSelection() {
  const checkboxes = document.querySelectorAll('.shift-checkbox');
  const selectedCheckboxes = document.querySelectorAll('.shift-checkbox:checked');
  const batchActions = document.getElementById('batchActions');
  const selectedCount = document.getElementById('selectedCount');
  
  // Update coach select-all checkboxes
  document.querySelectorAll('.coach-select-all').forEach(coachSelectAll => {
    const coachKey = coachSelectAll.getAttribute('data-coach');
    const coachSchedules = document.getElementById(coachKey);
    if (coachSchedules) {
      const coachCheckboxes = coachSchedules.querySelectorAll('.shift-checkbox');
      const coachSelected = coachSchedules.querySelectorAll('.shift-checkbox:checked');
      
      if (coachSelected.length === 0) {
        coachSelectAll.indeterminate = false;
        coachSelectAll.checked = false;
      } else if (coachSelected.length === coachCheckboxes.length) {
        coachSelectAll.indeterminate = false;
        coachSelectAll.checked = true;
      } else {
        coachSelectAll.indeterminate = true;
      }
    }
  });
  
  // Show/hide batch actions
  if (selectedCheckboxes.length > 0) {
    batchActions.style.display = 'block';
    selectedCount.textContent = selectedCheckboxes.length + ' selected';
  } else {
    batchActions.style.display = 'none';
  }
}

function clearSelection() {
  const checkboxes = document.querySelectorAll('.shift-checkbox');
  const coachSelectAlls = document.querySelectorAll('.coach-select-all');
  
  checkboxes.forEach(checkbox => {
    checkbox.checked = false;
  });
  
  coachSelectAlls.forEach(checkbox => {
    checkbox.checked = false;
    checkbox.indeterminate = false;
  });
  
  updateSelection();
}

function batchDelete() {
  const selectedCheckboxes = document.querySelectorAll('.shift-checkbox:checked');
  
  if (selectedCheckboxes.length === 0) {
    alert('Please select at least one shift to delete.');
    return;
  }
  
  const shiftIds = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
  
  if (confirm(`Are you sure you want to delete ${selectedCheckboxes.length} selected shift(s)? This action cannot be undone.`)) {
    // Create a form to submit the batch delete request
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'batch_delete_shifts.php';
    
    // Add current date range to maintain filter state
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    
    const startDateInput = document.createElement('input');
    startDateInput.type = 'hidden';
    startDateInput.name = 'start_date';
    startDateInput.value = startDate;
    form.appendChild(startDateInput);
    
    const endDateInput = document.createElement('input');
    endDateInput.type = 'hidden';
    endDateInput.name = 'end_date';
    endDateInput.value = endDate;
    form.appendChild(endDateInput);
    
    // Add shift IDs
    shiftIds.forEach(id => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'shift_ids[]';
      input.value = id;
      form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
  }
}
</script>

</body>
</html>
