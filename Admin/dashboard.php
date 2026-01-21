<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session before any output
if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
    session_name('gym_admin_session');
    session_start();
}

include '../config.php';

$members_result = mysqli_query($conn, "SELECT COUNT(*) AS total_members FROM members m INNER JOIN users u ON m.user_id = u.id WHERE m.status = 'active' AND u.role = 'customer'");
$members = mysqli_fetch_assoc($members_result)['total_members'];

$staff_result = mysqli_query($conn, "SELECT COUNT(*) AS total_staff FROM users WHERE role IN ('staff', 'coach') AND archived = 0");
$total_staff = mysqli_fetch_assoc($staff_result)['total_staff'];

$revenue_result = mysqli_query($conn, "
  SELECT SUM(amount) AS monthly_revenue 
  FROM transactions 
  WHERE MONTH(date) = MONTH(CURRENT_DATE()) 
    AND YEAR(date) = YEAR(CURRENT_DATE())
");
$revenue_row = mysqli_fetch_assoc($revenue_result);
$monthly_revenue = isset($revenue_row['monthly_revenue']) ? $revenue_row['monthly_revenue'] : 0;

// Today's Revenue
$todays_revenue_result = mysqli_query($conn, "
  SELECT SUM(amount) AS todays_revenue 
  FROM transactions 
  WHERE DATE(date) = CURDATE()
");
$todays_revenue_row = mysqli_fetch_assoc($todays_revenue_result);
$todays_revenue = isset($todays_revenue_row['todays_revenue']) ? $todays_revenue_row['todays_revenue'] : 0;

$walkins_result = mysqli_query($conn, "
  SELECT COUNT(*) AS today_walkins 
  FROM transactions 
  WHERE type = 'walk-in' AND DATE(date) = CURDATE()
");
$today_walkins = mysqli_fetch_assoc($walkins_result)['today_walkins'];

$attendance_result = mysqli_query($conn, "
  SELECT COUNT(*) AS today_attendance 
  FROM attendance 
  WHERE DATE(check_in_time) = CURDATE()
");
$today_attendance = mysqli_fetch_assoc($attendance_result)['today_attendance'];

$expiries_result = mysqli_query($conn, "
  SELECT COUNT(*) AS pending_expiries FROM subscriptions 
  WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
  AND status = 'active'
");
$pending_expiries = mysqli_fetch_assoc($expiries_result)['pending_expiries'];

$recent_walkins_result = mysqli_query($conn, "
  SELECT 
    name,
    TRIM(SUBSTRING_INDEX(name, ' ', -1)) as surname,
    TRIM(SUBSTRING_INDEX(name, ' ', CHAR_LENGTH(name) - CHAR_LENGTH(REPLACE(name, ' ', '')))) as first_and_middle_name,
    package, 
    amount, 
    date
  FROM walk_in_log 
  WHERE DATE(date) = CURDATE()
    AND name NOT LIKE '%E2E Test User%'
  ORDER BY date DESC 
  LIMIT 5
");

$today_roster_result = mysqli_query($conn, "
  SELECT 
    u.full_name,
    TRIM(SUBSTRING_INDEX(u.full_name, ' ', -1)) as surname,
    TRIM(SUBSTRING_INDEX(u.full_name, ' ', CHAR_LENGTH(u.full_name) - CHAR_LENGTH(REPLACE(u.full_name, ' ', '')))) as first_and_middle_name,
    rs.start_time, 
    rs.end_time, 
    rs.role
  FROM roster_shifts rs
  JOIN users u ON rs.staff_user_id = u.id
  WHERE rs.shift_date = CURDATE()
  ORDER BY rs.start_time ASC
");

$expiring_members_result = mysqli_query($conn, "
  SELECT 
    u.full_name,
    TRIM(SUBSTRING_INDEX(u.full_name, ' ', -1)) as surname,
    TRIM(SUBSTRING_INDEX(u.full_name, ' ', CHAR_LENGTH(u.full_name) - CHAR_LENGTH(REPLACE(u.full_name, ' ', '')))) as first_and_middle_name,
    s.plan_name, 
    s.expiry_date
  FROM subscriptions s
  JOIN members m ON s.member_id = m.id
  JOIN users u ON m.user_id = u.id
  WHERE s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND s.status = 'active'
  ORDER BY s.expiry_date ASC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard</title>
  <style>
    :root {
      --accent: #5e63ff;
      --bg: #111;
      --card-bg: #1c1d25;
      --line: #444;
      --font: 'Poppins', sans-serif;
    }

    body {
      margin: 0;
      font-family: var(--font);
      background: var(--bg);
      color: white;
      overflow-x: hidden;
    }

    main {
      padding: 2rem;
      width: 100%;
      max-width: 100%;
      overflow-x: hidden;
      box-sizing: border-box;
    }

    h1 {
      margin-bottom: 1rem;
      font-size: 2rem;
    }

    .dashboard-grid {
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
      margin-bottom: 2rem;
      animation: fadeInUp 0.6s ease-out;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .card {
      background: var(--card-bg);
      padding: 1.5rem;
      border-radius: 12px;
      flex: 1 1 200px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.15);
      text-align: center;
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      cursor: pointer;
      position: relative;
      overflow: hidden;
      border: 1px solid transparent;
      min-width: 0;
      box-sizing: border-box;
    }

    .card:hover {
      transform: translateY(-4px) scale(1.01);
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
      background: linear-gradient(135deg, #252630 0%, #2a2b36 100%);
      border-color: rgba(94, 99, 255, 0.3);
    }

    .card:hover h2 {
      color: #7c82ff;
      transform: scale(1.02);
    }

    .card:hover p {
      color: #e0e0e0;
      transform: translateY(-2px);
    }

    .card:active {
      transform: translateY(-2px) scale(1.005);
      transition: all 0.1s ease;
    }

    .card a {
      text-decoration: none;
      color: inherit;
      display: block;
      width: 100%;
      height: 100%;
      position: relative;
      z-index: 1;
    }

    .card h2 {
      margin: 0;
      font-size: 1.5rem;
      color: var(--accent);
      transition: all 0.3s ease;
      position: relative;
      word-wrap: break-word;
    }

    .card p {
      margin-top: 8px;
      font-size: 1rem;
      transition: color 0.3s ease;
      word-wrap: break-word;
    }

    .card small {
      display: block;
      margin-top: 6px;
      font-size: 0.75rem;
      color: #5e63ff;
      font-weight: 500;
      letter-spacing: 0.5px;
      transition: all 0.3s ease;
    }

    .card:hover small {
      color: #7c82ff;
      transform: translateY(-1px);
    }

    .section-date {
      display: block;
      margin-top: 4px;
      margin-bottom: 1rem;
      font-size: 0.75rem;
      color: #888;
      font-weight: 500;
      letter-spacing: 0.5px;
    }

    .section {
      background: var(--card-bg);
      padding: 1.5rem;
      border-radius: 10px;
      margin-bottom: 2rem;
      overflow: hidden;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
    }

    .section h3 {
      margin-bottom: 1rem;
      color: var(--accent);
      font-size: 1.25rem;
    }

    .two-column {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 2rem;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
    }

    .table-wrapper {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      margin-top: 1rem;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      box-sizing: border-box;
    }

    th, td {
      padding: 10px;
      border: 1px solid var(--line);
      text-align: left;
      font-size: 0.9rem;
      word-wrap: break-word;
      overflow-wrap: break-word;
    }

    th {
      background: #2a2b36;
      font-weight: 600;
      white-space: nowrap;
    }

    td {
      word-wrap: break-word;
      overflow-wrap: break-word;
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

      .dashboard-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .dashboard-grid .card {
        flex: 1 1 calc(50% - 0.5rem);
        min-width: 0;
        max-width: 100%;
        box-sizing: border-box;
      }

      .two-column {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.5rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .section {
        width: 100%;
        max-width: 100%;
        padding: 1.25rem;
        box-sizing: border-box;
      }

      table {
        width: 100%;
      }

      main h1 {
        font-size: 1.5rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .section h3 {
        font-size: 1.25rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
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
        padding: 1rem;
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

      .table-wrapper {
        /* Hide scrollbar but keep scrolling functionality */
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
      }

      .table-wrapper::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
      }

      main h1 {
        font-size: 1.25rem;
        margin-bottom: 1rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
        padding-right: 0;
      }

      .dashboard-grid {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .dashboard-grid .card {
        flex: 1 1 100%;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
      }

      .card {
        padding: 1rem;
      }

      .card h2 {
        font-size: 1.5rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .card p {
        font-size: 0.9rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .card small {
        font-size: 0.7rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .two-column {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .section {
        width: 100%;
        max-width: 100%;
        padding: 1rem;
        margin-bottom: 1rem;
        box-sizing: border-box;
      }

      .section h3 {
        font-size: 1.1rem;
        margin-bottom: 0.75rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
        width: 100%;
      }

      .section-date {
        font-size: 0.7rem;
        margin-bottom: 0.75rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .table-wrapper {
        overflow-x: auto;
        width: 100%;
        max-width: 100%;
        -webkit-overflow-scrolling: touch;
        box-sizing: border-box;
      }

      table {
        font-size: 0.8rem;
        width: 100%;
        box-sizing: border-box;
      }

      th, td {
        padding: 0.5rem 0.75rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      /* Ensure all text elements don't overflow */
      .card h2,
      .card p,
      .card small,
      .section h3,
      .section-date {
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

      .table-wrapper {
        /* Hide scrollbar but keep scrolling functionality */
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
      }

      .table-wrapper::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
      }

      main h1 {
        font-size: 1.125rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
        padding-right: 0;
      }

      .dashboard-grid {
        gap: 0.75rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .card {
        padding: 0.875rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        min-width: 0;
      }

      .card h2 {
        font-size: 1.25rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .card p {
        font-size: 0.85rem;
        margin-top: 6px;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .card small {
        font-size: 0.65rem;
        margin-top: 4px;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .section {
        padding: 0.875rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .section h3 {
        font-size: 1rem;
        margin-bottom: 0.5rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .section-date {
        font-size: 0.65rem;
        margin-bottom: 0.5rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
      }

      .two-column {
        gap: 1rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      .table-wrapper {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
      }

      table {
        width: 100%;
        font-size: 0.75rem;
      }

      th, td {
        padding: 0.4rem 0.5rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
        max-width: 150px;
      }
    }
  </style>
</head>
<body>
  <?php include 'navbar.php'; ?>

  <main>
    <h1>Admin Dashboard</h1>

    <div class="dashboard-grid">
      <div class="card">
        <a href="members__management.php">
          <h2><?= $members ?></h2>
          <p>Total Active Members</p>
        </a>
      </div>
      <div class="card">
        <a href="user__list.php">
          <h2><?= $total_staff ?></h2>
          <p>Coaches</p>
        </a>
      </div>
      <div class="card">
        <a href="revenue__report.php">
          <h2>₱<?= number_format($todays_revenue, 2) ?></h2>
          <p>Today's Revenue</p>
          <small><?= date('l, F j, Y') ?></small>
        </a>
      </div>
      <div class="card">
        <a href="revenue__report.php">
          <h2>₱<?= number_format($monthly_revenue, 2) ?></h2>
          <p>Monthly Revenue</p>
        </a>
      </div>
      <div class="card">
        <a href="walkin__log.php">
          <h2><?= $today_walkins ?></h2>
          <p>Walk-ins Today</p>
        </a>
      </div>
      <div class="card">
        <a href="#">
          <h2><?= $today_attendance ?></h2>
          <p>Today's Attendance</p>
        </a>
      </div>
      <!-- Removed Expiries in 7 Days card -->
    </div>

    <div class="section">
      <h3>Expiring Memberships <small class="section-date">In the next 7 days</small></h3>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Member Name</th>
              <th>Plan</th>
              <th>Expiry Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (mysqli_num_rows($expiring_members_result) > 0): ?>
              <?php while ($row = mysqli_fetch_assoc($expiring_members_result)): ?>
                <tr>
                  <td><?= htmlspecialchars($row['surname'] . ', ' . $row['first_and_middle_name']) ?></td>
                  <td><?= htmlspecialchars($row['plan_name']) ?></td>
                  <td><?= date('F j, Y', strtotime($row['expiry_date'])) ?></td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="3">No expiring memberships in the next 7 days.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="two-column">
      <div class="section">
        <h3>Today's Roster <small class="section-date"><?= date('l, F j, Y') ?></small></h3>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Staff Name</th>
                <th>Role</th>
                <th>Time</th>
              </tr>
            </thead>
            <tbody>
              <?php if (mysqli_num_rows($today_roster_result) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($today_roster_result)): ?>
                  <tr>
                    <td><?= htmlspecialchars($row['surname'] . ', ' . $row['first_and_middle_name']) ?></td>
                    <td><?= ucfirst(htmlspecialchars($row['role'])) ?></td>
                    <td><?= date('g:i A', strtotime($row['start_time'])) ?> - <?= date('g:i A', strtotime($row['end_time'])) ?></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="3">No staff scheduled for today.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="section">
        <h3>Recent Walk-ins <small class="section-date"><?= date('l, F j, Y') ?></small></h3>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Package</th>
                <th>Amount</th>
                <th>Time</th>
              </tr>
            </thead>
            <tbody>
              <?php if (mysqli_num_rows($recent_walkins_result) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($recent_walkins_result)): ?>
                  <tr>
                    <td><?= htmlspecialchars($row['surname'] . ', ' . $row['first_and_middle_name']) ?></td>
                    <td><?= htmlspecialchars($row['package']) ?></td>
                    <td>₱<?= number_format($row['amount'], 2) ?></td>
                    <td><?= date('g:i A', strtotime($row['date'])) ?></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="4">No recent walk-ins.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
