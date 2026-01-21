<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}
@include '../config.php';

// Check if there's existing member data and handle OTP status
if (isset($_SESSION['temp_member_data']) && isset($_SESSION['member_otp_email'])) {
    $email = $_SESSION['member_otp_email'];
    
    // Check if the existing OTP is still valid
    $otp_check_query = "SELECT * FROM member_otp_verification 
                       WHERE email = ? AND is_used = 0 AND expires_at > NOW() 
                       ORDER BY created_at DESC LIMIT 1";
    
    if ($otp_stmt = mysqli_prepare($conn, $otp_check_query)) {
        mysqli_stmt_bind_param($otp_stmt, 's', $email);
        mysqli_stmt_execute($otp_stmt);
        $otp_result = mysqli_stmt_get_result($otp_stmt);
        
        if (mysqli_num_rows($otp_result) == 0) {
            // OTP has expired - show resend option
            $otp_expired = true;
            $remaining_seconds = 0;
        } else {
            // OTP is still valid - calculate remaining time
            $otp_expired = false;
            $otp_row = mysqli_fetch_assoc($otp_result);
            $expires_at = strtotime($otp_row['expires_at']);
            $current_time = time();
            $remaining_seconds = max(0, $expires_at - $current_time);
        }
        mysqli_stmt_close($otp_stmt);
    }
} else {
    // No active OTP session
    $otp_expired = true;
    $remaining_seconds = 0;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <link rel="stylesheet" href="../css/styles__access__form.css" />
  <style>
    /* OTP Form Styling */
.otp-form {
    width: 100%;
    max-width: 500px;
    margin: 2.8rem auto;
    padding: 2rem;
    background: white;
    border-radius: 12px;
    box-sizing: border-box;
}

.otp-timer {
    text-align: center;
    margin: 0 0 1.5rem 0;
    padding: 12px;
    background: rgba(12, 12, 98, 0.1);
    border-radius: 8px;
    font-size: 15px;
    color: #0c0c62;
    font-weight: 500;
}

.otp-input {
    width: 100%;
    text-align: center;
    letter-spacing: 12px;
    font-size: 28px;
    height: 60px;
    padding: 0 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    margin: 10px 0;
    transition: all 0.3s ease;
}

.otp-input:focus {
    border-color: #0c0c62;
    outline: none;
    box-shadow: 0 0 0 3px rgba(12, 12, 98, 0.15);
}

.input-box {
    margin-bottom: 1.5rem;
}

.input-box label {
    display: block;
    margin-bottom: 8px;
    color: #333;
    font-weight: 500;
}

.btn-submit, .btn-resend {
    width: 100%;
    padding: 14px;
    margin: 8px 0;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-submit {
    background: #0c0c62;
    color: white;
}

.btn-submit:hover {
    background: #0a0a52;
    transform: translateY(-2px);
}

.btn-resend {
    background: #f5f5f5;
    color: #0c0c62;
    border: 1px solid #e0e0e0;
}

.btn-resend:hover {
    background: #eeeeee;
}

.switch-form {
    text-align: center;
    margin-top: 1.5rem;
    font-size: 15px;
    color: #666;
}

.switch-form a {
    color: #0c0c62;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.switch-form a:hover {
    text-decoration: underline;
}

.form-header {
    margin-bottom: 2rem;
    text-align: center;
}

.title-register {
    font-size: 24px !important;
    font-weight: 600 !important;
    color: white !important;
    margin: 0 !important;
    line-height: 5.3 !important;
    position: relative;
    z-index: 2;
}

.subtitle {
    color: #666;
    font-size: 15px;
    margin: 0 0 1.5rem 0;
    line-height: 1.5;
}

.verification-message {
    color: #555;
    font-size: 15px;
    line-height: 1.5;
    margin: 1rem 0 0 0;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
    text-align: center;
}

/* Responsive adjustments */
@media (max-width: 600px) {
    .otp-form {
        padding: 1.5rem;
        margin: 1rem;
    }
    
    .otp-input {
        font-size: 24px;
        height: 55px;
    }
    
    .title-register {
        font-size: 24px;
    }
}
  </style>
  <title>Member OTP Verification - Pound for Pound</title>
  <script>
    // Start countdown when the page loads
    document.addEventListener('DOMContentLoaded', function() {
      let timeLeft = <?php echo isset($remaining_seconds) ? $remaining_seconds : 300; ?>; // Use actual remaining time or default to 5 minutes
      const countdownElement = document.getElementById('countdown');
      const resendButton = document.getElementById('resend-btn');
      
      // Check if OTP is already expired (from PHP)
      <?php if (isset($otp_expired) && $otp_expired): ?>
        // OTP is already expired - show resend button immediately
        if (countdownElement) {
          countdownElement.textContent = '00:00';
        }
        if (resendButton) {
          resendButton.disabled = false;
          resendButton.style.opacity = '1';
          resendButton.style.cursor = 'pointer';
          resendButton.textContent = 'Request New Code';
        }
      <?php else: ?>
        // OTP is still valid - start countdown
        function updateCountdown() {
          const minutes = Math.floor(timeLeft / 60);
          let seconds = timeLeft % 60;
          
          // Add leading zero if seconds is less than 10
          seconds = seconds < 10 ? '0' + seconds : seconds;
          
          if (countdownElement) {
            countdownElement.textContent = `${minutes}:${seconds}`;
          }
          
          if (timeLeft <= 0) {
            clearInterval(window.otpTimer);
            if (countdownElement) {
              countdownElement.textContent = '00:00';
            }
            if (resendButton) {
              resendButton.disabled = false;
              resendButton.style.opacity = '1';
              resendButton.style.cursor = 'pointer';
              resendButton.textContent = 'Request New Code';
            }
          } else {
            timeLeft--;
          }
        }
        
        // Initial call to display the first second immediately
        updateCountdown();
        
        // Update the countdown every second
        window.otpTimer = setInterval(updateCountdown, 1000);
      <?php endif; ?>
      
      // Handle resend button click
      if (resendButton) {
        resendButton.addEventListener('click', function(e) {
          e.preventDefault();
          
          // Make AJAX call to resend OTP
          fetch('member_otp_handler.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'resend_member_otp=1'
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              // Reset the timer to 5 minutes (300 seconds)
              timeLeft = 300;
              
              // Clear any existing timer
              if (window.otpTimer) {
                clearInterval(window.otpTimer);
              }
              
              // Reset button state
              resendButton.disabled = true;
              resendButton.style.opacity = '0.7';
              resendButton.style.cursor = 'not-allowed';
              resendButton.textContent = 'Resend Code';
              
              // Show success message
              showToast('New verification code sent! Timer reset to 5:00', 'success');
              
              // Restart countdown immediately
              <?php if (!isset($otp_expired) || !$otp_expired): ?>
                // Start the countdown immediately
                updateCountdown();
                window.otpTimer = setInterval(updateCountdown, 1000);
              <?php else: ?>
                // If OTP was expired, start fresh countdown
                updateCountdown();
                window.otpTimer = setInterval(updateCountdown, 1000);
              <?php endif; ?>
            } else {
              showToast(data.message || 'Failed to resend code. Please try again.', 'error');
            }
          })
          .catch(error => {
            showToast('Failed to resend code. Please try again.', 'error');
          });
        });
      }
    });
  </script>
