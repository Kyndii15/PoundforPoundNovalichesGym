<?php
// Get user data for header
if (!isset($conn)) {
    include '../config.php';
}

// Handle session - CRITICAL: Never call session functions if headers are sent
$headers_sent = headers_sent();
$user_id = null;

if (!$headers_sent) {
    // Only try to start session if headers haven't been sent
    if (session_status() == PHP_SESSION_NONE) {
        @session_name('gym_admin_session');
        @session_start();
    } elseif (session_status() == PHP_SESSION_ACTIVE) {
        // Session already active (might be started by the page itself)
        // Just use it - don't try to change session name
    }
    // Get user_id from session if available (works for both new and existing sessions)
    $user_id = $_SESSION['user_id'] ?? null;
} else {
    // Headers already sent - try to use existing session variables if session is active
    if (session_status() == PHP_SESSION_ACTIVE) {
        $user_id = $_SESSION['user_id'] ?? null;
    }
}
$user_data = null;
$profile_photo_path = null;

if ($user_id) {
    $user_query = "SELECT full_name, role, profile_photo FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $user_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user_data = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        if ($user_data && !empty($user_data['profile_photo']) && file_exists(__DIR__ . '/../uploads/profile_photos/' . $user_data['profile_photo'])) {
            $profile_photo_path = '../uploads/profile_photos/' . $user_data['profile_photo'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard</title>
  <link rel="stylesheet" href="../css/admin.css">
  <script type="text/javascript" src="../java/admin.js" defer></script>
  <style>
    .user-header {
      padding: 1rem;
      margin: 0 -1em 1rem -1em;
      margin-bottom: 1.5rem;
      border-bottom: 1px solid var(--line-clr);
      background: linear-gradient(135deg, rgba(94, 99, 255, 0.08) 0%, rgba(94, 99, 255, 0.03) 100%);
      display: flex;
      align-items: center;
      gap: 0.875rem;
      transition: all 0.3s ease;
      position: relative;
    }
    .user-header::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: linear-gradient(90deg, var(--accent-clr), transparent);
      opacity: 0.6;
    }
    .user-header.hidden {
      display: none;
    }
    .user-profile-pic {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      object-fit: cover;
      border: 2.5px solid var(--accent-clr);
      flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(94, 99, 255, 0.3);
      transition: all 0.3s ease;
    }
    .user-header:hover .user-profile-pic {
      transform: scale(1.05);
      box-shadow: 0 4px 12px rgba(94, 99, 255, 0.4);
    }
    .user-profile-placeholder {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--hover-clr) 0%, rgba(94, 99, 255, 0.1) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2.5px solid var(--accent-clr);
      flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(94, 99, 255, 0.2);
      transition: all 0.3s ease;
    }
    .user-header:hover .user-profile-placeholder {
      transform: scale(1.05);
      box-shadow: 0 4px 12px rgba(94, 99, 255, 0.3);
      background: linear-gradient(135deg, var(--hover-clr) 0%, rgba(94, 99, 255, 0.15) 100%);
    }
    .user-profile-placeholder svg {
      width: 24px;
      height: 24px;
      fill: var(--accent-clr);
      opacity: 0.8;
    }
    .user-info {
      flex: 1;
      min-width: 0;
    }
    .user-name {
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--text-clr);
      margin: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      letter-spacing: 0.3px;
    }
    .user-role {
      font-size: 0.8rem;
      color: var(--secondary-text-clr);
      margin: 0.35rem 0 0 0;
      text-transform: capitalize;
      font-weight: 500;
      display: inline-block;
      padding: 0.2rem 0.5rem;
      background: rgba(94, 99, 255, 0.1);
      border-radius: 12px;
      border: 1px solid rgba(94, 99, 255, 0.2);
    }
    #sidebar.close .user-header .user-info {
      display: none;
    }
    #sidebar.close .user-header {
      justify-content: center;
      padding: 1rem 0.5rem;
      margin: 0 -0.5rem 1rem -0.5rem;
    }
    #sidebar.close .user-profile-pic,
    #sidebar.close .user-profile-placeholder {
      width: 40px;
      height: 40px;
    }

    /* Mobile Bottom Navigation Styles */
    @media (max-width: 768px) {
      #sidebar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        top: auto;
        height: auto;
        width: 100%;
        padding: 0;
        border-right: none;
        border-top: 1px solid var(--line-clr);
        z-index: 1000;
        background-color: var(--base-clr);
        box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.3);
      }

      #sidebar.close {
        width: 100%;
        padding: 0;
      }

      #sidebar .user-header {
        display: none; /* Hide user header on mobile */
      }

      #sidebar > ul {
        display: flex;
        flex-direction: row;
        justify-content: space-around;
        align-items: center;
        padding: 0.5rem 0;
        margin: 0;
        height: 100%;
      }

      #sidebar > ul > li {
        flex: 1;
        margin: 0;
        display: flex;
        justify-content: center;
        align-items: center;
      }

      #sidebar > ul > li:first-child {
        display: none; /* Hide logo and toggle button on mobile */
      }

      /* Mobile dropdown backdrop overlay */
      #sidebar::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 70px;
        background-color: rgba(0, 0, 0, 0.5);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
        z-index: 1000;
        pointer-events: none;
      }

      #sidebar.has-open-dropdown::before {
        opacity: 1;
        visibility: visible;
        pointer-events: all;
      }

      /* Mobile dropdown button styling */
      #sidebar > ul > li .dropdown-btn {
        width: 100%;
        min-height: 60px;
        flex-direction: column;
        gap: 0.25rem;
        padding: 0.5rem 0.25rem;
        justify-content: center;
        align-items: center;
        border-radius: 0.5rem;
        transition: all 0.2s ease;
        position: relative;
      }

      #sidebar > ul > li .dropdown-btn svg:last-child {
        display: block;
        width: 16px;
        height: 16px;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        margin-top: 0.25rem;
      }

      #sidebar > ul > li .dropdown-btn.rotate svg:last-child {
        transform: rotate(180deg);
      }

      #sidebar > ul > li .dropdown-btn:active {
        background-color: var(--hover-clr);
        transform: scale(0.98);
      }

      /* Mobile dropdown menu styles */
      #sidebar > ul > li .sub-menu {
        position: fixed;
        bottom: 70px;
        left: 0;
        right: 0;
        background-color: var(--base-clr);
        border-top: 2px solid var(--accent-clr);
        box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.4);
        z-index: 1001;
        max-height: 0;
        overflow: hidden;
        opacity: 0;
        transform: translateY(20px);
        transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                    opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                    transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: block !important;
        border-radius: 16px 16px 0 0;
      }

      #sidebar > ul > li .sub-menu.show {
        max-height: 120px;
        padding: 1rem 0;
        opacity: 1;
        transform: translateY(0);
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
      }

      #sidebar > ul > li .sub-menu > div {
        display: flex;
        flex-direction: row;
        gap: 0.75rem;
        padding: 0 1rem;
        opacity: 0;
        transition: opacity 0.2s ease 0.1s;
        min-height: fit-content;
        width: max-content;
      }

      #sidebar > ul > li .sub-menu.show > div {
        opacity: 1;
      }

      /* Custom scrollbar for mobile dropdown (horizontal) */
      #sidebar > ul > li .sub-menu.show::-webkit-scrollbar {
        height: 4px;
      }

      #sidebar > ul > li .sub-menu.show::-webkit-scrollbar-track {
        background: transparent;
      }

      #sidebar > ul > li .sub-menu.show::-webkit-scrollbar-thumb {
        background: rgba(94, 99, 255, 0.3);
        border-radius: 2px;
      }

      #sidebar > ul > li .sub-menu.show::-webkit-scrollbar-thumb:hover {
        background: rgba(94, 99, 255, 0.5);
      }

      #sidebar > ul > li .sub-menu li {
        list-style: none;
        margin: 0;
        flex-shrink: 0;
        width: auto;
      }

      #sidebar > ul > li .sub-menu a {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0.875rem 1.25rem;
        color: var(--text-clr);
        text-decoration: none;
        border-radius: 0.75rem;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        font-size: 0.9rem;
        font-weight: 500;
        min-height: auto;
        flex-direction: column;
        gap: 0.5rem;
        background-color: transparent;
        border: none;
        margin: 0;
        white-space: nowrap;
        min-width: 120px;
      }

      #sidebar > ul > li .sub-menu a svg {
        width: 24px;
        height: 24px;
        flex-shrink: 0;
        opacity: 0.9;
      }

      #sidebar > ul > li .sub-menu a:hover,
      #sidebar > ul > li .sub-menu a:active {
        background-color: transparent;
        transform: translateY(-2px);
        opacity: 0.8;
      }

      #sidebar > ul > li .sub-menu a span {
        font-size: 0.85rem;
        margin-top: 0;
        text-align: center;
        line-height: 1.2;
      }

      #sidebar a {
        flex-direction: column;
        padding: 0.5rem 0.25rem;
        gap: 0.25rem;
        width: 100%;
        justify-content: center;
        text-align: center;
        border-radius: 0.5rem;
        min-height: 60px;
      }

      #sidebar a span {
        font-size: 0.7rem;
        flex-grow: 0;
        display: block;
        margin-top: 0.25rem;
      }

      #sidebar svg {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
      }

      #sidebar a:hover,
      #sidebar a:active {
        background-color: var(--hover-clr);
      }

      #sidebar ul li.active a {
        color: var(--accent-clr);
        background-color: rgba(94, 99, 255, 0.1);
      }

      #sidebar ul li.active a svg {
        fill: var(--accent-clr);
      }

      /* Add bottom padding to main content to prevent overlap */
      body {
        padding-bottom: 70px;
      }

      main {
        padding-bottom: 80px;
      }
    }

    /* Extra small mobile devices */
    @media (max-width: 480px) {
      #sidebar a span {
        font-size: 0.65rem;
      }

      #sidebar svg {
        width: 18px;
        height: 18px;
      }

      #sidebar a {
        padding: 0.4rem 0.2rem;
        min-height: 55px;
      }

      #sidebar > ul > li .dropdown-btn {
        min-height: 55px;
        padding: 0.4rem 0.2rem;
      }

      #sidebar > ul > li .sub-menu {
        bottom: 65px;
      }

      #sidebar > ul > li .sub-menu.show {
        max-height: 110px;
        padding: 0.875rem 0;
      }

      #sidebar > ul > li .sub-menu a {
        padding: 0.75rem 1rem;
        font-size: 0.85rem;
        min-width: 100px;
      }

      #sidebar > ul > li .sub-menu a svg {
        width: 20px;
        height: 20px;
      }

      #sidebar::before {
        bottom: 65px;
      }

      body {
        padding-bottom: 65px;
      }

      main {
        padding-bottom: 75px;
      }
    }
  </style>
