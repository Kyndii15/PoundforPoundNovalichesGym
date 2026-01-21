<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}
@include '../config.php';

// Check if there's existing walk-in conversion data and handle OTP status
if (isset($_SESSION['temp_walkin_data']) && isset($_SESSION['walkin_otp_email'])) {
    $email = $_SESSION['walkin_otp_email'];
    
    // Check if the existing OTP is still valid
    $otp_check_query = "SELECT * FROM walkin_otp_verification 
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
    // No active OTP session - redirect back to walk-in log
    header('Location: walkin__log.php');
    exit();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <style>
    /* Import Poppins font */
    @import url("https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap");

    :root {
        --primary-color: #0c0c62;
        --secondary-color: #535354;
        --background-color: linear-gradient(to right, #090979, #000000, #090979);
        --shadow-color: rgba(0, 0, 0, 0.1);
        --white-color: #FFF;
        --black-color: #000;
        --input-border-color: #E3E4E6;
        --success-color: #4BB543;
        --error-color: #ff4d4f;
        --transition-3s: 0.3s;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: "Poppins", sans-serif;
    }

    body {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        background: var(--background-color);
        padding: 20px;
    }

    .wrapper {
        position: relative;
        width: 100%;
        max-width: 450px;
        background-color: var(--white-color);
        border-radius: 20px;
        border: 1px solid var(--primary-color);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        overflow: hidden;
    }

    .form-header {
        background: linear-gradient(135deg, var(--primary-color), #1a1a7a);
        padding: 30px 20px;
        text-align: center;
        position: relative;
    }

    .form-header::before {
        content: '';
        position: absolute;
        bottom: -10px;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 0;
        border-left: 15px solid transparent;
        border-right: 15px solid transparent;
        border-top: 15px solid var(--primary-color);
    }

    .title-register {
        color: var(--white-color);
        font-size: 28px;
        font-weight: 600;
        margin: 0;
    }

    .otp-form {
        padding: 40px 30px 30px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .otp-timer {
        text-align: center;
        padding: 15px;
        background: linear-gradient(135deg, rgba(12, 12, 98, 0.1), rgba(12, 12, 98, 0.05));
        border: 1px solid rgba(12, 12, 98, 0.2);
        border-radius: 12px;
        font-size: 16px;
        color: var(--primary-color);
        font-weight: 600;
        margin-bottom: 10px;
    }

    .otp-timer .countdown {
        font-size: 18px;
        font-weight: 700;
        color: var(--primary-color);
    }

    .input-box {
        position: relative;
        margin: 15px 0;
    }

    .input-field {
        width: 100%;
        height: 60px;
        font-size: 20px;
        background: transparent;
        color: var(--black-color);
        padding: 0 25px;
        border: 2px solid var(--input-border-color);
        border-radius: 15px;
        outline: none;
        transition: all 0.3s ease;
        text-align: center;
        font-family: 'Courier New', monospace;
        letter-spacing: 4px;
        font-weight: 600;
    }

    .input-field:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(12, 12, 98, 0.1);
        transform: translateY(-2px);
    }

    .label {
        position: absolute;
        top: -10px;
        left: 20px;
        background-color: var(--white-color);
        color: var(--primary-color);
        font-size: 14px;
        font-weight: 500;
        padding: 0 8px;
        z-index: 1;
    }

    .icon {
        position: absolute;
        top: 50%;
        right: 20px;
        transform: translateY(-50%);
        font-size: 24px;
        color: var(--primary-color);
    }

    .form-actions {
        display: flex;
        flex-direction: column;
        gap: 15px;
        margin-top: 10px;
    }

    .btn-submit {
        width: 100%;
        height: 55px;
        background: linear-gradient(135deg, var(--primary-color), #1a1a7a);
        color: var(--white-color);
        font-size: 18px;
        font-weight: 600;
        border: none;
        border-radius: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(12, 12, 98, 0.3);
    }

    .btn-submit:active {
        transform: translateY(0);
    }

    .btn-resend {
        width: 100%;
        height: 50px;
        background-color: transparent;
        color: var(--primary-color);
        border: 2px solid var(--primary-color);
        border-radius: 12px;
        font-size: 16px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .btn-resend:hover:not(:disabled) {
        background-color: var(--primary-color);
        color: var(--white-color);
        transform: translateY(-1px);
    }

    .btn-resend:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    .switch-form {
        text-align: center;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #f0f0f0;
    }

    .switch-form a {
        font-weight: 500;
        color: var(--primary-color);
        text-decoration: none;
        transition: color 0.3s ease;
    }

    .switch-form a:hover {
        color: #1a1a7a;
        text-decoration: underline;
    }

    .error-message {
        text-align: center;
        padding: 15px;
        background: rgba(255, 77, 79, 0.1);
        border: 1px solid rgba(255, 77, 79, 0.3);
        border-radius: 12px;
        color: var(--error-color);
        margin: 15px 0;
        font-weight: 500;
    }

    .incorrect-code-notice {
        text-align: center;
        color: var(--error-color);
        margin: 10px 0;
        font-weight: 500;
        font-size: 14px;
    }

    /* Toast notification styles */
    #toast-container {
        position: fixed;
        top: 30px;
        right: 30px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 12px;
        align-items: flex-end;
    }

    .toast {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-width: 300px;
        max-width: 400px;
        padding: 16px 20px;
        border-radius: 12px;
        font-size: 16px;
        color: white;
        animation: slideInRight 0.4s ease-out forwards;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        background-color: #333;
        opacity: 0;
        transform: translateX(100%);
    }

    .toast.success {
        background: linear-gradient(135deg, var(--success-color), #3a9a3a);
    }

    .toast.error {
        background: linear-gradient(135deg, var(--error-color), #e63946);
    }

    .toast .close-btn {
        background: none;
        border: none;
        color: white;
        font-size: 20px;
        font-weight: bold;
        margin-left: 15px;
        cursor: pointer;
        opacity: 0.8;
        transition: opacity 0.3s ease;
    }

    .toast .close-btn:hover {
        opacity: 1;
    }

    @keyframes slideInRight {
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    /* Responsive styles */
    @media only screen and (max-width: 480px) {
        body {
            padding: 10px;
        }
        
        .wrapper {
            max-width: 100%;
        }
        
        .otp-form {
            padding: 30px 20px 20px;
        }
        
        .input-field {
            font-size: 18px;
            height: 55px;
        }
        
        .btn-submit {
            height: 50px;
            font-size: 16px;
        }
        
        .btn-resend {
            height: 45px;
            font-size: 14px;
        }
    }

    @media only screen and (max-width: 360px) {
        .otp-form {
            padding: 25px 15px 15px;
        }
        
        .input-field {
            font-size: 16px;
            height: 50px;
            letter-spacing: 2px;
        }
    }
  </style>
  <title>Walk-in Conversion OTP Verification - Pound for Pound</title>
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
          fetch('walkin_otp_handler.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'resend_walkin_otp=1'
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
    <div class="title-register">Walk-in OTP Verification</div>
  </div>

  <?php 
  // Store error message before clearing session
  $error_message = $_SESSION['error'] ?? '';
  if (isset($_SESSION['error'])): ?>
    <div class="error-message">
      <i class='bx bx-error-circle'></i>
      <p><?= htmlspecialchars($_SESSION['error']) ?></p>
    </div>
    <?php unset($_SESSION['error']); ?>
  <?php endif; ?>

  <?php if (isset($otp_expired) && $otp_expired && !isset($_SESSION['walkin_otp_email'])): ?>
    <!-- OTP Expired Message -->
    <div class="otp-form">
      <div class="error-message" style="text-align: center; padding: 2rem;">
        <h3 style="margin-bottom: 1rem; font-size: 24px;">⏰ Session Expired</h3>
        <p style="margin-bottom: 1.5rem; font-size: 16px;">Your verification session has expired. Please start the walk-in conversion process again.</p>
        <a href="walkin__log.php" class="btn-submit" style="display: inline-block; text-decoration: none; width: auto; padding: 0 30px;">
          Back to Walk-in Log
        </a>
      </div>
    </div>
  <?php else: ?>
    <!-- OTP Verification Form -->
    <form method="POST" action="walkin_otp_handler.php" class="otp-form" autocomplete="off">
      <!-- OTP Timer -->
      <div class="otp-timer" id="otp-timer">
        <i class='bx bx-time-five'></i>
        Code expires in: <span class="countdown" id="countdown">05:00</span>
      </div>

      <!-- Incorrect Code Notice -->
      <?php if (!empty($error_message) && strpos($error_message, 'Invalid or expired verification code') !== false): ?>
        <div class="incorrect-code-notice">
          <i class='bx bx-error'></i>
          Incorrect verification code. Please try again.
        </div>
      <?php endif; ?>

      <!-- OTP Input Field -->
      <div class="input-box">
        <input type="text" class="input-field" id="otp_code" name="otp_code" maxlength="6" required autocomplete="off" placeholder="000000" />
        <label for="otp_code" class="label">Enter 6-digit verification code</label>
        <i class='bx bx-shield-check icon'></i>
      </div>

      <!-- Form Actions -->
      <div class="form-actions">
        <button class="btn-submit" name="verify_walkin_otp" type="submit">
          <i class='bx bx-check-circle'></i>
          Verify Code
        </button>
        <button type="button" class="btn-resend" id="resend-btn" disabled>
          <i class='bx bx-revision'></i>
          Resend Code
        </button>
      </div>

      <!-- Back Link -->
      <div class="switch-form">
        <span>Wrong details input? <a href="walkin__log.php">Go Back</a></span>
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

// Initialize form and add input formatting
document.addEventListener('DOMContentLoaded', function() {
    const otpInput = document.getElementById('otp_code');
    
    if (otpInput) {
        // Format OTP input to only allow numbers
        otpInput.addEventListener('input', function(e) {
            // Remove any non-numeric characters
            this.value = this.value.replace(/[^0-9]/g, '');
            
            // Auto-focus next input if 6 digits entered
            if (this.value.length === 6) {
                this.blur();
            }
        });
        
        // Prevent non-numeric input
        otpInput.addEventListener('keypress', function(e) {
            if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'Tab', 'Enter', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                e.preventDefault();
            }
        });
        
        // Auto-focus on load
        otpInput.focus();
    }
});
</script>

</body>
</html>