</head>
<body>
<div class="wrapper">

  <!-- Messages -->
  <div id="toast-container"></div>

<?php if (isset($error)) echo "<script>window.onload = () => showToast('{$error}', 'error');</script>"; ?>
<?php if (isset($success)) echo "<script>window.onload = () => showToast('{$success}', 'success');</script>"; ?>

  <div class="form-header">
    <div class="titles">
      <div class="title-register">Member Verification</div>
    </div>
  </div>


  <?php 
  // Store error message before clearing session
  $error_message = $_SESSION['error'] ?? '';
  // Debug: Show what error message we have
  if (!empty($error_message)) {
    echo "<!-- DEBUG: Error message: " . htmlspecialchars($error_message) . " -->";
  }
  if (isset($_SESSION['error'])): ?>
    <div class="error-message" style="text-align: center; padding: 1rem; background: rgba(220, 53, 69, 0.1); border: 1px solid rgba(220, 53, 69, 0.3); border-radius: 8px; color: #dc3545; margin: 1rem 0; max-width: 400px; margin-left: auto; margin-right: auto;">
      <i class='bx bx-error-circle' style="font-size: 1.5rem; margin-bottom: 0.5rem;"></i>
      <p style="margin: 0; font-weight: 500;"><?= htmlspecialchars($_SESSION['error']) ?></p>
    </div>
    <?php unset($_SESSION['error']); ?>
  <?php endif; ?>

  <?php if (isset($otp_expired) && $otp_expired && !isset($_SESSION['member_otp_email'])): ?>
    <!-- OTP Expired Message -->
    <div class="otp-form">
      <div style="text-align: center; padding: 2rem; background: rgba(220, 53, 69, 0.1); border: 1px solid rgba(220, 53, 69, 0.3); border-radius: 8px; color: #dc3545;">
        <h3>⏰ Session Expired</h3>
        <p>Your verification session has expired. Please create the member account again.</p>
        <div style="margin-top: 1rem;">
          <a href="member__form.php" style="background: #0c0c62; color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: 500;">Create Member Again</a>
        </div>
      </div>
    </div>
  <?php else: ?>
    <!-- OTP Verification Form -->
    <form method="POST" action="member_otp_handler.php" class="otp-form" autocomplete="off">
      <!-- OTP Timer - compact -->
      <div class="otp-timer" id="otp-timer">
        Code expires in: <span id="countdown">05:00</span>
      </div>

      <!-- Incorrect Code Notice -->
      <?php 
      // Debug: Check error message for incorrect code
      if (!empty($error_message)) {
        echo "<!-- DEBUG: Checking error message for 'Invalid or expired verification code': " . (strpos($error_message, 'Invalid or expired verification code') !== false ? 'FOUND' : 'NOT FOUND') . " -->";
      }
      if (!empty($error_message) && strpos($error_message, 'Invalid or expired verification code') !== false): ?>
        <div class="incorrect-code-notice" style="text-align: center; color: #dc3545; margin: 0.25rem 0; font-weight: 500;">
          Incorrect verification code. Please try again.
        </div>
      <?php endif; ?>

      <div class="input-box">
        <input type="text" class="input-field otp-input" id="otp_code" name="otp_code" maxlength="6" required autocomplete="off" />
        <label for="otp_code" class="label">Verification Code</label>
        <i class='bx bx-shield-check icon'></i>
      </div>

      <div class="form-cols">
        <div class="col-1"></div>
        <div class="col-2"></div>
      </div>

      <div class="input-box">
        <button class="btn-submit" name="verify_member_otp" type="submit">Verify</button>
        <button type="button" class="btn-resend" id="resend-btn" disabled style="opacity: 0.7; cursor: not-allowed;">
        <i class='bx bx-revision'></i> Resend Code
      </button>
      </div>

      <div class="switch-form">
        <span>Wrong details input? <a href="member__form.php">Go Back</a></span>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.classList.add('toast', type);

    const messageSpan = document.createElement('span');
    messageSpan.textContent = message;

    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '&times;';
    closeBtn.classList.add('close-btn');
    closeBtn.onclick = () => {
        container.removeChild(toast);
    };

    toast.appendChild(messageSpan);
    toast.appendChild(closeBtn);
    container.appendChild(toast);

    setTimeout(() => {
        if (container.contains(toast)) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-100%)';
            setTimeout(() => {
                if (container.contains(toast)) container.removeChild(toast);
            }, 300);
        }
    }, 4000);
}

// Initialize form positioning - match signin form
document.addEventListener('DOMContentLoaded', function() {
    // Ensure OTP form is visible and properly positioned
    const otpForm = document.querySelector(".otp-form");
    
    if (otpForm) {
        otpForm.style.display = "block";
        otpForm.style.position = "relative";
        otpForm.style.left = "50%";
        otpForm.style.transform = "translateX(-50%)";
        otpForm.style.width = "85%";
        otpForm.style.opacity = "1";
    }
    
    // Auto-focus on OTP input
    const otpInput = document.getElementById('otp_code');
    if (otpInput) {
        otpInput.focus();
    }
    
    // Handle OTP form submission - let PHP handle the redirect
    const otpForm = document.querySelector('.otp-form form');
    if (otpForm) {
        otpForm.addEventListener('submit', function(e) {
            // Don't prevent default - let the form submit normally
            // The PHP handler will redirect to the payment page
        });
    }
});

// Auto-format OTP input (numbers only)
document.addEventListener('DOMContentLoaded', function() {
    const otpInput = document.getElementById('otp_code');
    if (otpInput) {
        otpInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }
});

</script>
</body>
</html>