</head>
<body>
  <nav id="sidebar">
    <div class="user-header">
      <?php if ($profile_photo_path): ?>
        <img src="<?= htmlspecialchars($profile_photo_path) ?>" alt="Profile" class="user-profile-pic">
      <?php else: ?>
        <div class="user-profile-placeholder">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M224 256A128 128 0 1 0 224 0a128 128 0 1 0 0 256zm-45.7 48C79.8 304 0 383.8 0 482.3C0 498.7 13.3 512 29.7 512H418.3c16.4 0 29.7-13.3 29.7-29.7C448 383.8 368.2 304 269.7 304H178.3z"/></svg>
        </div>
      <?php endif; ?>
      <div class="user-info">
        <p class="user-name"><?= htmlspecialchars($user_data['full_name'] ?? 'Admin') ?></p>
        <p class="user-role"><?= htmlspecialchars(ucfirst($user_data['role'] ?? 'Admin')) ?></p>
      </div>
    </div>
    <ul>
      <li class="header-row">
        <img src="../assets/logo_optimized.png" alt="Pound for Pound" class="navbar-logo">
        <button onclick="toggleSidebar()" id="toggle-btn">
          <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e8eaed"><path d="m313-480 155 156q11 11 11.5 27.5T468-268q-11 11-28 11t-28-11L228-452q-6-6-8.5-13t-2.5-15q0-8 2.5-15t8.5-13l184-184q11-11 27.5-11.5T468-692q11 11 11 28t-11 28L313-480Zm264 0 155 156q11 11 11.5 27.5T732-268q11 11-28 11t-28-11L492-452q-6-6-8.5-13t-2.5-15q0-8 2.5-15t8.5-13l184-184q11-11 27.5-11.5T732-692q11 11 11 28t-11 28L577-480Z"/></svg>
        </button>
      </li>
      <li>
        <a href="dashboard.php">
          <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" viewBox="0 0 512 512"><!--!Font Awesome Free 6.7.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M64 64c0-17.7-14.3-32-32-32S0 46.3 0 64L0 400c0 44.2 35.8 80 80 80l400 0c17.7 0 32-14.3 32-32s-14.3-32-32-32L80 416c-8.8 0-16-7.2-16-16L64 64zm406.6 86.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L320 210.7l-57.4-57.4c-12.5-12.5-32.8-12.5-45.3 0l-112 112c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L240 221.3l57.4 57.4c12.5 12.5 32.8 12.5 45.3 0l128-128z"/></svg>
          <span>Dashboard</span>
        </a>
      </li>
      <li>
        <button class="dropdown-btn">
          <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" viewBox="0 0 512 512"><!--!Font Awesome Free 6.7.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M256 0c4.6 0 9.2 1 13.4 2.9L457.7 82.8c22 9.3 38.4 31 38.3 57.2c-.5 99.2-41.3 280.7-213.6 363.2c-16.7 8-36.1 8-52.8 0C57.3 420.7 16.5 239.2 16 140c-.1-26.2 16.3-47.9 38.3-57.2L242.7 2.9C246.8 1 251.4 0 256 0zm0 128a128 128 0 1 0 0 256 128 128 0 1 0 0-256zm0 96a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>
          <span>Management</span>
          <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e8eaed"><path d="M480-361q-8 0-15-2.5t-13-8.5L268-556q-11-11-11-28t11-28q11-11 28-11t28 11l156 156 156-156q11-11 28-11t28 11q11 11 11 28t-11 28L508-372q-6 6-13 8.5t-15 2.5Z"/></svg>
        </button>
        <ul class="sub-menu">
          <div>
            <li><a href="user__list.php">User Management</a></li>
            <li><a href="members__management.php">Gym Members</a></li>
          </div>
        </ul>
      </li>
      <li>
        <button class="dropdown-btn">
          <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" viewBox="0 0 512 512"><!--!Font Awesome Free 6.7.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M469.3 19.3l23.4 23.4c25 25 25 65.5 0 90.5l-56.4 56.4L322.3 75.7l56.4-56.4c25-25 65.5-25 90.5 0zM44.9 353.2L299.7 98.3 413.7 212.3 158.8 467.1c-6.7 6.7-15.1 11.6-24.2 14.2l-104 29.7c-8.4 2.4-17.4 .1-23.6-6.1s-8.5-15.2-6.1-23.6l29.7-104c2.6-9.2 7.5-17.5 14.2-24.2zM249.4 103.4L103.4 249.4 16 161.9c-18.7-18.7-18.7-49.1 0-67.9L94.1 16c18.7-18.7 49.1-18.7 67.9 0l19.8 19.8c-.3 .3-.7 .6-1 .9l-64 64c-6.2 6.2-6.2 16.4 0 22.6s16.4 6.2 22.6 0l64-64c.3-.3 .6-.7 .9-1l45.1 45.1zM408.6 262.6l45.1 45.1c-.3 .3-.7 .6-1 .9l-64 64c-6.2 6.2-6.2 16.4 0 22.6s16.4 6.2 22.6 0l64-64c.3-.3 .6-.7 .9-1L496 350.1c18.7 18.7 18.7 49.1 0 67.9L417.9 496c-18.7 18.7-49.1 18.7-67.9 0l-87.4-87.4L408.6 262.6z"/></svg>
          <span>Scheduling</span>
          <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e8eaed"><path d="M480-361q-8 0-15-2.5t-13-8.5L268-556q-11-11-11-28t11-28q11-11 28-11t28 11l156 156 156-156q11-11 28-11t28 11q11 11 11 28t-11 28L508-372q-6 6-13 8.5t-15 2.5Z"/></svg>
        </button>
        <ul class="sub-menu">
          <div>
            <li><a href="roster__form.php" target="_self">Assign Schedule</a></li>
             <li><a href="schedule__viewer.php">Schedule Viewer</a></li>
          </div>
        </ul>
      </li>
      <li>
        <button class="dropdown-btn">
          <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" viewBox="0 0 512 512"><!--!Font Awesome Free 6.7.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M96 96c0-35.3 28.7-64 64-64l288 0c35.3 0 64 28.7 64 64l0 320c0 35.3-28.7 64-64 64L80 480c-44.2 0-80-35.8-80-80L0 128c0-17.7 14.3-32 32-32s32 14.3 32 32l0 272c0 8.8 7.2 16 16 16s16-7.2 16-16L96 96zm64 24l0 80c0 13.3 10.7 24 24 24l112 0c13.3 0 24-10.7 24-24l0-80c0-13.3-10.7-24-24-24L184 96c-13.3 0-24 10.7-24 24zm208-8c0 8.8 7.2 16 16 16l48 0c8.8 0 16-7.2 16-16s-7.2-16-16-16l-48 0c-8.8 0-16 7.2-16 16zm0 96c0 8.8 7.2 16 16 16l48 0c8.8 0 16-7.2 16-16s-7.2-16-16-16l-48 0c-8.8 0-16 7.2-16 16zM160 304c0 8.8 7.2 16 16 16l256 0c8.8 0 16-7.2 16-16s-7.2-16-16-16l-256 0c-8.8 0-16 7.2-16 16zm0 96c0 8.8 7.2 16 16 16l256 0c8.8 0 16-7.2 16-16s-7.2-16-16-16l-256 0c-8.8 0-16 7.2-16 16z"/></svg>
          <span>Transactions</span>
          <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e8eaed"><path d="M480-361q-8 0-15-2.5t-13-8.5L268-556q-11-11-11-28t11-28q11-11 28-11t28 11l156 156 156-156q11-11 28-11t28 11q11 11 11 28t-11 28L508-372q-6 6-13 8.5t-15 2.5Z"/></svg>
        </button>
        <ul class="sub-menu">
          <div>
            <li><a href="walkin__log.php">Walk-in logs</a></li>
            <li><a href="membership_transactions.php">Membership</a></li>
            <li><a href="revenue__report.php">Revenue</a></li>
          </div>
        </ul>
      </li>
                <li>
                    <a href="attendance_scanner.php">
                        <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" viewBox="0 0 512 512"><!--!Font Awesome Free 6.7.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M160 0c-23.7 0-44.4 12.9-55.4 32L48 32C21.5 32 0 53.5 0 80L0 400c0 26.5 21.5 48 48 48l144 0 0-272c0-44.2 35.8-80 80-80l48 0 0-16c0-26.5-21.5-48-48-48l-56.6 0C204.4 12.9 183.7 0 160 0zM272 128c-26.5 0-48 21.5-48 48l0 272 0 16c0 26.5 21.5 48 48 48l192 0c26.5 0 48-21.5 48-48l0-220.1c0-12.7-5.1-24.9-14.1-33.9l-67.9-67.9c-9-9-21.2-14.1-33.9-14.1L320 128l-48 0zM160 40a24 24 0 1 1 0 48 24 24 0 1 1 0-48z"/></svg>
                        <span>Attendance</span>
                    </a>
                </li>
      <li>
        <a href="profile.php">
          <svg xmlns="http://www.w3.org/2000/svg" height="24" width="27" viewBox="0 0 576 512"><!--!Font Awesome Free 6.7.2 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M256 0l64 0c17.7 0 32 14.3 32 32l0 64c0 17.7-14.3 32-32 32l-64 0c-17.7 0-32-14.3-32-32l0-64c0-17.7 14.3-32 32-32zM64 64l128 0 0 48c0 26.5 21.5 48 48 48l96 0c26.5 0 48-21.5 48-48l0-48 128 0c35.3 0 64 28.7 64 64l0 320c0 35.3-28.7 64-64 64L64 512c-35.3 0-64-28.7-64-64L0 128C0 92.7 28.7 64 64 64zM176 437.3c0 5.9 4.8 10.7 10.7 10.7l202.7 0c5.9 0 10.7-4.8 10.7-10.7c0-29.5-23.9-53.3-53.3-53.3l-117.3 0c-29.5 0-53.3 23.9-53.3 53.3zM288 352a64 64 0 1 0 0-128 64 64 0 1 0 0 128z"/></svg>
          <span>Profile</span>
        </a>
      </li>
      <li>
        <a href="../logout.php">
        <svg xmlns="http://www.w3.org/2000/svg" height="32" width="32" viewBox="0 0 640 640"><!--!Font Awesome Free v7.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M569 337C578.4 327.6 578.4 312.4 569 303.1L425 159C418.1 152.1 407.8 150.1 398.8 153.8C389.8 157.5 384 166.3 384 176L384 256L272 256C245.5 256 224 277.5 224 304L224 336C224 362.5 245.5 384 272 384L384 384L384 464C384 473.7 389.8 482.5 398.8 486.2C407.8 489.9 418.1 487.9 425 481L569 337zM224 160C241.7 160 256 145.7 256 128C256 110.3 241.7 96 224 96L160 96C107 96 64 139 64 192L64 448C64 501 107 544 160 544L224 544C241.7 544 256 529.7 256 512C256 494.3 241.7 480 224 480L160 480C142.3 480 128 465.7 128 448L128 192C128 174.3 142.3 160 160 160L224 160z"/></svg>
          <span>Logout</span>
        </a>
      </li>
    </ul>
  </nav>

  <script>
    // Define toggleSidebar function immediately - must work before admin.js loads
    // This function will always work regardless of admin.js overriding it
    (function() {
      const workingToggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const toggleButton = document.getElementById('toggle-btn');
        if (sidebar) {
          sidebar.classList.toggle('close');
        }
        if (toggleButton) {
          toggleButton.classList.toggle('rotate');
        }
      };
      
      // Define as regular function for onclick attribute compatibility
      function toggleSidebar() {
        workingToggleSidebar();
      }
      
      // Expose to window immediately
      window.toggleSidebar = workingToggleSidebar;
      
      // After DOM loads, ensure it still works even if admin.js overrides it
      document.addEventListener('DOMContentLoaded', function() {
        // Restore our working function (admin.js might have overridden it)
        window.toggleSidebar = workingToggleSidebar;
        
        // Add direct click event listener as the primary method (more reliable than onclick)
        const toggleBtn = document.getElementById('toggle-btn');
        if (toggleBtn) {
          // Use addEventListener instead of onclick attribute for better reliability
          toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            workingToggleSidebar();
            return false;
          }, true); // Use capture phase for early execution
        }
      });
    })();

    // Global function to handle dropdown - ensures only ONE is open at a time
    // Make it globally available immediately
    window.closeAllDropdowns = function() {
      const sidebar = document.getElementById('sidebar');
      if (!sidebar) return;
      
      const allSubMenus = sidebar.querySelectorAll('.sub-menu');
      const allDropdownButtons = sidebar.querySelectorAll('.dropdown-btn');
      
      allSubMenus.forEach(menu => {
        menu.classList.remove('show');
      });
      
      allDropdownButtons.forEach(btn => {
        btn.classList.remove('rotate');
      });
      
      // Remove backdrop overlay class
      sidebar.classList.remove('has-open-dropdown');
    };

    window.toggleSubMenu = function(button) {
      if (!button) return;
      
      const subMenu = button.nextElementSibling;
      if (!subMenu || !subMenu.classList.contains('sub-menu')) {
        return;
      }
      
      const isCurrentlyOpen = subMenu.classList.contains('show');
      
      // FIRST: Close ALL dropdowns (including the current one) - SYNCHRONOUS
      window.closeAllDropdowns();
      
      // THEN: If the clicked dropdown was closed, open it now - SYNCHRONOUS
      if (!isCurrentlyOpen) {
        subMenu.classList.add('show');
        button.classList.add('rotate');
        
        // Add backdrop overlay class for mobile
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
          sidebar.classList.add('has-open-dropdown');
        }
      }
    };

    // Set up dropdown button event listeners on DOM ready
    document.addEventListener("DOMContentLoaded", function() {
      const sidebar = document.getElementById('sidebar');
      if (!sidebar) return;
      
      // Use a flag to ensure we only set up listeners once
      if (sidebar.dataset.dropdownListenersAttached === 'true') {
        return;
      }
      sidebar.dataset.dropdownListenersAttached = 'true';
      
      // Use event delegation - handle clicks on button OR any child element
      sidebar.addEventListener('click', function(e) {
        // Check if click is on dropdown button or any element inside it
        let button = e.target.closest('.dropdown-btn');
        
        // If closest didn't work, manually traverse up the DOM tree
        if (!button) {
          let element = e.target;
          while (element && element !== sidebar) {
            if (element.classList && element.classList.contains('dropdown-btn')) {
              button = element;
              break;
            }
            element = element.parentElement;
          }
        }
        
        if (button) {
          e.stopPropagation();
          e.preventDefault();
          window.toggleSubMenu(button);
          return false;
        }
      }, true); // Use capture phase for more reliable handling

      // Active link detection
      const currentPage = window.location.pathname.split("/").pop();
      const menuLinks = document.querySelectorAll("#sidebar a");

      menuLinks.forEach(link => {
        // Extract only the file name from the href
        const linkPage = link.getAttribute("href").split("/").pop();

        if (linkPage === currentPage) {
          link.parentElement.classList.add("active");

          // Open parent dropdown if on a submenu page
          const subMenu = link.closest(".sub-menu");
          if (subMenu) {
            subMenu.classList.add("show");
            const dropdownBtn = subMenu.previousElementSibling;
            if (dropdownBtn && dropdownBtn.classList.contains('dropdown-btn')) {
              dropdownBtn.classList.add("rotate");
            }
          }
        }
      });

      // Close dropdowns when clicking outside (especially useful on mobile)
      document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        // Check if click is on dropdown button or submenu
        const clickedDropdownBtn = e.target.closest('.dropdown-btn');
        const clickedSubMenu = e.target.closest('.sub-menu');
        const clickedInsideSidebar = sidebar.contains(e.target);
        const clickedRegularLink = e.target.closest('#sidebar > ul > li > a:not(.dropdown-btn)');
        
        // Check if clicking on backdrop (the ::before pseudo-element area)
        const isBackdropClick = !clickedInsideSidebar && sidebar.classList.contains('has-open-dropdown');
        
        // Close dropdown when clicking on backdrop
        if (isBackdropClick) {
          window.closeAllDropdowns();
        }
        // Close dropdown when clicking on a submenu link (to allow navigation)
        else if (clickedSubMenu && e.target.tagName === 'A') {
          setTimeout(() => {
            window.closeAllDropdowns();
          }, 100);
        }
        // Close dropdown when clicking outside sidebar completely
        else if (!clickedInsideSidebar) {
          window.closeAllDropdowns();
        }
        // Close dropdown when clicking on a regular (non-dropdown) menu item
        else if (clickedRegularLink && !clickedDropdownBtn && !clickedSubMenu) {
          window.closeAllDropdowns();
        }
      });

    });
  </script>

</body>
</html>