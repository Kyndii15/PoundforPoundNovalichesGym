<?php
include '../config.php';

include 'navbar.php';

$id = $_GET['id'] ?? null;
$user = ['full_name' => '', 'email' => '', 'role' => ''];

if ($id) {
  $result = mysqli_query($conn, "SELECT * FROM users WHERE id = $id AND role IN ('manager', 'coach')");
  if ($row = mysqli_fetch_assoc($result)) {
    $user = $row;
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $id ? "Edit" : "Add" ?> Staff</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <style>
    :root {
      --bg: #12131a;
      --accent: #5e63ff;
      --card-bg: #1c1d25;
      --line: #3c3d4a;
      --text-clr: #ffffff;
      --secondary-text-clr: #d1d1d1;
      --font: 'Poppins', sans-serif;
    }

    body {
      margin: 0;
      font-family: var(--font);
      background: #0c1118;
      color: var(--text-clr);
    }

    main {
      padding: 2rem;
      color: var(--text-clr);
    }

    h1 {
      font-size: 2rem;
      margin-bottom: 1.5rem;
      color: var(--text-clr);
    }

    .form-card {
      background: var(--card-bg);
      padding: 2rem;
      border-radius: 12px;
      max-width: 1000px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    }

    .form-card label {
      font-weight: 500;
      color: var(--text-clr);
      display: block;
      margin-bottom: 0.3rem;
    }

    .form-card input,
    .form-card select,
    .form-card textarea {
      width: 100%;
      padding: 12px 16px;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: #2a2b36;
      color: var(--text-clr);
      font-size: 1rem;
      box-sizing: border-box;
      transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .form-card input:focus,
    .form-card select:focus,
    .form-card textarea:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(94, 99, 255, 0.1);
    }
    
    .alert {
      padding: 1rem 1.5rem;
      border-radius: 8px;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      font-weight: 500;
    }
    
    .alert-success {
      background-color: rgba(40, 167, 69, 0.1);
      border: 1px solid rgba(40, 167, 69, 0.3);
      color: #28a745;
    }
    
    .alert-error {
      background-color: rgba(220, 53, 69, 0.1);
      border: 1px solid rgba(220, 53, 69, 0.3);
      color: #dc3545;
    }
    
    .alert i {
      font-size: 1.25rem;
    }
    
    .email-validation-error {
      color: #dc3545;
    }
    
    .email-validation-success {
      color: #28a745;
    }
    
    .phone-validation-error {
      color: #dc3545;
    }
    
    .phone-validation-success {
      color: #28a745;
    }

    .form-card select {
      appearance: none;
      background-image: url("data:image/svg+xml;charset=UTF-8,<svg fill='white' height='18' viewBox='0 0 24 24' width='18' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>");
      background-repeat: no-repeat;
      background-position: right 10px center;
      background-size: 16px 16px;
    }

    .form-card button {
      background: var(--accent);
      color: white;
      padding: 12px 24px;
      border: none;
      border-radius: 8px;
      font-weight: 500;
      font-size: 1rem;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .form-card button:hover {
      background: #4a50e6;
      transform: translateY(-1px);
    }

    .back-btn {
      display: inline-block;
      text-align: center;
      margin-top: 0.5rem;
      padding: 12px 24px;
      width: 100%;
      background: #6b7280;
      border: none;
      border-radius: 8px;
      color: white;
      text-decoration: none;
      transition: all 0.3s ease;
      box-sizing: border-box;
      cursor: pointer;
    }

    .back-btn:hover {
      background: #4b5563;
      transform: translateY(-1px);
    }

    .form-card section {
      border-top: 1px solid var(--line);
      padding-top: 1.5rem;
      margin-top: 1.5rem;
    }

    .form-card section:first-of-type {
      border-top: none;
      padding-top: 0;
      margin-top: 0;
    }

    .form-card h2 {
      margin: 0 0 1rem 0;
      font-size: 1.3rem;
      color: var(--accent);
      font-weight: 600;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
      margin-bottom: 1rem;
    }

    .form-row.full-width {
      grid-template-columns: 1fr;
    }

    .form-group {
      display: flex;
      flex-direction: column;
    }

    textarea {
      resize: vertical;
      min-height: 100px;
    }

    .button-row {
      display: flex;
      gap: 1rem;
      margin-top: 2rem;
    }

    .button-row button {
      flex: 1;
    }

    .button-row .back-btn {
      flex: 1;
      margin-top: 0;
    }

    @media (max-width: 768px) {
      .form-card {
        padding: 1.5rem;
        max-width: 100%;
        margin: 0 1rem;
      }
      
      .form-row {
        grid-template-columns: 1fr;
        gap: 1rem;
      }
      
      .button-row {
        flex-direction: column;
      }
    }
  </style>
</head>
<body>
  <main>
    <h1><?= $id ? "Edit Staff" : "New Staff Details" ?></h1>
    
    <?php if (isset($_SESSION['success'])): ?>
      <div class="alert alert-success">
        <i class='bx bx-check-circle'></i>
        <?= htmlspecialchars($_SESSION['success']) ?>
      </div>
      <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
      <div class="alert alert-error">
        <i class='bx bx-error-circle'></i>
        <?= htmlspecialchars($_SESSION['error']) ?>
      </div>
      <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form action="../staff_otp_handler.php" method="POST" class="form-card">
      <input type="hidden" name="id" value="<?= $id ?>">

      <!-- User Info -->
      <section>
        <h2>Staff Details</h2>

        <div class="form-row">
          <div class="form-group">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" value="<?= $id ? htmlspecialchars($user['full_name']) : '' ?>" autocomplete="off" required>
          </div>
          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= $id ? htmlspecialchars($user['email']) : '' ?>" <?= $id ? 'readonly style="background-color: #f5f5f5; color: #666; cursor: not-allowed;"' : 'required autocomplete="off"' ?> onblur="checkEmailAvailability()">
            <div id="email-validation-notice" style="margin-top: 0.5rem; font-size: 0.875rem; display: none;">
              <span id="email-validation-message"></span>
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" value="<?= $id ? htmlspecialchars($user['phone'] ?? '') : '' ?>" <?= $id ? '' : 'required' ?> placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" title="Please enter a valid 11-digit Philippine mobile number starting with 09" maxlength="11" onblur="checkPhoneAvailability()" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
            <div id="phone-validation-notice" style="margin-top: 0.5rem; font-size: 0.875rem; display: none;">
              <span id="phone-validation-message"></span>
            </div>
          </div>
          <div class="form-group">
            <label for="role">Role</label>
            <select name="role" id="role" required>
              <?php
              $roles = ['manager', 'coach'];
              foreach ($roles as $r) {
                $selected = ($user['role'] == $r) ? 'selected' : '';
                echo "<option value='$r' $selected>" . ucfirst($r) . "</option>";
              }
              ?>
            </select>
          </div>
        </div>

        <?php if (!$id): ?>
          <div class="form-row full-width">
            <div class="form-group">
              <label for="password">Password</label>
              <div style="position: relative;">
                <input type="password" id="password" name="password" required autocomplete="new-password" style="width: 100%; padding-right: 2.5rem;">
                <i class='bx bx-hide password-toggle' id="toggle-password-visibility" onclick="togglePassword('password', this)" style="position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%); font-size: 18px; color: white; cursor: pointer; transition: color 0.3s ease;"></i>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </section>

      <div class="button-row">
        <?php if ($id): ?>
          <button type="submit" name="update_staff">Update Staff</button>
        <?php else: ?>
          <button type="submit" name="create_staff_otp">Create Staff</button>
        <?php endif; ?>
        <a href="user__list.php" class="back-btn" target="_self">Back to List</a>
      </div>
    </form>
  </main>

  <script>
    // Password toggle functionality
    function togglePassword(inputId, icon) {
      const input = document.getElementById(inputId);
      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bx-hide');
        icon.classList.add('bx-show');
      } else {
        input.type = 'password';
        icon.classList.remove('bx-show');
        icon.classList.add('bx-hide');
      }
    }

    // Email availability check function
    function checkEmailAvailability() {
      const emailInput = document.getElementById('email');
      const email = emailInput.value.trim();
      const notice = document.getElementById('email-validation-notice');
      const message = document.getElementById('email-validation-message');
      
      // Don't check if email is empty or if we're editing an existing staff
      if (!email || emailInput.readOnly) {
        notice.style.display = 'none';
        return;
      }
      
      // Basic email format validation
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
        notice.style.display = 'block';
        notice.className = 'email-validation-error';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Please enter a valid email address.';
        return;
      }
      
      // Show loading state
      notice.style.display = 'block';
      notice.className = '';
      message.innerHTML = '<i class="bx bx-loader-alt bx-spin" style="margin-right: 0.25rem;"></i>Checking email availability...';
      
      // Make AJAX request to check email availability
      const formData = new FormData();
      formData.append('email', email);
      
      fetch('../check_email_availability.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.available) {
          notice.className = 'email-validation-success';
          message.innerHTML = '<i class="bx bx-check" style="margin-right: 0.25rem;"></i>' + data.message;
        } else {
          notice.className = 'email-validation-error';
          message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>' + data.message;
        }
      })
      .catch(error => {
        notice.className = 'email-validation-error';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Error checking email availability. Please try again.';
        console.error('Error:', error);
      });
    }

    // Phone availability check function
    function checkPhoneAvailability() {
      const phoneInput = document.getElementById('phone');
      const phone = phoneInput.value.trim();
      const notice = document.getElementById('phone-validation-notice');
      const message = document.getElementById('phone-validation-message');
      const userId = document.querySelector('input[name="id"]')?.value || null;
      
      if (!notice || !message) return;
      
      // Don't check if phone is empty
      if (!phone) {
        notice.style.display = 'none';
        notice.className = '';
        message.innerHTML = '';
        return;
      }
      
      // Basic phone format validation (11 digits starting with 09)
      const phoneRegex = /^09[0-9]{9}$/;
      if (!phoneRegex.test(phone)) {
        notice.style.display = 'block';
        notice.className = 'phone-validation-error';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Please enter a valid 11-digit Philippine mobile number starting with 09.';
        return;
      }
      
      // Show loading state
      notice.style.display = 'block';
      notice.className = '';
      message.innerHTML = '<i class="bx bx-loader-alt bx-spin" style="margin-right: 0.25rem;"></i>Checking phone availability...';
      
      // Make AJAX request to check phone availability
      const formData = new FormData();
      formData.append('phone', phone);
      if (userId) {
        formData.append('exclude_member_id', userId);
      }
      
      fetch('../check_phone_availability.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.available) {
          notice.className = 'phone-validation-success';
          message.innerHTML = '<i class="bx bx-check" style="margin-right: 0.25rem;"></i>' + data.message;
        } else {
          notice.className = 'phone-validation-error';
          message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>' + data.message;
        }
      })
      .catch(error => {
        notice.className = 'phone-validation-error';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Error checking phone availability. Please try again.';
        console.error('Error:', error);
      });
    }

    // Handle form submission for staff creation and update
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.querySelector('.form-card form');
      const createStaffBtn = document.querySelector('button[name="create_staff_otp"]');
      const updateStaffBtn = document.querySelector('button[name="update_staff"]');
      
      if (form) {
        form.addEventListener('submit', function(e) {
          const isCreate = createStaffBtn && document.activeElement === createStaffBtn;
          const isUpdate = updateStaffBtn && document.activeElement === updateStaffBtn;
          
          if (isCreate || isUpdate) {
            // Validate form fields
            const fullName = document.getElementById('full_name').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password') ? document.getElementById('password').value.trim() : '';
            const phone = document.getElementById('phone').value.trim();
            const role = document.getElementById('role').value;
            
            if (!fullName || !email || !phone || !role) {
              e.preventDefault();
              alert('Please fill in all required fields.');
              return;
            }
            
            if (isCreate && !password) {
              e.preventDefault();
              alert('Please fill in all required fields.');
              return;
            }
            
            // Check if email validation shows an error
            const emailNotice = document.getElementById('email-validation-notice');
            if (emailNotice && emailNotice.style.display === 'block' && emailNotice.className === 'email-validation-error') {
              e.preventDefault();
              alert('Please fix the email validation error before submitting.');
              return;
            }
            
            // Check if phone validation shows an error
            const phoneNotice = document.getElementById('phone-validation-notice');
            if (phoneNotice && phoneNotice.style.display === 'block' && phoneNotice.className === 'phone-validation-error') {
              e.preventDefault();
              alert('Please fix the phone validation error before submitting.');
              return;
            }
            
            // Validate phone format
            const phoneRegex = /^09[0-9]{9}$/;
            if (!phoneRegex.test(phone)) {
              e.preventDefault();
              alert('Please enter a valid 11-digit phone number starting with 09.');
              return;
            }
            
            // Show loading state
            if (isCreate && createStaffBtn) {
              createStaffBtn.disabled = true;
              createStaffBtn.textContent = 'Sending OTP...';
            } else if (isUpdate && updateStaffBtn) {
              updateStaffBtn.disabled = true;
              updateStaffBtn.textContent = 'Updating...';
            }
            
            // Let the form submit normally - the OTP handler will redirect
          }
        });
      }
    });
  </script>
</body>
</html>
