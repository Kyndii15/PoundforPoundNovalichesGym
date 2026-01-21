<?php
include '../config.php';

include 'navbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Assign Scheduled Shift</title>
  <style>
    :root {
      --accent: #5e63ff;
      --accent-hover: #4a52e8;
      --bg: #0c1118;
      --card-bg: #1c1d25;
      --line: #444;
      --line-light: #555;
      --text-primary: #ffffff;
      --text-secondary: #b0b0b0;
      --text-muted: #888;
      --success: #10b981;
      --error: #ef4444;
      --font: 'Poppins', sans-serif;
      --shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
      --shadow-hover: 0 15px 35px rgba(0, 0, 0, 0.4);
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: var(--font);
      background: linear-gradient(135deg, var(--bg) 0%, #0a0d14 100%);
      color: var(--text-primary);
      min-height: 100vh;
      line-height: 1.6;
    }

    main {
      padding: 2rem;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
    }

    .form-card {
      background: var(--card-bg);
      padding: 2.5rem;
      border-radius: 20px;
      box-shadow: var(--shadow);
      width: 100%;
      max-width: 520px;
      border: 1px solid var(--line);
      position: relative;
      overflow: hidden;
    }

    .form-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--accent), #7c3aed, var(--accent));
    }

    .form-card h1 {
      text-align: center;
      font-size: 2rem;
      color: var(--text-primary);
      margin: 0 0 0.5rem 0;
      font-weight: 700;
      letter-spacing: -0.5px;
    }

    .form-card .subtitle {
      text-align: center;
      color: var(--text-secondary);
      margin-bottom: 2rem;
      font-size: 0.95rem;
    }

    .form-group {
      margin-bottom: 1.5rem;
      position: relative;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.75rem;
      font-weight: 600;
      color: var(--text-primary);
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .form-group select,
    .form-group input {
      width: 100%;
      padding: 14px 16px;
      border: 2px solid var(--line);
      border-radius: 12px;
      background: #2a2b36;
      color: var(--text-primary);
      font-size: 1rem;
      outline: none;
      transition: all 0.3s ease;
      box-sizing: border-box;
      font-family: var(--font);
    }

    .form-group select:focus,
    .form-group input:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(94, 99, 255, 0.1);
      transform: translateY(-1px);
    }

    .form-group select:hover,
    .form-group input:hover {
      border-color: var(--line-light);
    }

    .form-group select option {
      color: var(--text-primary);
      background: #2a2b36;
      padding: 10px;
    }

    .form-group select option:first-child {
      color: var(--text-muted);
      font-style: italic;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }

    .btn {
      background: linear-gradient(135deg, var(--accent) 0%, var(--accent-hover) 100%);
      color: white;
      border: none;
      padding: 16px 24px;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      width: 100%;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      position: relative;
      overflow: hidden;
    }

    .btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
      transition: left 0.5s;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-hover);
    }

    .btn:hover::before {
      left: 100%;
    }

    .btn:active {
      transform: translateY(0);
    }

    .form-icon {
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      pointer-events: none;
    }

    .form-group:has(.form-icon) select,
    .form-group:has(.form-icon) input {
      padding-right: 50px;
    }

    .loading {
      opacity: 0.7;
      pointer-events: none;
    }

    .loading::after {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 20px;
      height: 20px;
      margin: -10px 0 0 -10px;
      border: 2px solid var(--accent);
      border-top: 2px solid transparent;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .success-message {
      background: rgba(16, 185, 129, 0.1);
      border: 1px solid var(--success);
      color: var(--success);
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 1rem;
      font-size: 0.9rem;
    }

    .error-message {
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid var(--error);
      color: var(--error);
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 1rem;
      font-size: 0.9rem;
    }

    .conflict-warning {
      background: rgba(245, 158, 11, 0.1);
      border: 2px solid #f59e0b;
      border-radius: 12px;
      padding: 1rem;
      margin-bottom: 1.5rem;
      animation: slideIn 0.3s ease-out;
    }

    .conflict-header {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-weight: 600;
      color: #f59e0b;
      margin-bottom: 0.75rem;
      font-size: 0.95rem;
      cursor: pointer;
      user-select: none;
      transition: all 0.2s ease;
      padding: 0.25rem;
      border-radius: 6px;
    }

    .conflict-header:hover {
      background: rgba(245, 158, 11, 0.1);
    }

    .toggle-icon {
      margin-left: auto;
      transition: transform 0.3s ease;
    }

    .toggle-icon.expanded {
      transform: rotate(180deg);
    }

    .conflict-details {
      color: var(--text-secondary);
      font-size: 0.9rem;
      line-height: 1.5;
    }

    .conflict-item {
      background: rgba(245, 158, 11, 0.05);
      border-left: 3px solid #f59e0b;
      padding: 0.75rem;
      margin-bottom: 0.5rem;
      border-radius: 0 8px 8px 0;
    }

    .conflict-item:last-child {
      margin-bottom: 0;
    }

    .conflict-coach {
      color: var(--accent);
      font-weight: 600;
      font-size: 0.95rem;
      margin-bottom: 0.25rem;
    }

    .conflict-type {
      font-size: 0.85rem;
      color: var(--text-secondary);
      margin-bottom: 0.5rem;
    }

    .conflict-summary {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-bottom: 0.5rem;
      flex-wrap: wrap;
    }

    .conflict-count {
      color: #f59e0b;
      font-weight: 600;
      font-size: 0.9rem;
    }

    .conflict-separator {
      color: var(--text-muted);
    }

    .conflict-time {
      color: var(--text-primary);
      font-family: monospace;
      font-size: 0.9rem;
    }

    .conflict-date {
      font-size: 0.85rem;
      color: var(--text-secondary);
      font-style: italic;
      line-height: 1.6;
    }

    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .btn:disabled {
      background: #6b7280;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    .btn:disabled:hover {
      transform: none;
      box-shadow: none;
    }

    .btn:disabled::before {
      display: none;
    }

    .day-exclusion-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      cursor: pointer;
      padding: 0.5rem 0;
      user-select: none;
      transition: color 0.2s ease;
    }

    .day-exclusion-header:hover {
      color: var(--accent);
    }

    .day-exclusion-header label {
      margin-bottom: 0;
      cursor: pointer;
    }

    .dropdown-arrow {
      transition: transform 0.3s ease;
      flex-shrink: 0;
    }

    .dropdown-arrow.expanded {
      transform: rotate(180deg);
    }

    .day-exclusion-content {
      margin-top: 0.75rem;
      animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .day-exclusion-container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 0.75rem;
      padding: 1rem;
      background: rgba(94, 99, 255, 0.05);
      border-radius: 8px;
      border: 1px solid var(--line);
    }

    .day-checkbox {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      cursor: pointer;
      padding: 0.5rem;
      border-radius: 6px;
      transition: background 0.2s ease;
      user-select: none;
    }

    .day-checkbox:hover {
      background: rgba(94, 99, 255, 0.1);
    }

    .day-checkbox input[type="checkbox"] {
      width: 18px;
      height: 18px;
      cursor: pointer;
      accent-color: var(--accent);
    }

    .form-hint {
      display: block;
      margin-top: 0.5rem;
      color: var(--text-muted);
      font-size: 0.85rem;
    }

    @media (max-width: 600px) {
      main {
        padding: 1rem;
      }
      
      .form-card {
        padding: 2rem 1.5rem;
        border-radius: 16px;
      }
      
      .form-card h1 {
        font-size: 1.75rem;
      }
      
      .form-row {
        grid-template-columns: 1fr;
        gap: 1rem;
      }
      
      .form-group select,
      .form-group input {
        padding: 12px 14px;
        font-size: 16px; /* Prevents zoom on iOS */
      }
    }

    @media (max-width: 480px) {
      .form-card {
        padding: 1.5rem 1rem;
        margin: 0.5rem;
      }
      
      .form-card h1 {
        font-size: 1.5rem;
      }
    }
  </style>
