<?php
include '../config.php';

include 'navbar.php';

// Date filtering - default to today
$filter_date = $_GET['date'] ?? date('Y-m-d');
$date_condition = "DATE(t.date) = '$filter_date'";
$whereClause = "WHERE $date_condition";

// Fetch totals - only include transactions with receipts/proof
$totalQuery = $conn->query("SELECT 
    SUM(CASE WHEN t.type = 'membership' AND t.or_photo_path IS NOT NULL AND t.or_photo_path != '' THEN t.amount ELSE 0 END) AS membership_total,
    SUM(CASE WHEN t.type = 'walk-in' AND w.receipt_path IS NOT NULL AND w.receipt_path != '' THEN t.amount ELSE 0 END) AS walkin_total,
    SUM(CASE 
        WHEN (t.type = 'walk-in' AND w.receipt_path IS NOT NULL AND w.receipt_path != '')
             OR (t.type = 'membership' AND t.or_photo_path IS NOT NULL AND t.or_photo_path != '')
        THEN t.amount ELSE 0 END) AS total
    FROM transactions t
    LEFT JOIN walk_in_log w ON t.type = 'walk-in' AND t.amount = w.amount AND DATE(t.date) = DATE(w.date)
    $whereClause
    AND (
        (t.type = 'walk-in' AND w.receipt_path IS NOT NULL AND w.receipt_path != '')
        OR
        (t.type = 'membership' AND t.or_photo_path IS NOT NULL AND t.or_photo_path != '')
    )");
$totals = $totalQuery->fetch_assoc();

// Fetch detailed transactions with joins - only include transactions with receipts/proof
$transactions = $conn->query("
    SELECT 
        t.*,
        w.name as walkin_name,
        w.package as walkin_package,
        w.amount_given as walkin_amount_given,
        w.change_amount as walkin_change,
        w.receipt_path as walkin_receipt_path,
        w.payment_method as walkin_payment_method,
        COALESCE(
            (SELECT gr.reference_number 
             FROM gcash_references gr 
             WHERE gr.transaction_type = 'walkin_payment' 
               AND gr.payment_date BETWEEN DATE_SUB(w.date, INTERVAL 10 MINUTE) AND DATE_ADD(w.date, INTERVAL 10 MINUTE)
               AND ABS(gr.amount - w.amount) < 0.01
             LIMIT 1),
            (SELECT p.reference_number 
             FROM payments p 
             WHERE p.payment_method = 'gcash' 
               AND p.payment_date BETWEEN DATE_SUB(w.date, INTERVAL 10 MINUTE) AND DATE_ADD(w.date, INTERVAL 10 MINUTE)
               AND ABS(p.amount - w.amount) < 0.01
             LIMIT 1),
            NULL
        ) as walkin_gcash_reference,
        u.full_name as member_name,
        t.plan_name as member_package,
        t.customer_name as transaction_customer_name
    FROM transactions t
    LEFT JOIN walk_in_log w ON t.type = 'walk-in' AND t.amount = w.amount AND DATE(t.date) = DATE(w.date)
    LEFT JOIN users u ON t.user_id = u.id AND t.type = 'membership'
    $whereClause
    AND (
        (t.type = 'walk-in' AND w.receipt_path IS NOT NULL AND w.receipt_path != '')
        OR
        (t.type = 'membership' AND t.or_photo_path IS NOT NULL AND t.or_photo_path != '')
    )
    ORDER BY t.date DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Revenue View</title>
  <style>
    :root {
      --accent-clr: #5e63ff;
      --line-clr: #444;
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

    .filter-form {
      background: #1c1d25;
      padding: 1rem;
      margin-bottom: 2rem;
      border-radius: 10px;
    }

    form {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
    }

    input[type="date"] {
      padding: 10px;
      border-radius: 6px;
      border: 1px solid var(--line-clr);
      background: #2a2b36;
      color: white;
    }

    button {
      padding: 10px 16px;
      background: var(--accent-clr);
      color: white;
      border: none;
      border-radius: 6px;
      font-weight: bold;
      cursor: pointer;
    }

    .card-group {
      display: flex;
      gap: 20px;
      margin-bottom: 2rem;
      flex-wrap: wrap;
    }

    .card {
      background: #1c1d25;
      padding: 1rem 1.5rem;
      border-radius: 8px;
      flex: 1 1 200px;
      box-shadow: 0 0 8px rgba(0,0,0,0.2);
    }

    .card h2 {
      margin: 0;
      font-size: 1.3rem;
      color: var(--accent-clr);
    }

    .card p {
      margin: 6px 0 0;
      font-size: 1.1rem;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      background: #1c1d25;
    }

    th, td {
      padding: 8px;
      border: 1px solid var(--line-clr);
      text-align: center;
      font-size: 0.9rem;
    }

    th {
      background: #2a2b36;
    }

    .table-container {
      overflow-x: auto;
      margin-bottom: 2rem;
    }

    @media (max-width: 768px) {
      form {
        flex-direction: column;
        align-items: stretch;
      }

      .card-group {
        flex-direction: column;
      }

      th, td {
        padding: 6px;
        font-size: 0.8rem;
      }
    }
  </style>
</head>
<body>
<main>
  <h1>Revenue View</h1>
  <p>Summary of Collected Payments: Memberships and Walk-ins</p>

  <!-- Totals -->
  <div class="card-group">
    <div class="card">
      <h2>Total Revenue</h2>
      <p>₱<?= number_format($totals['total'] ?? 0, 2) ?></p>
    </div>
    <div class="card">
      <h2>Memberships</h2>
      <p>₱<?= number_format($totals['membership_total'] ?? 0, 2) ?></p>
    </div>
    <div class="card">
      <h2>Walk-ins</h2>
      <p>₱<?= number_format($totals['walkin_total'] ?? 0, 2) ?></p>
    </div>
  </div>

  <!-- Date Navigation -->
  <div class="date-navigation" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; padding-bottom: 10px;">
     <a href="?date=<?= date('Y-m-d', strtotime($filter_date . ' -1 day')) ?>" class="nav-arrow nav-arrow-left" style="display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; background: linear-gradient(135deg, #2a2b36 0%, #1c1d25 100%); color: #e0e0e0; border-radius: 16px; text-decoration: none; font-size: 20px; font-weight: bold; transition: all 0.3s ease; border: 2px solid #444; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-left: -2px;">
        <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/>
      </svg>
    </a>
    
     <div class="current-date" id="currentDateDisplay" style="text-align: center; flex: 0 0 auto; padding: 12px 20px; background: linear-gradient(135deg, #2a2b36 0%, #1c1d25 100%); border-radius: 16px; border: 2px solid #444; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3); min-width: 280px; max-width: 320px; cursor: pointer; transition: all 0.3s ease;" onclick="openCalendar()">
      <h3 style="margin: 0; color: var(--accent-clr); font-size: 1.3rem; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.1);">
        <?= date('F j, Y', strtotime($filter_date)) ?>
      </h3>
      <?php 
      $total_transactions = $transactions->num_rows;
      if ($total_transactions > 0): 
      ?>
        <p style="margin: 6px 0 0; color: #4caf50; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 4px;">
          <span style="display: inline-block; width: 6px; height: 6px; background: #4caf50; border-radius: 50%;"></span>
          <?= $total_transactions ?> transaction(s) recorded
        </p>
      <?php endif; ?>
    </div>
    
     <?php 
     $today = date('Y-m-d');
     $next_day = date('Y-m-d', strtotime($filter_date . ' +1 day'));
     $is_future = $next_day > $today;
     ?>
     <?php if ($is_future): ?>
       <div class="nav-arrow nav-arrow-right" style="display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; background: linear-gradient(135deg, #1a1a1a 0%, #0f0f0f 100%); color: #666; border-radius: 16px; font-size: 20px; font-weight: bold; border: 2px solid #333; cursor: not-allowed; opacity: 0.5;">
         <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-right: -2px;">
           <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/>
         </svg>
       </div>
     <?php else: ?>
       <a href="?date=<?= $next_day ?>" class="nav-arrow nav-arrow-right" style="display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; background: linear-gradient(135deg, #2a2b36 0%, #1c1d25 100%); color: #e0e0e0; border-radius: 16px; text-decoration: none; font-size: 20px; font-weight: bold; transition: all 0.3s ease; border: 2px solid #444; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);">
         <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-right: -2px;">
           <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/>
         </svg>
       </a>
     <?php endif; ?>
  </div>

  <!-- Transaction Table -->
  <h2>All Transactions</h2>
  
  <!-- Export Button -->
  <div style="margin-bottom: 1rem; text-align: right;">
    <button onclick="openExportModal()" class="export-btn" style="background: #c83126; color: white; padding: 10px 20px; border: none; border-radius: 6px; font-weight: bold; display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
      <i class='bx bx-download'></i>
      Export Revenue
    </button>
  </div>
  
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Type</th>
          <th>Amount Given</th>
          <th>Name</th>
          <th>Package</th>
          <th>Payment Method</th>
          <th>Receipt/Proof</th>
          <th>Date</th>
          <th>Time</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($transactions->num_rows > 0): ?>
          <?php while ($row = $transactions->fetch_assoc()): ?>
            <?php
            // Set Philippine timezone
            date_default_timezone_set('Asia/Manila');
            $date = new DateTime($row['date']);
            $formatted_date = $date->format('F j, Y');
            $formatted_time = $date->format('g:i A');
            
            // Determine name and package based on transaction type
            $name = $row['type'] === 'walk-in' ? $row['walkin_name'] : ($row['transaction_customer_name'] ?: $row['member_name']);
            $package = $row['type'] === 'walk-in' ? $row['walkin_package'] : $row['member_package'];
            $amount_given = $row['type'] === 'walk-in' ? $row['walkin_amount_given'] : $row['amount'];
            $change = $row['type'] === 'walk-in' ? $row['walkin_change'] : '0.00';
            ?>
            <tr>
              <td><?= ucfirst($row['type']) ?></td>
              <td>₱<?= number_format($amount_given, 2) ?></td>
              <td><?= htmlspecialchars($name ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($package ?? 'N/A') ?></td>
              <td><?= ucfirst($row['payment_method'] ?? 'N/A') ?></td>
              <td>
                <?php 
                  // For walk-in transactions, use walk_in_log receipt_path
                  if ($row['type'] === 'walk-in' && !empty($row['walkin_receipt_path'])) {
                      $paymentMethod = strtolower($row['walkin_payment_method'] ?? $row['payment_method'] ?? '');
                      $receiptPath = $row['walkin_receipt_path'];
                      $gcashRef = $row['walkin_gcash_reference'] ?? '';
                      
                      if ($paymentMethod === 'gcash' && !empty($gcashRef)) {
                          // GCash payment with receipt - show button and reference
                          ?>
                          <div style="display: flex; flex-direction: column; gap: 4px;">
                            <button onclick="viewWalkinReceipt('<?= htmlspecialchars($receiptPath, ENT_QUOTES) ?>', true, '<?= htmlspecialchars($gcashRef, ENT_QUOTES) ?>')" 
                                    style="background: #17a2b8; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                              View Photo
                            </button>
                            <span style="color: #17a2b8; font-size: 0.7rem;">Ref: <?= htmlspecialchars($gcashRef) ?></span>
                          </div>
                          <?php
                      } else {
                          // Cash payment or GCash without reference - show receipt button only
                          ?>
                          <button onclick="viewWalkinReceipt('<?= htmlspecialchars($receiptPath, ENT_QUOTES) ?>', false, '')" 
                                  style="background: #28a745; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                            View Photo
                          </button>
                          <?php
                      }
                  }
                  // For membership transactions, use existing logic
                  elseif ($row['type'] === 'membership') {
                      if ($row['payment_method'] === 'cash' && !empty($row['or_photo_path'])) {
                          ?>
                          <button onclick="viewORPhoto('<?= htmlspecialchars($row['or_photo_path']) ?>')" 
                                  style="background: #28a745; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                            View Photo
                          </button>
                          <?php
                      } elseif ($row['payment_method'] === 'gcash' && !empty($row['gcash_reference_number'])) {
                          // Check or_photo_path for GCash screenshots (saved in same column as cash receipts)
                          if (!empty($row['or_photo_path'])) {
                              ?>
                              <button onclick="viewGCashScreenshot('<?= htmlspecialchars($row['or_photo_path'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['gcash_reference_number'], ENT_QUOTES) ?>')" 
                                      style="background: #28a745; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                                View Photo
                              </button>
                              <?php
                          } else {
                              ?>
                              <span style="color: #6c757d; font-size: 0.8rem;">N/A</span>
                              <?php
                          }
                      } elseif (!empty($row['or_photo_path'])) {
                          // Fallback: if there's a photo path but payment method is unclear, show it
                          ?>
                          <button onclick="viewORPhoto('<?= htmlspecialchars($row['or_photo_path']) ?>')" 
                                  style="background: #28a745; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                            View Photo
                          </button>
                          <?php
                      } else {
                          ?>
                          <span style="color: #6c757d; font-size: 0.8rem;">N/A</span>
                          <?php
                      }
                  } else {
                      ?>
                      <span style="color: #6c757d; font-size: 0.8rem;">N/A</span>
                      <?php
                  }
                  ?>
              </td>
              <td><?= $formatted_date ?></td>
              <td><?= $formatted_time ?></td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="8">No transactions found for selected range.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<!-- Calendar Modal -->
<div id="calendarModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); overflow-y: auto;">
  <div class="modal-content" style="background: #1c1d25; margin: 2% auto; padding: 0; border-radius: 10px; width: 95%; max-width: 400px; min-height: auto; box-shadow: 0 4px 20px rgba(0,0,0,0.5); position: relative;">
    <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid var(--line-clr); position: sticky; top: 0; background: #1c1d25; z-index: 10;">
      <h2 style="margin: 0; color: var(--accent-clr); font-size: 1.2rem;">Select Date</h2>
      <span class="close" onclick="closeCalendar()" style="color: #aaa; font-size: 24px; font-weight: bold; cursor: pointer; padding: 5px; line-height: 1;">&times;</span>
    </div>
    <div style="padding: 20px;">
      <div id="calendar" style="background: #1c1d25; border-radius: 8px; padding: 15px;"></div>
    </div>
  </div>
</div>

<script>
// Calendar functionality
window.openCalendar = function() {
    document.getElementById('calendarModal').style.display = 'block';
    generateCalendar();
};

window.closeCalendar = function() {
    document.getElementById('calendarModal').style.display = 'none';
};

function generateCalendar() {
    const calendar = document.getElementById('calendar');
    const currentDate = new Date('<?= $filter_date ?>');
    // Use Philippine timezone for today's date
    const today = new Date(new Date().toLocaleString("en-US", {timeZone: "Asia/Manila"}));
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    
    const monthNames = ["January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"];
    
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const firstDay = new Date(year, month, 1).getDay();
    
    let html = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <button onclick="changeMonth(-1)" style="background: var(--accent-clr); color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer;">&lt;</button>
            <h3 style="margin: 0; color: var(--accent-clr);">${monthNames[month]} ${year}</h3>
            <button onclick="changeMonth(1)" style="background: var(--accent-clr); color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer;">&gt;</button>
        </div>
        <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; margin-bottom: 10px;">
            <div style="text-align: center; padding: 8px; font-weight: bold; color: #888;">Sun</div>
            <div style="text-align: center; padding: 8px; font-weight: bold; color: #888;">Mon</div>
            <div style="text-align: center; padding: 8px; font-weight: bold; color: #888;">Tue</div>
            <div style="text-align: center; padding: 8px; font-weight: bold; color: #888;">Wed</div>
            <div style="text-align: center; padding: 8px; font-weight: bold; color: #888;">Thu</div>
            <div style="text-align: center; padding: 8px; font-weight: bold; color: #888;">Fri</div>
            <div style="text-align: center; padding: 8px; font-weight: bold; color: #888;">Sat</div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px;">
    `;
    
    // Empty cells for days before the first day of the month
    for (let i = 0; i < firstDay; i++) {
        html += `<div style="padding: 8px;"></div>`;
    }
    
    // Days of the month
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = dateStr === today.toISOString().split('T')[0];
        const isCurrentDate = dateStr === '<?= $filter_date ?>';
        const isFuture = new Date(dateStr) > today;
        
        let dayStyle = `
            padding: 8px; 
            text-align: center; 
            cursor: pointer; 
            border-radius: 6px; 
            transition: all 0.2s ease;
            color: ${isFuture ? '#666' : '#e0e0e0'};
            background: ${isCurrentDate ? 'var(--accent-clr)' : 'transparent'};
            border: ${isToday ? '2px solid #4caf50' : 'none'};
        `;
        
        if (isFuture) {
            dayStyle += 'cursor: not-allowed; opacity: 0.5;';
        }
        
        html += `<div style="${dayStyle}" ${!isFuture ? `onclick="selectDate('${dateStr}')"` : ''}>${day}</div>`;
    }
    
    html += '</div>';
    calendar.innerHTML = html;
}

window.changeMonth = function(direction) {
    const currentDate = new Date('<?= $filter_date ?>');
    currentDate.setMonth(currentDate.getMonth() + direction);
    // Format date in Philippine timezone
    const newDateStr = currentDate.toLocaleDateString('en-CA', {timeZone: 'Asia/Manila'});
    window.location.href = `?date=${newDateStr}`;
};

window.selectDate = function(dateStr) {
    window.location.href = `?date=${dateStr}`;
};

// Export Modal Functions
window.openExportModal = function() {
    document.getElementById('exportModal').style.display = 'block';
    // Small delay to ensure DOM is ready
    setTimeout(function() {
        const filterType = document.getElementById('modal_filter_type').value;
        if (filterType === 'monthly') {
            // Calculate dates based on selected months first
            updateMonthlyDates();
        } else if (filterType === 'yearly') {
            // For yearly, ensure year is set and update link
            updateExportLink();
        } else {
            // For other types, just update the link
            updateExportLink();
        }
    }, 50);
};

window.closeExportModal = function() {
    document.getElementById('exportModal').style.display = 'none';
};

window.handleExportClick = function(event) {
    event.preventDefault();
    // Update export link with current selections
    updateExportLink();
    const exportLink = document.getElementById('modal_export_link');
    if (exportLink && exportLink.href && exportLink.href !== '#') {
        window.location.href = exportLink.href;
    }
};

window.handleYearChange = function() {
    const filterType = document.getElementById('modal_filter_type').value;
    if (filterType === 'monthly') {
        updateMonthlyDates();
    } else if (filterType === 'yearly') {
        // For yearly, recalculate dates based on selected year
        updateExportLink();
    } else {
        updateExportLink();
    }
};

window.updateMonthlyDates = function() {
    const startMonthEl = document.getElementById('modal_start_month');
    const endMonthEl = document.getElementById('modal_end_month');
    const yearEl = document.getElementById('modal_year');
    
    if (!startMonthEl || !endMonthEl || !yearEl) {
        return; // Elements not ready yet
    }
    
    // Force update of export link
    updateExportLink();
};

window.updateExportLink = function() {
    const filterType = document.getElementById('modal_filter_type').value;
    const startMonthEl = document.getElementById('modal_start_month');
    const endMonthEl = document.getElementById('modal_end_month');
    const yearEl = document.getElementById('modal_year');
    const startMonthGroup = document.getElementById('modal_start_month_group');
    const endMonthGroup = document.getElementById('modal_end_month_group');
    const yearGroup = document.getElementById('modal_year_group');
    const exportLink = document.getElementById('modal_export_link');
    
    let startDate = '';
    let endDate = '';
    
    // Show/hide inputs based on filter type and calculate dates
    if (filterType === 'daily') {
        startMonthGroup.style.display = 'none';
        endMonthGroup.style.display = 'none';
        yearGroup.style.display = 'none';
        // Use today's date for daily
        const today = new Date();
        startDate = today.toISOString().split('T')[0];
        endDate = startDate;
    } else if (filterType === 'monthly') {
        startMonthGroup.style.display = 'flex';
        endMonthGroup.style.display = 'flex';
        yearGroup.style.display = 'flex';
        
        // Calculate dates from month/year selections
        if (startMonthEl && endMonthEl && yearEl) {
            const startMonth = startMonthEl.value;
            const endMonth = endMonthEl.value;
            const year = yearEl.value;
            
            if (startMonth && endMonth && year) {
                // Calculate dates from month/year
                startDate = `${year}-${startMonth}-01`;
                const lastDay = new Date(year, parseInt(endMonth), 0).getDate();
                endDate = `${year}-${endMonth}-${String(lastDay).padStart(2, '0')}`;
            }
        }
    } else if (filterType === 'yearly') {
        startMonthGroup.style.display = 'none';
        endMonthGroup.style.display = 'none';
        yearGroup.style.display = 'flex';
        
        // Calculate dates for yearly - ensure we get the full year
        if (yearEl && yearEl.value) {
            const year = yearEl.value;
            startDate = `${year}-01-01`;
            endDate = `${year}-12-31`;
        } else if (yearEl) {
            // If year dropdown exists but no value, use current year as fallback
            const currentYear = new Date().getFullYear();
            startDate = `${currentYear}-01-01`;
            endDate = `${currentYear}-12-31`;
        }
    }
    
    // Update export link
    let link = `../Reports/revenue_report.php?export=pdf&filter_type=${filterType}&user_role=admin`;
    
    if (startDate && endDate) {
        link += `&start_date=${startDate}&end_date=${endDate}`;
    }
    
    if (exportLink) {
        exportLink.href = link;
    }
};

// Close calendar when clicking outside
window.onclick = function(event) {
    const exportModal = document.getElementById('exportModal');
    const calendarModal = document.getElementById('calendarModal');
    const walkinReceiptModal = document.getElementById('walkinReceiptViewModal');
    const gcashScreenshotModal = document.getElementById('gcashScreenshotViewModal');
    if (event.target == exportModal) {
        closeExportModal();
    }
    if (event.target == calendarModal) {
        closeCalendar();
    }
    if (event.target == walkinReceiptModal) {
        closeWalkinReceiptModal();
    }
    if (event.target == gcashScreenshotModal) {
        closeGCashScreenshotModal();
    }
};
</script>

<!-- Export Modal -->
<div id="exportModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
  <div class="modal-content" style="background-color: #1c1d25; margin: 5% auto; padding: 20px; border: 1px solid #444; border-radius: 12px; width: 80%; max-width: 500px; color: white;">
    <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
      <h2 style="margin: 0; color: #4fc3f7;"><i class='bx bx-download'></i> Export Revenue</h2>
      <span class="close" onclick="closeExportModal()" style="color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
    </div>
    
    <div class="modal-body">
      <p style="margin-bottom: 20px; color: #b0b3c1;">Choose your export options:</p>
      
      <div class="form-group" style="margin-bottom: 15px;">
        <label for="modal_filter_type" style="display: block; margin-bottom: 5px; font-weight: 500;">Report Type:</label>
        <select id="modal_filter_type" onchange="updateExportLink()" style="width: 100%; padding: 10px; border: 1px solid #444; border-radius: 6px; background: #2a2b36; color: white;">
          <option value="daily">Daily</option>
          <option value="monthly">Monthly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>
      
      <div class="form-group" id="modal_start_month_group" style="margin-bottom: 15px; display: none;">
        <label for="modal_start_month" style="display: block; margin-bottom: 5px; font-weight: 500;">Start Month:</label>
        <select id="modal_start_month" onchange="updateMonthlyDates()" style="width: 100%; padding: 10px; border: 1px solid #444; border-radius: 6px; background: #2a2b36; color: white;">
          <?php 
          $current_month = date('m');
          $months = [
              '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
              '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
              '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
          ];
          foreach ($months as $value => $name) {
              $selected = ($value == $current_month) ? ' selected' : '';
              echo "<option value='$value'$selected>$name</option>";
          }
          ?>
        </select>
      </div>
      
      <div class="form-group" id="modal_end_month_group" style="margin-bottom: 15px; display: none;">
        <label for="modal_end_month" style="display: block; margin-bottom: 5px; font-weight: 500;">End Month:</label>
        <select id="modal_end_month" onchange="updateMonthlyDates()" style="width: 100%; padding: 10px; border: 1px solid #444; border-radius: 6px; background: #2a2b36; color: white;">
          <?php 
          $current_month = date('m');
          $months = [
              '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
              '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
              '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
          ];
          foreach ($months as $value => $name) {
              $selected = ($value == $current_month) ? ' selected' : '';
              echo "<option value='$value'$selected>$name</option>";
          }
          ?>
        </select>
      </div>
      
      <div class="form-group" id="modal_year_group" style="margin-bottom: 20px; display: none;">
        <label for="modal_year" style="display: block; margin-bottom: 5px; font-weight: 500;">Year:</label>
        <select id="modal_year" onchange="handleYearChange()" style="width: 100%; padding: 10px; border: 1px solid #444; border-radius: 6px; background: #2a2b36; color: white;">
          <?php 
          $current_year = date('Y');
          for ($year = $current_year; $year >= $current_year - 5; $year--) {
              echo "<option value='$year'" . ($year == $current_year ? ' selected' : '') . ">$year</option>";
          }
          ?>
        </select>
      </div>
    </div>
    
    <div class="modal-footer" style="display: flex; gap: 10px; justify-content: flex-end;">
      <button onclick="closeExportModal()" style="padding: 10px 20px; background: #444; color: white; border: none; border-radius: 6px; cursor: pointer;">Cancel</button>
      <a href="#" id="modal_export_link" onclick="handleExportClick(event)" style="padding: 10px 20px; background: #4fc3f7; color: white; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px;">
        <i class='bx bx-download'></i>
        Export to PDF
      </a>
    </div>
  </div>
</div>

<script>
// View OR Photo function
function viewORPhoto(photoPath) {
    const orUrl = '../' + photoPath;
    window.open(orUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
}

// View GCash Screenshot function - displays screenshot and GCash reference number
window.viewGCashScreenshot = function(filename, gcashReferenceNumber) {
    // Validate filename
    if (!filename) {
        alert('Screenshot filename is missing.');
        return;
    }
    
    // Get modal elements
    const modal = document.getElementById('gcashScreenshotViewModal');
    const screenshotImg = document.getElementById('gcashScreenshotImage');
    const refDisplay = document.getElementById('gcashScreenshotGCashRef');
    const refLabel = document.getElementById('gcashScreenshotGCashRefLabel');
    
    if (!modal || !screenshotImg) {
        alert('Screenshot viewer elements not found.');
        return;
    }
    
    // Set screenshot image - use the full path from database (uploads/payments/filename)
    screenshotImg.src = '../' + filename;
    screenshotImg.alt = 'GCash Screenshot';
    
    // Hide GCash reference number display - only show the photo
    if (refDisplay && refLabel) {
        refDisplay.style.display = 'none';
        refLabel.style.display = 'none';
        refDisplay.textContent = '';
    }
    
    // Show modal
    modal.style.display = 'block';
};

// View Walk-in Receipt function - displays receipt image and GCash reference number
window.viewWalkinReceipt = function(receiptPath, isGCashPayment, gcashReferenceNumber) {
    // Validate receipt path
    if (!receiptPath) {
        alert('Receipt path is missing.');
        return;
    }
    
    // Get modal elements
    const modal = document.getElementById('walkinReceiptViewModal');
    const receiptImg = document.getElementById('walkinReceiptImage');
    const refDisplay = document.getElementById('walkinReceiptGCashRef');
    const refLabel = document.getElementById('walkinReceiptGCashRefLabel');
    
    if (!modal || !receiptImg) {
        alert('Receipt viewer elements not found.');
        return;
    }
    
    // Set receipt image
    receiptImg.src = '../' + receiptPath;
    receiptImg.alt = 'Receipt Screenshot';
    
    // Handle GCash reference number display below the photo
    if (refDisplay && refLabel) {
        // Convert isGCashPayment to boolean
        const isGCash = isGCashPayment === true || isGCashPayment === 'true' || String(isGCashPayment).toLowerCase() === 'true';
        
        // Check if we have a valid reference number
        let refValue = '';
        if (gcashReferenceNumber) {
            refValue = String(gcashReferenceNumber).trim();
        }
        
        const hasValidReference = isGCash && refValue !== '' && refValue !== 'undefined' && refValue !== 'null';
        
        if (hasValidReference) {
            // Display GCash reference number below the photo
            refDisplay.textContent = refValue;
            refDisplay.style.display = 'block';
            refLabel.style.display = 'block';
        } else {
            // Hide reference number section
            refDisplay.style.display = 'none';
            refLabel.style.display = 'none';
            refDisplay.textContent = '';
        }
    }
    
    // Show modal
    modal.style.display = 'block';
};

// Close Walk-in Receipt Modal
window.closeWalkinReceiptModal = function() {
    const modal = document.getElementById('walkinReceiptViewModal');
    if (modal) {
        modal.style.display = 'none';
    }
};

// Close GCash Screenshot Modal
window.closeGCashScreenshotModal = function() {
    const modal = document.getElementById('gcashScreenshotViewModal');
    if (modal) {
        modal.style.display = 'none';
    }
};
</script>

<!-- Walk-in Receipt Viewer Modal -->
<div id="walkinReceiptViewModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); overflow-y: auto;">
  <div class="modal-content" style="max-width: 700px; background: #1c1d25; margin: 2% auto; padding: 0; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); position: relative;">
    <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid var(--line-clr); position: sticky; top: 0; background: #1c1d25; z-index: 10;">
      <h2 style="margin: 0; color: var(--accent-clr); font-size: 1.2rem;">Receipt & Payment Details</h2>
      <span class="close" onclick="closeWalkinReceiptModal()" style="color: #aaa; font-size: 24px; font-weight: bold; cursor: pointer; padding: 5px; line-height: 1;">&times;</span>
    </div>
    <div class="modal-body" style="padding: 20px;">
      <!-- Receipt Image -->
      <div class="form-group" style="text-align: center; margin-bottom: 25px;">
        <img id="walkinReceiptImage" src="" alt="Receipt Screenshot" style="max-width: 100%; height: auto; border-radius: 8px; border: 1px solid var(--line-clr); box-shadow: 0 2px 8px rgba(0,0,0,0.3);">
      </div>
      
      <!-- GCash Reference Number Section - Displayed below the photo -->
      <div class="form-group" id="walkinReceiptGCashRefLabel" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--line-clr);">
        <label style="font-weight: 600; color: var(--accent-clr); margin-bottom: 10px; display: block; font-size: 0.95rem;">GCash Reference Number</label>
        <div class="view-field" id="walkinReceiptGCashRef" style="background: linear-gradient(135deg, rgba(94, 99, 255, 0.15) 0%, rgba(74, 82, 232, 0.15) 100%); border: 2px solid #5e63ff; color: #5e63ff; font-weight: 700; font-size: 1.2rem; text-align: center; padding: 12px; letter-spacing: 1px; font-family: 'Courier New', monospace; border-radius: 6px;">
        </div>
      </div>
    </div>
  </div>
</div>

<!-- GCash Screenshot Viewer Modal -->
<div id="gcashScreenshotViewModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); overflow-y: auto;">
  <div class="modal-content" style="max-width: 700px; background: #1c1d25; margin: 2% auto; padding: 0; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); position: relative;">
    <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid var(--line-clr); position: sticky; top: 0; background: #1c1d25; z-index: 10;">
      <h2 style="margin: 0; color: var(--accent-clr); font-size: 1.2rem;">GCash Payment Details</h2>
      <span class="close" onclick="closeGCashScreenshotModal()" style="color: #aaa; font-size: 24px; font-weight: bold; cursor: pointer; padding: 5px; line-height: 1;">&times;</span>
    </div>
    <div class="modal-body" style="padding: 20px;">
      <!-- GCash Screenshot Image -->
      <div class="form-group" style="text-align: center; margin-bottom: 25px;">
        <img id="gcashScreenshotImage" src="" alt="GCash Screenshot" style="max-width: 100%; height: auto; border-radius: 8px; border: 1px solid var(--line-clr); box-shadow: 0 2px 8px rgba(0,0,0,0.3);">
      </div>
      
      <!-- GCash Reference Number Section - Displayed below the photo -->
      <div class="form-group" id="gcashScreenshotGCashRefLabel" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--line-clr);">
        <label style="font-weight: 600; color: var(--accent-clr); margin-bottom: 10px; display: block; font-size: 0.95rem;">GCash Reference Number</label>
        <div class="view-field" id="gcashScreenshotGCashRef" style="background: linear-gradient(135deg, rgba(94, 99, 255, 0.15) 0%, rgba(74, 82, 232, 0.15) 100%); border: 2px solid #5e63ff; color: #5e63ff; font-weight: 700; font-size: 1.2rem; text-align: center; padding: 12px; letter-spacing: 1px; font-family: 'Courier New', monospace; border-radius: 6px;">
        </div>
      </div>
    </div>
  </div>
</div>

</body>
</html>
