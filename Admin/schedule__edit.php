<?php
include '../config.php';

$id = $_GET['id'] ?? null;
if (!$id) die("Shift ID is required.");

// Fetch shift data
$stmt = $conn->prepare("SELECT * FROM roster_shifts WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$shift = $result->fetch_assoc();

if (!$shift) die("Shift not found.");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['shift_date'];
    $start = $_POST['start_time'];
    $end = $_POST['end_time'];

    // Validate time range (7:00 AM to 10:00 PM)
    $min_time = '07:00:00';
    $max_time = '22:00:00';
    
    if ($start < $min_time || $start > $max_time) {
        $error_message = "Start time must be between 7:00 AM and 10:00 PM.";
    } elseif ($end < $min_time || $end > $max_time) {
        $error_message = "End time must be between 7:00 AM and 10:00 PM.";
    } else {
        $update = $conn->prepare("UPDATE roster_shifts SET shift_date=?, start_time=?, end_time=? WHERE id=?");
        $update->bind_param("sssi", $date, $start, $end, $id);
        $result = $update->execute();

        if ($result) {
            header("Location: schedule__viewer.php?date=" . $date);
            exit;
        } else {
            $error_message = "Failed to update shift. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Shift - Admin</title>
  <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
  <?php include 'navbar.php'; ?>
  <main>
    <h1>Edit Roster Shift</h1>
    <p>Update shift details for staff members.</p>

    <div class="form-card">
      <?php if (isset($error_message)): ?>
        <div class="alert error"><?= $error_message ?></div>
      <?php endif; ?>
      <form method="POST" onsubmit="return validateShiftForm();">
        <div class="form-group">
          <label for="shift_date">Shift Date</label>
          <input type="date" name="shift_date" id="shift_date" value="<?php echo htmlspecialchars($shift['shift_date']); ?>" required min="<?= date('Y-m-d'); ?>">
        </div>
        
        <div class="form-group">
          <label for="start_time">Start Time</label>
          <input type="time" name="start_time" id="start_time" value="<?php echo htmlspecialchars($shift['start_time']); ?>" required min="07:00" max="22:00">
          <small style="color: #999; font-size: 0.875rem; display: block; margin-top: 0.25rem;">Time must be between 7:00 AM and 10:00 PM</small>
        </div>
        
        <div class="form-group">
          <label for="end_time">End Time</label>
          <input type="time" name="end_time" id="end_time" value="<?php echo htmlspecialchars($shift['end_time']); ?>" required min="07:00" max="22:00">
          <small style="color: #999; font-size: 0.875rem; display: block; margin-top: 0.25rem;">Time must be between 7:00 AM and 10:00 PM</small>
        </div>
        
        <div class="button-group">
          <button type="submit" class="btn">Update Shift</button>
          <a href="schedule__viewer.php" class="btn btn-secondary" target="_self">Cancel</a>
        </div>
      </form>
    </div>
  </main>

  <style>
    :root {
      --accent: #5e63ff;
      --bg: #0c1118;
      --card-bg: #1c1d25;
      --line: #444;
      --font: 'Poppins', sans-serif;
    }

    body {
      margin: 0;
      font-family: var(--font);
      background: var(--bg);
      color: white;
    }

    main {
      padding: 2rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 100vh;
    }

    .form-card {
      background: var(--card-bg);
      padding: 2rem;
      border-radius: 15px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
      width: 100%;
      max-width: 500px;
      display: flex;
      flex-direction: column;
      gap: 1.2rem;
      border: 1px solid var(--line);
    }

    .form-card h1 {
      text-align: center;
      font-size: 1.8rem;
      color: var(--accent);
      margin-bottom: 0.5rem;
    }

    .form-card p {
      text-align: center;
      color: #ccc;
      margin-bottom: 1.5rem;
    }

    .form-group {
      margin-bottom: 1rem;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      font-weight: 500;
    }

    .form-group input {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: #2a2b36;
      color: white;
      font-size: 1rem;
      outline: none;
      transition: border 0.3s;
      box-sizing: border-box;
    }

    .form-group input:focus {
      border-color: var(--accent);
    }

    .btn {
      background: var(--accent);
      color: white;
      border: none;
      padding: 12px;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.3s;
      width: 100%;
      text-decoration: none;
      text-align: center;
      display: inline-block;
    }

    .btn:hover {
      opacity: 0.9;
    }

    .btn-secondary {
      background: #666;
    }

    .alert {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
    }

    .error {
      background: #c62828;
      color: #ffcdd2;
    }

    .button-group {
      display: flex;
      gap: 1rem;
      width: 100%;
    }

    .button-group .btn {
      flex: 1;
    }

    @media (max-width: 600px) {
      .form-card {
        padding: 1.5rem;
        margin: 1rem;
      }
      .form-card h1 {
        font-size: 1.5rem;
      }
    }
  </style>

  <script>
    function validateShiftForm() {
      const startInput = document.querySelector('input[name="start_time"]');
      const endInput = document.querySelector('input[name="end_time"]');
      const start = startInput.value;
      const end = endInput.value;
      
      // Check if times are within allowed range (7:00 AM to 10:00 PM)
      const minTime = '07:00';
      const maxTime = '22:00';
      
      if (start < minTime || start > maxTime) {
        alert("Start time must be between 7:00 AM and 10:00 PM.");
        startInput.focus();
        return false;
      }
      
      if (end < minTime || end > maxTime) {
        alert("End time must be between 7:00 AM and 10:00 PM.");
        endInput.focus();
        return false;
      }
      
      if (start >= end) {
        alert("End time must be after start time.");
        endInput.focus();
        return false;
      }
      
      return true;
    }
    
    // Add real-time validation on input
    document.addEventListener('DOMContentLoaded', function() {
      const startInput = document.getElementById('start_time');
      const endInput = document.getElementById('end_time');
      
      [startInput, endInput].forEach(input => {
        input.addEventListener('change', function() {
          const time = this.value;
          const minTime = '07:00';
          const maxTime = '22:00';
          
          if (time && (time < minTime || time > maxTime)) {
            this.setCustomValidity('Time must be between 7:00 AM and 10:00 PM');
            this.reportValidity();
          } else {
            this.setCustomValidity('');
          }
        });
      });
    });
  </script>
</body>
</html>