</head>
<body>
  <main>
    <form action="save__shift.php" method="POST" onsubmit="return validateShiftForm();" class="form-card">
      <h1>Assign Scheduled Shift</h1>
      <p class="subtitle">Please select a coach to assign a shift to</p>

      <div class="form-group">
        <label for="staff_user_id">Select Coach</label>
        <select id="staff_user_id" name="staff_user_id" required>
          <option value="">Choose Coach</option>
        </select>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="shift_date_start">Start Date</label>
          <input type="date" id="shift_date_start" name="shift_date_start" required min="<?= date('Y-m-d'); ?>">
        </div>

        <div class="form-group">
          <label for="shift_date_end">End Date</label>
          <input type="date" id="shift_date_end" name="shift_date_end" required min="<?= date('Y-m-d'); ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="start_time">Start Time</label>
          <input type="time" id="start_time" name="start_time" required min="07:00" max="22:00">
          <small class="form-hint">Schedule time must be between 7:00 AM and 10:00 PM</small>
        </div>

        <div class="form-group">
          <label for="end_time">End Time</label>
          <input type="time" id="end_time" name="end_time" required min="07:00" max="22:00">
        </div>
      </div>

      <!-- Day Exclusion Section -->
      <div class="form-group">
        <div class="day-exclusion-header" onclick="toggleDayExclusion()">
          <label>Exclude Days (Optional)</label>
          <svg id="dayExclusionArrow" xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512" fill="var(--text-secondary)" class="dropdown-arrow">
            <path d="M233.4 406.6c12.5 12.5 32.8 12.5 45.3 0l192-192c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L256 338.7 86.6 169.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3l192 192z"/>
          </svg>
        </div>
        <div id="dayExclusionContent" class="day-exclusion-content" style="display: none;">
          <div class="day-exclusion-container">
            <label class="day-checkbox">
              <input type="checkbox" name="exclude_days[]" value="1"> Monday
            </label>
            <label class="day-checkbox">
              <input type="checkbox" name="exclude_days[]" value="2"> Tuesday
            </label>
            <label class="day-checkbox">
              <input type="checkbox" name="exclude_days[]" value="3"> Wednesday
            </label>
            <label class="day-checkbox">
              <input type="checkbox" name="exclude_days[]" value="4"> Thursday
            </label>
            <label class="day-checkbox">
              <input type="checkbox" name="exclude_days[]" value="5"> Friday
            </label>
            <label class="day-checkbox">
              <input type="checkbox" name="exclude_days[]" value="6"> Saturday
            </label>
            <label class="day-checkbox">
              <input type="checkbox" name="exclude_days[]" value="0"> Sunday
            </label>
          </div>
          <small class="form-hint">Check days you want to exclude from scheduling</small>
        </div>
      </div>

      <!-- Conflict Warning Display -->
      <div id="conflictWarning" class="conflict-warning" style="display: none;">
        <div class="conflict-header" onclick="toggleConflictDetails()">
          <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512" fill="#f59e0b">
            <path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c13.3 0 24 10.7 24 24V264c0 13.3-10.7 24-24 24s-24-10.7-24-24V152c0-13.3 10.7-24 24-24zM224 352a32 32 0 1 1 64 0 32 32 0 1 1 -64 0z"/>
          </svg>
          <span id="conflictSummary">Schedule Conflict Detected!</span>
          <svg id="conflictToggleIcon" xmlns="http://www.w3.org/2000/svg" height="16" width="16" viewBox="0 0 512 512" fill="#f59e0b" class="toggle-icon">
            <path d="M233.4 406.6c12.5 12.5 32.8 12.5 45.3 0l192-192c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L256 338.7 86.6 169.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3l192 192z"/>
          </svg>
        </div>
        <div id="conflictDetails" class="conflict-details" style="display: none;"></div>
      </div>
      
      <button type="submit" class="btn" id="submitBtn">Assign Shift</button>
    </form>
  </main>

  <script>
    let conflictCheckTimeout;
    let isCheckingConflict = false;

    function loadCoaches() {
      const userSelect = document.getElementById('staff_user_id');
      userSelect.innerHTML = '<option>Loading...</option>';

      fetch('fetch_users_by_roles.php?role=coach')
        .then(res => res.json())
        .then(data => {
          userSelect.innerHTML = '';
          if (data.length === 0) {
            const option = document.createElement('option');
            option.text = 'No coaches found';
            option.disabled = true;
            option.selected = true;
            userSelect.appendChild(option);
          } else {
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.text = 'Choose Coach';
            userSelect.appendChild(defaultOption);

            data.forEach(user => {
              const option = document.createElement('option');
              option.value = user.id;
              option.textContent = user.full_name;
              userSelect.appendChild(option);
            });
          }
        })
        .catch(() => {
          userSelect.innerHTML = '<option disabled selected>Failed to load coaches</option>';
        });
    }

    // Load coaches when page loads
    document.addEventListener('DOMContentLoaded', function() {
      loadCoaches();
      
      // Initialize conflict detection
      const formInputs = [
        'select[name="staff_user_id"]',
        'input[name="shift_date_start"]',
        'input[name="shift_date_end"]',
        'input[name="start_time"]',
        'input[name="end_time"]'
      ];

      formInputs.forEach(selector => {
        const element = document.querySelector(selector);
        if (element) {
          element.addEventListener('change', checkForConflicts);
          element.addEventListener('input', checkForConflicts);
        }
      });
    });

    async function checkForConflicts() {
      // Clear previous timeout
      if (conflictCheckTimeout) {
        clearTimeout(conflictCheckTimeout);
      }

      // Debounce the conflict check
      conflictCheckTimeout = setTimeout(async () => {
        await performConflictCheck();
      }, 500);
    }

    async function performConflictCheck() {
      const staffId = document.querySelector('select[name="staff_user_id"]').value;
      const startDate = document.querySelector('input[name="shift_date_start"]').value;
      const endDate = document.querySelector('input[name="shift_date_end"]').value;
      const startTime = document.querySelector('input[name="start_time"]').value;
      const endTime = document.querySelector('input[name="end_time"]').value;

      // Don't check if required fields are empty
      if (!staffId || !startDate || !endDate || !startTime || !endTime) {
        hideConflictWarning();
        return;
      }

      // Basic validation
      if (startDate > endDate || startTime >= endTime) {
        hideConflictWarning();
        return;
      }

      if (isCheckingConflict) return;
      isCheckingConflict = true;

      try {
        const response = await fetch('check_schedule_conflict.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            staff_id: staffId,
            start_date: startDate,
            end_date: endDate,
            start_time: startTime,
            end_time: endTime
          })
        });

        const data = await response.json();

        if (data.has_conflict) {
          showConflictWarning(data.conflicts);
        } else {
          hideConflictWarning();
        }
      } catch (error) {
        console.error('Error checking conflicts:', error);
        hideConflictWarning();
      } finally {
        isCheckingConflict = false;
      }
    }

    function showConflictWarning(conflicts) {
      const warningDiv = document.getElementById('conflictWarning');
      const detailsDiv = document.getElementById('conflictDetails');
      const submitBtn = document.getElementById('submitBtn');
      const summarySpan = document.getElementById('conflictSummary');
      const toggleIcon = document.getElementById('conflictToggleIcon');

      // Update summary text with conflict count
      const conflictCount = conflicts.length;
      summarySpan.textContent = `Schedule Conflict Detected! (${conflictCount} conflict${conflictCount > 1 ? 's' : ''})`;

      // Group conflicts by coach name and conflict type
      const groupedConflicts = {};
      conflicts.forEach(conflict => {
        const key = `${conflict.coach_name}_${conflict.conflict_type}`;
        if (!groupedConflicts[key]) {
          groupedConflicts[key] = {
            coach_name: conflict.coach_name,
            conflict_type: conflict.conflict_type,
            conflicts: []
          };
        }
        groupedConflicts[key].conflicts.push(conflict);
      });

      // Build simplified conflict details HTML
      let detailsHTML = '';
      Object.values(groupedConflicts).forEach(group => {
        const conflictTypeText = 'This coach already has overlapping shifts';
        
        const conflictCount = group.conflicts.length;
        const dates = group.conflicts.map(c => c.date).sort();
        const uniqueDates = [...new Set(dates)];
        
        // Group consecutive dates into ranges
        const dateGroups = groupConsecutiveDates(uniqueDates);
        const dateListItems = [];
        
        dateGroups.forEach(group => {
          if (group.length === 1) {
            // Single date - show with weekday
            dateListItems.push(`• ${formatDate(group[0])}`);
          } else {
            // Range of consecutive dates - show as range without weekday
            const startDate = formatDateWithoutWeekday(group[0]);
            const endDate = formatDateWithoutWeekday(group[group.length - 1]);
            dateListItems.push(`• ${startDate} - ${endDate}`);
          }
        });
        
        const dateList = dateListItems.join('<br>');
        
        // Get time range (same time for all conflicts in this group typically)
        const timeRange = group.conflicts[0];
        const timeText = `${formatTime(timeRange.existing_start)} - ${formatTime(timeRange.existing_end)}`;
        
        detailsHTML += `
          <div class="conflict-item">
            <div class="conflict-coach">${group.coach_name}</div>
            <div class="conflict-type">${conflictTypeText}</div>
            <div class="conflict-summary">
              <span class="conflict-count">${conflictCount} conflict${conflictCount > 1 ? 's' : ''}</span>
              <span class="conflict-separator">•</span>
              <span class="conflict-time">${timeText}</span>
            </div>
            <div class="conflict-date">${dateList}</div>
          </div>
        `;
      });

      detailsDiv.innerHTML = detailsHTML;
      warningDiv.style.display = 'block';
      
      // Reset toggle state
      detailsDiv.style.display = 'none';
      toggleIcon.classList.remove('expanded');
      
      // Disable submit button
      submitBtn.disabled = true;
      submitBtn.textContent = 'Cannot Assign - Conflicts Detected';
    }

    function hideConflictWarning() {
      const warningDiv = document.getElementById('conflictWarning');
      const submitBtn = document.getElementById('submitBtn');

      warningDiv.style.display = 'none';
      
      // Enable submit button
      submitBtn.disabled = false;
      submitBtn.textContent = 'Assign Shift';
    }

    function toggleConflictDetails() {
      const detailsDiv = document.getElementById('conflictDetails');
      const toggleIcon = document.getElementById('conflictToggleIcon');
      
      if (detailsDiv.style.display === 'none' || detailsDiv.style.display === '') {
        detailsDiv.style.display = 'block';
        toggleIcon.classList.add('expanded');
      } else {
        detailsDiv.style.display = 'none';
        toggleIcon.classList.remove('expanded');
      }
    }

    function formatDate(dateString) {
      const date = new Date(dateString);
      return date.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      });
    }

    function formatDateWithoutWeekday(dateString) {
      const date = new Date(dateString);
      return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      });
    }

    function groupConsecutiveDates(dates) {
      if (dates.length === 0) return [];
      
      const sortedDates = [...dates].sort();
      const groups = [];
      let currentGroup = [sortedDates[0]];
      
      for (let i = 1; i < sortedDates.length; i++) {
        const prevDate = new Date(sortedDates[i - 1]);
        const currentDate = new Date(sortedDates[i]);
        const daysDiff = (currentDate - prevDate) / (1000 * 60 * 60 * 24);
        
        if (daysDiff === 1) {
          // Consecutive date, add to current group
          currentGroup.push(sortedDates[i]);
        } else {
          // Not consecutive, save current group and start new one
          groups.push(currentGroup);
          currentGroup = [sortedDates[i]];
        }
      }
      
      // Add the last group
      groups.push(currentGroup);
      
      return groups;
    }

    function formatTime(timeString) {
      // Convert 24-hour format (HH:MM:SS or HH:MM) to 12-hour format with AM/PM
      const [hours, minutes] = timeString.split(':');
      const hour24 = parseInt(hours, 10);
      const minute = parseInt(minutes, 10);
      
      let hour12 = hour24;
      let ampm = 'AM';
      
      if (hour24 === 0) {
        hour12 = 12;
      } else if (hour24 === 12) {
        hour12 = 12;
        ampm = 'PM';
      } else if (hour24 > 12) {
        hour12 = hour24 - 12;
        ampm = 'PM';
      }
      
      return `${hour12}:${minute.toString().padStart(2, '0')} ${ampm}`;
    }

    function toggleDayExclusion() {
      const content = document.getElementById('dayExclusionContent');
      const arrow = document.getElementById('dayExclusionArrow');
      
      if (content.style.display === 'none' || content.style.display === '') {
        content.style.display = 'block';
        arrow.classList.add('expanded');
      } else {
        content.style.display = 'none';
        arrow.classList.remove('expanded');
      }
    }

    function validateShiftForm() {
      const startDate = document.getElementById("shift_date_start").value;
      const endDate = document.getElementById("shift_date_end").value;
      const startTime = document.getElementById("start_time").value;
      const endTime = document.getElementById("end_time").value;
      
      if (startDate > endDate) {
        alert("End date must be after or equal to start date.");
        return false;
      }
      
      if (startTime >= endTime) {
        alert("End time must be after start time.");
        return false;
      }

      // Validate time range (7:00 AM to 10:00 PM)
      if (startTime < '07:00' || startTime > '22:00') {
        alert("Start time must be between 7:00 AM and 10:00 PM (Philippine Time).");
        return false;
      }

      if (endTime < '07:00' || endTime > '22:00') {
        alert("End time must be between 7:00 AM and 10:00 PM (Philippine Time).");
        return false;
      }

      // Check if there are active conflicts
      const warningDiv = document.getElementById('conflictWarning');
      if (warningDiv.style.display !== 'none') {
        alert("Please resolve schedule conflicts before submitting.");
        return false;
      }

      return true;
    }
  </script>
</body>
</html>

