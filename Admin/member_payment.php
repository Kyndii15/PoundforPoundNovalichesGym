<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('gym_admin_session');
    session_start();
}
@include '../config.php';

// Check if there's member payment data in session
if (!isset($_SESSION['member_payment_data'])) {
    header('Location: member__form.php');
    exit;
}

$member_data = $_SESSION['member_payment_data'];
$full_name = $member_data['full_name'];
$membership_plan = $member_data['membership_plan'];
$email = $member_data['email'];
$phone = $member_data['phone'] ?? '';
$address = $member_data['address'] ?? '';

// Extract price from membership plan (matching the form options)
$price = 0;
if (strpos($membership_plan, 'Boxing (1 Month)') !== false) $price = 2500;
elseif (strpos($membership_plan, 'Boxing (2 Months)') !== false) $price = 4000;
elseif (strpos($membership_plan, 'Boxing (3 Months)') !== false) $price = 6000;
elseif (strpos($membership_plan, 'Circuit Training (1 Month)') !== false) $price = 1700;
elseif (strpos($membership_plan, 'Circuit Training (3 Months)') !== false) $price = 2900;
elseif (strpos($membership_plan, 'Circuit Training (6 Months)') !== false) $price = 5500;
elseif (strpos($membership_plan, 'Circuit Training (1 Year)') !== false) $price = 9000;
elseif (strpos($membership_plan, 'Muay Thai (1 Month)') !== false) $price = 3000;
elseif (strpos($membership_plan, 'Muay Thai (2 Months)') !== false) $price = 5000;
elseif (strpos($membership_plan, 'Muay Thai (3 Months)') !== false) $price = 7000;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Payment - Pound for Pound</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* Import Poppins font */
        @import url("https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap");
        
        /* Apply font family and background from styles__access__form.css */
        * {
            font-family: "Poppins", sans-serif;
        }
        
        body {
            background: linear-gradient(to right, #090979, #000000, #090979);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .payment-container {
            max-width: 800px;
            width: 90%;
            margin: 1rem auto;
            padding: 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .payment-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .payment-header h1 {
            color: #0c0c62;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .payment-header p {
            color: #666;
            font-size: 0.95rem;
        }

        .payment-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        .payment-info {
            background: #f8f9fa;
            padding: 1.25rem;
            border-radius: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .info-row:last-child {
            border-top: 2px solid #0c0c62;
            border-bottom: none;
            margin-top: 1rem;
            font-weight: bold;
            font-size: 1.2rem;
            background: #e8f0fe;
            padding: 1rem;
            border-radius: 6px;
        }

        .info-label {
            color: #333;
            font-weight: 600;
            font-size: 1rem;
            min-width: 140px;
        }

        .info-value {
            color: #0c0c62;
            font-weight: 600;
            font-size: 1rem;
            text-align: right;
            flex: 1;
        }

        .payment-methods h3 {
            color: #333;
            margin-bottom: 0.8rem;
            font-size: 1.1rem;
        }

        .payment-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.8rem;
        }

        .payment-option {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 0.75rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .payment-option:hover {
            border-color: #0c0c62;
            background: #f8f9fa;
        }

        .payment-option.selected {
            border-color: #0c0c62;
            background: #e8f0fe;
        }

        .payment-option input[type="radio"] {
            display: none;
        }

        .payment-option i {
            font-size: 2rem;
            color: #0c0c62;
            margin-bottom: 0.5rem;
        }

        .payment-option h4 {
            color: #333;
            margin-bottom: 0.5rem;
        }

        .payment-option p {
            color: #666;
            font-size: 0.9rem;
        }

        .proceed-btn {
            width: 100%;
            padding: 1rem;
            background: #0c0c62;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 2rem;
        }

        .proceed-btn:hover {
            background: #0a0a52;
            transform: translateY(-2px);
        }

        .proceed-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        /* Payment Fields Styles */
        .payment-fields {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 3px solid #0c0c62;
            box-shadow: 0 2px 8px rgba(12, 12, 98, 0.15);
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        /* GCash Payment Fields - Smaller Panel */
        #gcashFields {
            padding: 1rem;
            margin-top: 1rem;
        }

        #gcashFields h3 {
            margin-bottom: 0.75rem;
            font-size: 1rem;
        }

        .payment-fields.show {
            opacity: 1;
            transform: translateY(0);
        }

        .payment-fields h3 {
            color: #0c0c62;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            font-weight: 700;
            text-align: center;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #0c0c62;
        }

        .form-group {
            margin-bottom: 0.75rem;
        }

        .form-group label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 0.4rem;
            font-size: 0.9rem;
        }

        .form-group input[type="text"] {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }

        .form-group input[type="text"]:focus {
            outline: none;
            border-color: #0c0c62;
        }

        /* Validation styles */
        .form-group input[type="text"].valid-input {
            border-color: #28a745 !important;
        }

        .form-group input[type="text"].invalid {
            border-color: #dc3545 !important;
        }

        .validation-notice {
            margin-top: 0.5rem;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
        }

        .validation-notice.gcash-validation-error {
            color: #dc3545;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        .file-upload-section {
            margin-top: 0.75rem;
            text-align: center;
        }

        .file-upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.6rem 1.2rem;
            background: #0c0c62;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .file-upload-btn:hover {
            background: #0a0a52;
            transform: translateY(-2px);
        }

        .file-info {
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: #e8f0fe;
            border-radius: 6px;
            font-size: 0.9rem;
            color: #0c0c62;
            display: none;
        }

        /* Responsive design for mobile devices */
        @media (max-width: 768px) {
            .payment-container {
                max-width: 95%;
                margin: 0.5rem auto;
                padding: 1rem;
            }
            
            .payment-content {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .payment-header h1 {
                font-size: 1.5rem;
            }
            
            .payment-options {
                grid-template-columns: 1fr;
                gap: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="payment-container">
            <div class="payment-header">
                <h1>Complete Payment</h1>
                <p>Choose your preferred payment method to complete your membership registration</p>
            </div>

            <div class="payment-content">
                <div class="payment-info">
                    <div class="info-row">
                        <span class="info-label">Member Name:</span>
                        <span class="info-value"><?= htmlspecialchars($full_name) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?= htmlspecialchars($email) ?></span>
                    </div>
                    <?php if (!empty($phone)): ?>
                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?= htmlspecialchars($phone) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label">Membership Plan:</span>
                        <span class="info-value"><?= htmlspecialchars($membership_plan) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Total Amount:</span>
                        <span class="info-value">₱<?= number_format($price, 2) ?></span>
                    </div>
                </div>

                <div class="payment-methods">
                    <h3>Select Payment Method</h3>
                    <div class="payment-options">
                        <label class="payment-option" for="cash">
                            <input type="radio" id="cash" name="payment_method" value="cash">
                            <i class='bx bx-money'></i>
                            <h4>Cash Payment</h4>
                            <p>Pay with cash and upload receipt</p>
                        </label>
                        <label class="payment-option" for="gcash">
                            <input type="radio" id="gcash" name="payment_method" value="gcash">
                            <i class='bx bx-credit-card'></i>
                            <h4>GCash Payment</h4>
                            <p>Pay via GCash and upload screenshot</p>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Cash Payment Fields -->
            <div id="cashFields" class="payment-fields" style="display: none;">
                <h3>Upload Official Receipt</h3>
                <div class="file-upload-section">
                    <input type="file" id="cashReceipt" name="cash_receipt" accept="image/*,.pdf" style="display: none;">
                    <label for="cashReceipt" class="file-upload-btn">
                        <i class='bx bx-upload'></i>
                        <span>Choose Official Receipt</span>
                    </label>
                    <div id="cashFileInfo" class="file-info"></div>
                </div>
            </div>

            <!-- GCash Payment Fields -->
            <div id="gcashFields" class="payment-fields" style="display: none;">
                <h3>GCash Payment Details</h3>
                <div class="form-group">
                    <label for="gcashReference">Enter Reference Number:</label>
                    <input type="text" id="gcashReference" name="gcash_reference" placeholder="Enter GCash reference number" maxlength="13" pattern="[0-9]{13}" oninput="this.value = this.value.replace(/[^0-9]/g, '')" onblur="checkGCashReferenceAvailability()">
                    <div id="gcash-reference-validation-notice" class="validation-notice" style="margin-top: 0.5rem; font-size: 0.875rem; display: none;">
                        <span id="gcash-reference-validation-message"></span>
                    </div>
                </div>
                <div class="file-upload-section">
                    <input type="file" id="gcashScreenshot" name="gcash_screenshot" accept="image/*,.pdf" style="display: none;">
                    <label for="gcashScreenshot" class="file-upload-btn">
                        <i class='bx bx-upload'></i>
                        <span>Upload GCash Screenshot</span>
                    </label>
                    <div id="gcashFileInfo" class="file-info"></div>
                </div>
            </div>

            <button class="proceed-btn" id="proceedBtn" disabled>Proceed to Payment</button>
        </div>
    </div>

    <script>
        // Payment method selection
        document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
            radio.addEventListener('change', function() {
                // Remove selected class from all options
                document.querySelectorAll('.payment-option').forEach(option => {
                    option.classList.remove('selected');
                });
                
                // Add selected class to chosen option
                this.closest('.payment-option').classList.add('selected');
                
                // Show/hide payment fields based on selection
                const cashFields = document.getElementById('cashFields');
                const gcashFields = document.getElementById('gcashFields');
                const proceedBtn = document.getElementById('proceedBtn');
                
                if (this.value === 'cash') {
                    // Hide GCash fields with animation
                    gcashFields.classList.remove('show');
                    setTimeout(() => {
                        gcashFields.style.display = 'none';
                    }, 300);
                    
                    // Show Cash fields with animation
                    cashFields.style.display = 'block';
                    setTimeout(() => {
                        cashFields.classList.add('show');
                    }, 50);
                    
                    // Reset GCash fields
                    document.getElementById('gcashReference').value = '';
                    document.getElementById('gcashScreenshot').value = '';
                    document.getElementById('gcashFileInfo').style.display = 'none';
                } else if (this.value === 'gcash') {
                    // Hide Cash fields with animation
                    cashFields.classList.remove('show');
                    setTimeout(() => {
                        cashFields.style.display = 'none';
                    }, 300);
                    
                    // Show GCash fields with animation
                    gcashFields.style.display = 'block';
                    setTimeout(() => {
                        gcashFields.classList.add('show');
                    }, 50);
                    
                    // Reset Cash fields
                    document.getElementById('cashReceipt').value = '';
                    document.getElementById('cashFileInfo').style.display = 'none';
                }
                
                // Disable proceed button until required fields are filled
                proceedBtn.disabled = true;
                checkPaymentReady();
            });
        });

        // File upload handlers
        document.getElementById('cashReceipt').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const fileInfo = document.getElementById('cashFileInfo');
            
            if (file) {
                fileInfo.style.display = 'block';
                fileInfo.innerHTML = `
                    <i class='bx bx-check-circle'></i>
                    <strong>Selected:</strong> ${file.name}<br>
                    <strong>Size:</strong> ${(file.size / 1024 / 1024).toFixed(2)} MB
                `;
            } else {
                fileInfo.style.display = 'none';
            }
            checkPaymentReady();
        });

        document.getElementById('gcashScreenshot').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const fileInfo = document.getElementById('gcashFileInfo');
            
            if (file) {
                fileInfo.style.display = 'block';
                fileInfo.innerHTML = `
                    <i class='bx bx-check-circle'></i>
                    <strong>Selected:</strong> ${file.name}<br>
                    <strong>Size:</strong> ${(file.size / 1024 / 1024).toFixed(2)} MB
                `;
            } else {
                fileInfo.style.display = 'none';
            }
            checkPaymentReady();
        });

        // GCash reference number input with validation
        document.getElementById('gcashReference').addEventListener('input', function() {
            // Remove any non-numeric characters
            this.value = this.value.replace(/[^0-9]/g, '');
            
            const reference = this.value.trim();
            const notice = document.getElementById('gcash-reference-validation-notice');
            const message = document.getElementById('gcash-reference-validation-message');
            
            if (!notice || !message) {
                checkPaymentReady();
                return;
            }
            
            // Reset validation notice
            notice.style.display = 'none';
            this.classList.remove('invalid', 'valid-input');
            
            // Check if contains non-numeric characters
            if (reference.length > 0 && !/^[0-9]+$/.test(reference)) {
                notice.style.display = 'block';
                notice.className = 'validation-notice gcash-validation-error';
                message.textContent = 'GCash reference number must contain only numbers.';
                this.classList.add('invalid');
            } else if (reference.length > 0 && reference.length !== 13) {
                notice.style.display = 'block';
                notice.className = 'validation-notice gcash-validation-error';
                message.textContent = 'GCash reference number must be exactly 13 characters long.';
                this.classList.add('invalid');
            } else if (reference.length === 13) {
                // Will be validated by checkGCashReferenceAvailability on blur
                this.classList.remove('invalid');
            }
            
            checkPaymentReady();
        });

        // Function to check GCash reference availability
        function checkGCashReferenceAvailability() {
            const referenceInput = document.getElementById('gcashReference');
            const reference = referenceInput.value.trim();
            const notice = document.getElementById('gcash-reference-validation-notice');
            const message = document.getElementById('gcash-reference-validation-message');
            
            if (!notice || !message) return;
            
            // Don't check if reference is empty
            if (!reference) {
                notice.style.display = 'none';
                referenceInput.classList.remove('invalid', 'valid-input');
                return;
            }
            
            // Check if contains only numbers
            if (!/^[0-9]+$/.test(reference)) {
                notice.style.display = 'block';
                notice.className = 'validation-notice gcash-validation-error';
                message.textContent = 'GCash reference number must contain only numbers.';
                referenceInput.classList.add('invalid');
                referenceInput.classList.remove('valid-input');
                return;
            }
            
            // Check length first
            if (reference.length !== 13) {
                notice.style.display = 'block';
                notice.className = 'validation-notice gcash-validation-error';
                message.textContent = 'GCash reference number must be exactly 13 characters long.';
                referenceInput.classList.add('invalid');
                referenceInput.classList.remove('valid-input');
                return;
            }
            
            // Show loading state
            notice.style.display = 'block';
            notice.className = 'validation-notice';
            message.innerHTML = '<i class="bx bx-loader-alt bx-spin" style="margin-right: 0.25rem;"></i>Checking reference availability...';
            referenceInput.classList.remove('invalid', 'valid-input');
            
            // Make AJAX request to check reference availability
            const formData = new FormData();
            formData.append('gcash_reference', reference);
            
            fetch('../check_gcash_reference_availability.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.available) {
                    // Hide notice and just mark input as valid (green border)
                    notice.style.display = 'none';
                    referenceInput.classList.add('valid-input');
                    referenceInput.classList.remove('invalid');
                } else {
                    notice.style.display = 'block';
                    notice.className = 'validation-notice gcash-validation-error';
                    message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>' + data.message;
                    referenceInput.classList.add('invalid');
                    referenceInput.classList.remove('valid-input');
                }
            })
            .catch(error => {
                notice.style.display = 'block';
                notice.className = 'validation-notice gcash-validation-error';
                message.innerHTML = '<i class="bx bx-x" style="margin-right: 0.25rem;"></i>Error checking reference availability. Please try again.';
                referenceInput.classList.add('invalid');
                referenceInput.classList.remove('valid-input');
                console.error('GCash reference check error:', error);
            });
        }

        // Check if payment is ready to proceed
        function checkPaymentReady() {
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            const proceedBtn = document.getElementById('proceedBtn');
            
            if (!selectedMethod) {
                proceedBtn.disabled = true;
                return;
            }
            
            if (selectedMethod.value === 'cash') {
                const cashReceipt = document.getElementById('cashReceipt').files[0];
                proceedBtn.disabled = !cashReceipt;
            } else if (selectedMethod.value === 'gcash') {
                const gcashReference = document.getElementById('gcashReference').value.trim();
                const gcashScreenshot = document.getElementById('gcashScreenshot').files[0];
                const gcashReferenceInput = document.getElementById('gcashReference');
                // Check if reference is valid (13 chars and has valid-input class)
                const isValid = gcashReference.length === 13 && 
                                gcashReferenceInput.classList.contains('valid-input') &&
                                !gcashReferenceInput.classList.contains('invalid');
                proceedBtn.disabled = !(isValid && gcashScreenshot);
            }
        }

        // Proceed button click - directly process payment
        document.getElementById('proceedBtn').addEventListener('click', function() {
            const selected = document.querySelector('input[name="payment_method"]:checked');
            if (!selected) return;
            const method = selected.value;
            const formData = new FormData();
            formData.append('process_payment', '1');
            formData.append('payment_method', method);
            if (method === 'cash') {
                formData.append('amount_paid', String(<?= $price ?>));
                const receipt = document.getElementById('cashReceipt').files[0];
                if (receipt) formData.append('cash_receipt', receipt);
            } else if (method === 'gcash') {
                const ref = document.getElementById('gcashReference').value.trim();
                const shot = document.getElementById('gcashScreenshot').files[0];
                formData.append('gcash_reference', ref);
                if (shot) formData.append('gcash_screenshot', shot);
            }

            const proceedBtn = document.getElementById('proceedBtn');
            const originalText = proceedBtn.textContent;
            proceedBtn.textContent = 'Processing...';
            proceedBtn.disabled = true;

            fetch('member_payment_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // First get response as text to check for any issues
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        console.error('Response text:', text);
                        // If parsing fails, check if account was created by looking at response
                        if (text.includes('"success":true') || text.includes("'success':true")) {
                            // Try to extract JSON
                            const jsonMatch = text.match(/\{[\s\S]*\}/);
                            if (jsonMatch) {
                                try {
                                    return JSON.parse(jsonMatch[0]);
                                } catch (e2) {
                                    throw new Error('Invalid JSON response');
                                }
                            }
                        }
                        throw new Error('Invalid JSON response');
                    }
                });
            })
            .then(data => {
                console.log('Response data:', data);
                if (data && data.success === true) {
                    // Account was successfully created in database
                    alert('Member registration completed successfully!');
                    window.location.href = data.redirect || 'member__form.php?success=1';
                } else {
                    // Account was not created in database
                    alert('Error: ' + (data.message || 'Failed to process payment. Please check the console for details.'));
                    proceedBtn.textContent = originalText;
                    proceedBtn.disabled = false;
                }
            })
            .catch(error => {
                // Network error or invalid response
                console.error('Error:', error);
                alert('An error occurred. Please check the console for details and try again.');
                proceedBtn.textContent = originalText;
                proceedBtn.disabled = false;
            });
        });
    </script>
</body>
</html>
