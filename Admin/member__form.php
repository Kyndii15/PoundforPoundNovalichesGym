<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}

// Clear any leftover success/error messages if accessing page directly (not from redirect)
if (!isset($_SERVER['HTTP_REFERER']) || strpos($_SERVER['HTTP_REFERER'], 'member_otp_handler.php') === false) {
    if (isset($_SESSION['success']) && $_SESSION['success'] !== 'Member account added!') {
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error']) && $_SESSION['error'] !== 'Member Creation Failed') {
        unset($_SESSION['error']);
    }
}

include '../config.php';
include '../includes/membership_plans_helper.php';

$id = $_GET['id'] ?? null;
$member = ['full_name' => '', 'email' => '', 'phone' => '', 'address' => '', 'membership_plan' => ''];

if ($id) {
  // Priority: 1) Active subscription with future expiry, 2) Most recent subscription
  $result = mysqli_query($conn, "SELECT 
                                    u.*, 
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
                                    ) as membership_plan 
                                 FROM users u 
                                 LEFT JOIN members m ON u.id = m.user_id 
                                 WHERE u.id = $id AND u.role = 'customer'");
  if ($row = mysqli_fetch_assoc($result)) {
    $member = $row;
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $id ? "Edit" : "Add" ?> Member</title>
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
      background: var(--bg);
      color: var(--text-clr);
    }

    main {
      padding: 1rem;
      color: var(--text-clr);
    }

    h1 {
      font-size: 1.6rem;
      margin-bottom: 0.75rem;
      color: var(--text-clr);
    }

    .form-card {
      background: var(--card-bg);
      padding: 1rem;
      border-radius: 12px;
      max-width: 1000px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    }

    .form-card label {
      font-weight: 500;
      color: var(--text-clr);
      display: block;
      margin-bottom: 0.3rem;
    }

    .form-card input,
    .form-card select {
      width: 100%;
      padding: 10px 14px;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: #2a2b36;
      color: var(--text-clr);
      font-size: 0.95rem;
      box-sizing: border-box;
      transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .form-card input.valid-input {
      border-color: #28a745;
      box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2);
    }

    .form-card input:focus,
    .form-card select:focus {
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
    
    .fullname-validation-error {
      color: #dc3545;
    }
    
    .fullname-validation-success {
      color: #28a745;
    }

    .form-card select {
      appearance: none;
      background-image: url("data:image/svg+xml;charset=UTF-8,<svg fill='white' height='18' viewBox='0 0 24 24' width='18' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/></svg>");
      background-repeat: no-repeat;
      background-position: right 10px center;
      background-size: 16px 16px;
    }

    .form-card section {
      border-top: none;
      padding-top: 0;
      margin-top: 0;
    }

    .form-card section:first-of-type {
      border-top: none;
      padding-top: 0;
      margin-top: 0;
    }

    .form-card h2 {
      margin: 0 0 0.5rem 0;
      font-size: 1.1rem;
      color: var(--accent);
      font-weight: 600;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.75rem;
      margin-bottom: 0.5rem;
    }

    .form-row.full-width {
      grid-template-columns: 1fr;
    }

    .form-group {
      display: flex;
      flex-direction: column;
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

    .form-card button:hover:not(:disabled) {
      background: #4a50e6;
      transform: translateY(-1px);
    }
    
    .form-card button:disabled {
      background: #4b5563;
      cursor: not-allowed;
      opacity: 0.6;
    }
    
    .form-card button:disabled:hover {
      transform: none;
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

    .button-row {
      display: flex;
      gap: 1rem;
      margin-top: 1rem;
    }

    .button-row button {
      flex: 1;
    }

    .button-row .back-btn {
      flex: 1;
      margin-top: 0;
    }

    .membership-plan-info {
      background: rgba(94, 99, 255, 0.1);
      border: 1px solid rgba(94, 99, 255, 0.3);
      border-radius: 8px;
      padding: 1rem;
      margin-top: 0.5rem;
    }

    .plan-price {
      font-size: 1.2rem;
      font-weight: 600;
      color: var(--accent);
      margin-bottom: 0.5rem;
    }

    .plan-description {
      color: var(--secondary-text-clr);
      font-size: 0.9rem;
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
  <?php include 'navbar.php'; ?>

  <main>
    <h1><?= $id ? "Edit Member" : "New Member Details" ?></h1>
    
    <?php if (isset($_SESSION['success']) && !empty(trim($_SESSION['success'])) && $_SESSION['success'] === 'Member account added!'): ?>
      <div class="alert alert-success">
        <i class='bx bx-check-circle'></i>
        <?= htmlspecialchars($_SESSION['success']) ?>
      </div>
      <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error']) && !empty(trim($_SESSION['error']))): ?>
      <div class="alert alert-error">
        <i class='bx bx-error-circle'></i>
        <?= htmlspecialchars($_SESSION['error']) ?>
      </div>
      <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form action="member_otp_handler.php" method="POST" class="form-card">
      <input type="hidden" name="id" value="<?= $id ?>">

      <!-- Member Info -->
      <section>
        <h2>Member Details</h2>

        <div class="form-row">
          <div class="form-group">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" value="<?= $id ? htmlspecialchars($member['full_name']) : '' ?>" autocomplete="off" required onblur="validateFullName()">
            <div id="fullname-validation-notice" style="margin-top: 0.5rem; font-size: 0.875rem; display: none;">
              <span id="fullname-validation-message"></span>
            </div>
          </div>
          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= $id ? htmlspecialchars($member['email']) : '' ?>" <?= $id ? 'readonly style="background-color: #f5f5f5; color: #666; cursor: not-allowed;"' : 'required autocomplete="off"' ?> onblur="checkEmailAvailability()">
            <div id="email-validation-notice" style="margin-top: 0.5rem; font-size: 0.875rem; display: none;">
              <span id="email-validation-message"></span>
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" value="<?= $id ? htmlspecialchars($member['phone'] ?? '') : '' ?>" <?= $id ? '' : 'required' ?> placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" title="Please enter a valid 11-digit Philippine mobile number starting with 09" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" onblur="checkPhoneAvailability()">
            <div id="phone-validation-notice" style="margin-top: 0.5rem; font-size: 0.875rem; display: none;">
              <span id="phone-validation-message"></span>
            </div>
          </div>
          <div class="form-group">
            <label for="address">Address</label>
            <input type="text" id="address" name="address" value="<?= $id ? htmlspecialchars($member['address'] ?? '') : '' ?>" placeholder="Optional">
          </div>
        </div>

        <?php if (!$id): ?>
          <div class="form-row full-width">
            <div class="form-group">
              <label for="password">Password</label>
              <div style="position: relative;">
                <input type="password" id="password" name="password" required autocomplete="new-password" style="width: 100%; padding-right: 2.5rem;" oninput="validatePassword(this.value)">
                <i class='bx bx-hide password-toggle' id="toggle-password-visibility" onclick="togglePassword('password', this)" style="position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%); font-size: 18px; color: white; cursor: pointer; transition: color 0.3s ease;"></i>
              </div>
              <div id="password-notice" style="margin-top: 0.5rem; font-size: 0.875rem; color: #dc3545; display: none;">
                <i class='bx bx-info-circle' style="margin-right: 0.25rem;"></i>
                <span id="password-validation-message">Password must be at least 6 characters long, contain at least 1 uppercase letter, and at least 1 number</span>
              </div>
              <div style="margin-top: 0.5rem; font-size: 0.875rem; color: var(--secondary-text-clr);">
                password must be at least 6 characters long, has 1 capital letter and at least 1 number
              </div>
            </div>
          </div>
        <?php endif; ?>
      </section>

      <?php if (!$id): ?>
      <!-- Membership Plan -->
      <section>
        <h2>Membership Plan</h2>
        <div class="form-row full-width">
          <div class="form-group">
            <label for="membership_plan">Select Membership Plan</label>
            <select id="membership_plan" name="membership_plan" required>
              <option value="">Select a membership plan</option>
              <?php 
              // Fetch plans dynamically from database
              $plans = getAllMembershipPlans($conn);
              foreach ($plans as $plan): 
              ?>
              <option value="<?= htmlspecialchars($plan['plan_name']) ?>" data-price="<?= $plan['price'] ?>" data-duration="<?= $plan['duration_months'] ?>">
                <?= htmlspecialchars($plan['plan_name']) ?> - ₱<?= number_format($plan['price'], 2) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div id="planInfo" class="membership-plan-info" style="display: none;">
              <div class="plan-price" id="planPrice"></div>
              <div id="planDescription"></div>
            </div>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <div class="button-row">
        <?php if ($id): ?>
          <button type="submit" name="update_member">Update Member</button>
        <?php else: ?>
          <button type="submit" name="create_member_otp" id="create-member-btn">Create Member</button>
        <?php endif; ?>
        <a href="members__management.php" class="back-btn" target="_self">Back to List</a>
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

    // Password validation function - shows error message if invalid
    function validatePassword(password) {
      const notice = document.getElementById('password-notice');
      const message = document.getElementById('password-validation-message');
      
      if (!notice || !message) return;
      
      if (password.length === 0) {
        notice.style.display = 'none';
        return;
      }
      
      const errors = [];
      
      // Check minimum length
      if (password.length < 6) {
        errors.push('at least 6 characters long');
      }
      
      // Check for uppercase letter
      if (!/[A-Z]/.test(password)) {
        errors.push('at least 1 uppercase letter');
      }
      
      // Check for number
      if (!/[0-9]/.test(password)) {
        errors.push('at least 1 number');
      }
      
      if (errors.length > 0) {
        notice.style.display = 'block';
        message.textContent = 'Password must be ' + errors.join(', ') + '.';
      } else {
        notice.style.display = 'none';
      }
    }

    // Membership plan selection handler
    document.addEventListener('DOMContentLoaded', function() {
      const membershipPlanSelect = document.getElementById('membership_plan');
      if (membershipPlanSelect) {
        membershipPlanSelect.addEventListener('change', function() {
          const selectedOption = this.options[this.selectedIndex];
          const planInfo = document.getElementById('planInfo');
          const planPrice = document.getElementById('planPrice');
          const planDescription = document.getElementById('planDescription');
          
          if (selectedOption.value) {
            const price = selectedOption.getAttribute('data-price');
            if (planPrice) planPrice.textContent = `Price: ₱${parseInt(price).toLocaleString()}`;
            if (planDescription) planDescription.textContent = `Plan: ${selectedOption.textContent}`;
            if (planInfo) planInfo.style.display = 'block';
          } else {
            if (planInfo) planInfo.style.display = 'none';
          }
        });
      }
    });

    // Phone availability check function
    function checkPhoneAvailability() {
      const phoneInput = document.getElementById('phone');
      const phone = phoneInput.value.trim();
      const notice = document.getElementById('phone-validation-notice');
      const message = document.getElementById('phone-validation-message');
      const memberId = document.querySelector('input[name="id"]')?.value || null;
      
      if (!notice || !message) return;
      
      phoneInput.classList.remove('valid-input');
      
      // Don't check if phone is empty
      if (!phone) {
        notice.style.display = 'none';
        notice.className = '';
        message.innerHTML = '';
        return;
      }
      
      // Basic phone format validation
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
      if (memberId) {
        formData.append('exclude_member_id', memberId);
      }
      
      fetch('../check_phone_availability.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.available) {
          notice.style.display = 'none';
          notice.className = '';
          message.innerHTML = '';
          phoneInput.classList.add('valid-input');
        } else {
          notice.style.display = 'block';
          notice.className = 'phone-validation-error';
          message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>' + data.message;
          phoneInput.classList.remove('valid-input');
        }
      })
      .catch(error => {
        notice.style.display = 'block';
        notice.className = 'phone-validation-error';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Error checking phone availability. Please try again.';
        console.error('Error:', error);
        phoneInput.classList.remove('valid-input');
      });
    }

    // Full name format validation function
    function validateFullName() {
      const fullNameInput = document.getElementById('full_name');
      const fullName = fullNameInput.value.trim();
      const notice = document.getElementById('fullname-validation-notice');
      const message = document.getElementById('fullname-validation-message');
      
      if (!notice || !message) return;
      
      // Don't check if full name is empty (required attribute will handle this)
      if (!fullName) {
        notice.style.display = 'none';
        return;
      }
      
      // Check if full name has at least two words (separated by space)
      const words = fullName.split(/\s+/).filter(word => word.length > 0);
      
      if (words.length < 2) {
        notice.style.display = 'block';
        notice.className = 'fullname-validation-error';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Full name must contain at least two words (e.g., "First Last").';
      } else {
        notice.style.display = 'block';
        notice.className = 'fullname-validation-success';
        message.innerHTML = '<i class="bx bx-check" style="margin-right: 0.25rem;"></i>Full name format is valid.';
      }
    }

    // Email availability check function
    function checkEmailAvailability() {
      const emailInput = document.getElementById('email');
      const email = emailInput.value.trim();
      const notice = document.getElementById('email-validation-notice');
      const message = document.getElementById('email-validation-message');
      
      if (!notice || !message) return;
      
      emailInput.classList.remove('valid-input');
      
      // Don't check if email is empty or if we're editing an existing member
      if (!email || emailInput.readOnly) {
        notice.style.display = 'none';
        notice.className = '';
        message.innerHTML = '';
        return;
      }
      
      // Basic email format validation (client-side quick check)
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
        notice.style.display = 'block';
        notice.className = 'email-validation-error';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Please enter a valid email address format.';
        return;
      }
      
      // Show loading state
      notice.style.display = 'block';
      notice.className = '';
      message.innerHTML = '<i class="bx bx-loader-alt bx-spin" style="margin-right: 0.25rem;"></i>Checking email format and availability...';
      
      // Make AJAX request to check email availability, format, and domain existence
      const formData = new FormData();
      formData.append('email', email);
      
      fetch('../check_email_availability.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        return response.json();
      })
      .then(data => {
        if (data.available) {
          notice.style.display = 'none';
          notice.className = '';
          message.innerHTML = '';
          emailInput.classList.add('valid-input');
        } else {
          notice.style.display = 'block';
          notice.className = 'email-validation-error';
          if (!data.valid_format) {
            message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Invalid email format. Please enter a valid email address.';
          } else if (!data.domain_exists) {
            message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Email domain does not exist. Please enter a valid email address.';
          } else {
            message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>' + data.message;
          }
          emailInput.classList.remove('valid-input');
        }
      })
      .catch(error => {
        notice.style.display = 'block';
        notice.className = 'email-validation-error';
        message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Error checking email availability. Please try again.';
        console.error('Error:', error);
        emailInput.classList.remove('valid-input');
      });
    }
    
    // Form submission validation
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.querySelector('.form-card form');
      
      if (form) {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          
          const fullName = document.getElementById('full_name').value.trim();
          const emailInput = document.getElementById('email');
          const email = emailInput.value.trim();
          const passwordInput = document.getElementById('password');
          const password = passwordInput ? passwordInput.value.trim() : '';
          const phoneInput = document.getElementById('phone');
          const phone = phoneInput.value.trim();
          const membershipPlanSelect = document.getElementById('membership_plan');
          const membershipPlan = membershipPlanSelect ? membershipPlanSelect.value.trim() : '';
          const memberId = document.querySelector('input[name="id"]')?.value || null;
          const isEditMode = !!memberId;
          
          const errors = [];
          
          // Check required fields
          if (!fullName) {
            errors.push('Full Name is required');
          } else {
            // Check full name format (at least two words)
            const nameWords = fullName.split(/\s+/).filter(word => word.length > 0);
            if (nameWords.length < 2) {
              errors.push('Full name must contain at least two words (e.g., "First Last")');
            }
          }
          if (!email) errors.push('Email is required');
          if (!isEditMode && !password) errors.push('Password is required');
          if (!phone) errors.push('Phone Number is required');
          if (!isEditMode && !membershipPlan) errors.push('Membership Plan is required');
          
          // Validate email format if not in edit mode
          if (!isEditMode && email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
              errors.push('Email format is invalid');
            } else {
              // Check if email validation shows error
              const emailNotice = document.getElementById('email-validation-notice');
              if (emailNotice && emailNotice.style.display !== 'none') {
                if (emailNotice.className.includes('email-validation-error')) {
                  errors.push('Email is already taken or invalid');
                }
              }
            }
          }
          
          // Validate phone format
          if (phone) {
            const phoneRegex = /^09[0-9]{9}$/;
            if (!phoneRegex.test(phone)) {
              errors.push('Phone number format is invalid (must be 11 digits starting with 09)');
            } else {
              // Check if phone validation shows error
              const phoneNotice = document.getElementById('phone-validation-notice');
              if (phoneNotice && phoneNotice.style.display !== 'none') {
                if (phoneNotice.className.includes('phone-validation-error')) {
                  errors.push('Phone number is already taken');
                }
              }
            }
          }
          
          // Validate password if creating new member
          if (!isEditMode && password) {
            const passwordErrors = [];
            if (password.length < 6) passwordErrors.push('at least 6 characters long');
            if (!/[A-Z]/.test(password)) passwordErrors.push('at least 1 uppercase letter');
            if (!/[0-9]/.test(password)) passwordErrors.push('at least 1 number');
            
            if (passwordErrors.length > 0) {
              const passwordNotice = document.getElementById('password-notice');
              const passwordMessage = document.getElementById('password-validation-message');
              if (passwordNotice && passwordMessage) {
                passwordNotice.style.display = 'block';
                passwordMessage.textContent = 'Password must be ' + passwordErrors.join(', ') + '.';
              }
              errors.push('Password does not meet requirements');
            }
          }
          
          // If there are validation errors, prevent submission
          if (errors.length > 0) {
            alert('Please fix the following errors before submitting:\n\n' + errors.join('\n'));
            return false;
          }
          
          // All validations passed, submit the form
          form.submit();
        });
      }
    });
  </script>
</body>
</html>
