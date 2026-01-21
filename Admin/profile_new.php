<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include '../config.php';
include '../auth_check.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Require admin role for admin profile access
requireRole('admin');

// Get user ID from session (authentication ensures this exists)
$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    
    // Update profile (email is readonly, so we don't update it)
    $update_query = "UPDATE users SET full_name = '$full_name' WHERE id = $user_id";
    
    if (mysqli_query($conn, $update_query)) {
        // Update session data
        $_SESSION['user_name'] = $full_name;
        $_SESSION['user_email'] = $user_data['email'] ?? ''; // Use existing email from database
        $success_message = "Profile updated successfully!";
    } else {
        $errors[] = "Failed to update profile. Please try again.";
    }
}

// Get current user data
$user_query = "SELECT * FROM users WHERE id = $user_id";
$user_result = mysqli_query($conn, $user_query);

if (!$user_result) {
    $errors[] = "Failed to retrieve user data. Please try again.";
    $user_data = [];
} else {
    $user_data = mysqli_fetch_assoc($user_result);
    if (!$user_data) {
        $errors[] = "User data not found. Please contact administrator.";
        $user_data = [];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Admin Dashboard</title>
    <link rel="stylesheet" href="../css/user.css">
    <script type="text/javascript" src="../java/admin.js?v=2.0" defer></script>
    <style>
        /* Enhanced Personal Information UI */
        .header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .edit-btn {
            background: var(--accent);
            color: white;
            border: 1px solid var(--accent);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            font-size: 0.875rem;
        }

        .edit-btn:hover {
            background: #4c52e8;
            border-color: #4c52e8;
        }

        .profile-display {
            margin-top: 1.5rem;
            background: transparent;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--line-clr);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .info-item {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid rgba(94, 99, 255, 0.08);
        }

        .info-item label {
            font-weight: 700;
            color: var(--accent);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-item label::before {
            content: '';
            width: 4px;
            height: 4px;
            background: var(--accent);
            border-radius: 50%;
        }

        .info-item .info-value {
            color: var(--text-clr);
            font-size: 1.1rem;
            font-weight: 500;
            line-height: 1.4;
            word-break: break-word;
        }

        .info-item .info-value:empty::after {
            content: 'Not provided';
            color: var(--secondary-text-clr);
            font-style: italic;
            font-weight: 400;
        }

        /* Enhanced Edit Form Styling */
        .profile-form-container {
            margin-top: 1.5rem;
            background: transparent;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--line-clr);
        }

        .profile-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .profile-form .form-group {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid rgba(94, 99, 255, 0.08);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .profile-form .form-group label {
            font-weight: 700;
            color: var(--accent);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-form .form-group label::before {
            content: '';
            width: 4px;
            height: 4px;
            background: var(--accent);
            border-radius: 50%;
        }

        .profile-form .form-group input,
        .profile-form .form-group textarea {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(94, 99, 255, 0.2);
            border-radius: 6px;
            padding: 0.75rem;
            color: var(--text-clr);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .profile-form .form-group input:focus,
        .profile-form .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(94, 99, 255, 0.1);
        }

        .profile-form .form-group input[readonly] {
            background: rgba(255, 255, 255, 0.02);
            color: var(--secondary-text-clr);
            cursor: not-allowed;
            border-color: rgba(94, 99, 255, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: flex-end;
            grid-column: 1 / -1;
        }

        .form-actions .btn {
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            border: 1px solid;
            transition: all 0.3s ease;
        }

        .form-actions .btn-primary {
            background: #28a745 !important;
            color: white !important;
            border-color: #28a745 !important;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
        }

        .form-actions .btn-primary:hover {
            background: #218838 !important;
            border-color: #1e7e34 !important;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }

        /* Two Column Layout */
        .two-column-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        /* Account Information Styling */
        .account-info-table-container {
            margin-top: 1.5rem;
        }

        .account-info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .account-info-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--line-clr);
        }

        .account-info-table .info-label {
            font-weight: 700;
            color: var(--accent);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            width: 40%;
        }

        .account-info-table .info-value {
            color: var(--text-clr);
            font-size: 1rem;
            font-weight: 500;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-success {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .status-error {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .two-column-layout {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .info-grid,
            .profile-form {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .info-item,
            .profile-form .form-group {
                padding: 0.75rem;
            }

            .form-actions {
                flex-direction: column;
                gap: 0.75rem;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .edit-btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }

            .profile-form-container,
            .profile-display {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <main>
        <h1>My Profile</h1>
        <p></p>

        <!-- Messages -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c17.7 0 32 14.3 32 32V256c0 17.7-14.3 32-32 32s-32-14.3-32-32V160c0-17.7 14.3-32 32-32zM224 352a32 32 0 1 1 64 0 32 32 0 1 1 -64 0z"/></svg>
                <span><?= implode(', ', $errors) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zm0-384c70.7 0 128 57.3 128 128s-57.3 128-128 128s-128-57.3-128-128s57.3-128 128-128zM96.8 431.2c5.1-12.2 19.1-18.8 31.3-13.7c67.9 28.3 144.1 28.3 212 0c12.2-5.1 26.2 1.5 31.3 13.7c5.1 12.2-1.5 26.2-13.7 31.3c-82.6 34.4-175.7 34.4-258.3 0c-12.2-5.1-18.8-19.1-13.7-31.3z"/></svg>
                <span><?= $success_message ?></span>
            </div>
        <?php endif; ?>

        <!-- Two Column Layout -->
        <div class="two-column-layout">
            <!-- Profile Information -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Personal Information</h2>
                    <div class="header-actions">
                        <button type="button" id="edit-profile-btn" class="btn btn-secondary edit-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512"><path d="M471.6 21.7c-21.9-21.9-57.3-21.9-79.2 0L362.3 51.7l97.9 97.9 30.1-30.1c21.9-21.9 21.9-57.3 0-79.2L471.6 21.7zm-299.2 220c-6.1 6.1-10.8 13.6-13.5 21.9l-29.6 88.8c-2.9 8.6-.6 18.1 5.8 24.6s15.9 8.7 24.6 5.8l88.8-29.6c8.2-2.7 15.7-7.4 21.9-13.5L437.7 172.3 339.7 74.3 172.4 241.7zM96 64C43 64 0 107 0 160V416c0 53 43 96 96 96H352c53 0 96-43 96-96V320c0-17.7-14.3-32-32-32s-32 14.3-32 32v96c0 17.7-14.3 32-32 32H96c-17.7 0-32-14.3-32-32V160c0-17.7 14.3-32 32-32h96c17.3 0 32-14.3 32-32s-14.3-32-32-32H96z"/></svg>
                            <span>Edit</span>
                        </button>
                    </div>
                </div>
                
                <!-- Read-only display -->
                <div id="profile-display" class="profile-display">
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Full Name</label>
                            <span class="info-value"><?= htmlspecialchars($user_data['full_name'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <label>Email Address</label>
                            <span class="info-value"><?= htmlspecialchars($user_data['email'] ?? '') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Edit form (hidden by default) -->
                <div id="profile-form" class="profile-form-container" style="display: none;">
                    <form method="POST" class="profile-form">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($user_data['full_name'] ?? '') ?>" autocomplete="off" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" readonly style="background-color: #f5f5f5; color: #666; cursor: not-allowed;" autocomplete="off">
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512"><path d="M471.6 21.7c-21.9-21.9-57.3-21.9-79.2 0L362.3 51.7l97.9 97.9 30.1-30.1c21.9-21.9 21.9-57.3 0-79.2L471.6 21.7zm-299.2 220c-6.1 6.1-10.8 13.6-13.5 21.9l-29.6 88.8c-2.9 8.6-.6 18.1 5.8 24.6s15.9 8.7 24.6 5.8l88.8-29.6c8.2-2.7 15.7-7.4 21.9-13.5L437.7 172.3 339.7 74.3 172.4 241.7zM96 64C43 64 0 107 0 160V416c0 53 43 96 96 96H352c53 0 96-43 96-96V320c0-17.7-14.3-32-32-32s-32 14.3-32 32v96c0 17.7-14.3 32-32 32H96c-17.7 0-32-14.3-32-32V160c0-17.7 14.3-32 32-32h96c17.3 0 32-14.3 32-32s-14.3-32-32-32H96z"/></svg>
                                <span>Save Changes</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Account Information -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Account Information</h2>
                    <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>
                </div>
                
                <div class="account-info-table-container">
                    <table class="account-info-table">
                        <tbody>
                            <tr>
                                <td class="info-label">Account ID</td>
                                <td class="info-value"><?= $user_data['id'] ?? 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td class="info-label">Role</td>
                                <td class="info-value">
                                    <span class="status-badge status-success">
                                        <?= ucfirst($user_data['role'] ?? 'Unknown') ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="info-label">Account Status</td>
                                <td class="info-value">
                                    <span class="status-badge <?= ($user_data['archived'] ?? 0) ? 'status-error' : 'status-success' ?>">
                                        <?= ($user_data['archived'] ?? 0) ? 'Archived' : 'Active' ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="info-label">Registration Date</td>
                                <td class="info-value"><?= isset($user_data['created_at']) ? date('F j, Y \a\t g:i A', strtotime($user_data['created_at'] . ' +8 hours')) : 'N/A' ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });

            // Profile edit functionality
            const editBtn = document.getElementById('edit-profile-btn');
            const profileDisplay = document.getElementById('profile-display');
            const profileForm = document.getElementById('profile-form');

            // Edit button functionality
            if (editBtn) {
                editBtn.addEventListener('click', function() {
                    if (profileDisplay && profileForm) {
                        profileDisplay.style.display = 'none';
                        profileForm.style.display = 'block';
                        
                        // Change Edit button to X button
                        editBtn.innerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512">
                                <path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM175 175c9.4-9.4 24.6-9.4 33.9 0l47 47 47-47c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9l-47 47 47 47c9.4 9.4 9.4 24.6 0 33.9s-24.6 9.4-33.9 0l-47-47-47 47c-9.4 9.4-24.6 9.4-33.9 0s-9.4-24.6 0-33.9l47-47-47-47c-9.4-9.4-9.4-24.6 0-33.9z"/>
                            </svg>
                            <span>Close</span>
                        `;
                        editBtn.id = 'close-edit-btn';
                    }
                });
            }

            // Close button functionality (when X button is clicked)
            document.addEventListener('click', function(e) {
                if (e.target.closest('#close-edit-btn')) {
                    if (profileDisplay && profileForm) {
                        profileForm.style.display = 'none';
                        profileDisplay.style.display = 'block';
                        
                        // Change X button back to Edit button
                        const closeBtn = e.target.closest('#close-edit-btn');
                        closeBtn.innerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" height="20" width="20" viewBox="0 0 512 512">
                                <path d="M471.6 21.7c-21.9-21.9-57.3-21.9-79.2 0L362.3 51.7l97.9 97.9 30.1-30.1c21.9-21.9 21.9-57.3 0-79.2L471.6 21.7zm-299.2 220c-6.1 6.1-10.8 13.6-13.5 21.9l-29.6 88.8c-2.9 8.6-.6 18.1 5.8 24.6s15.9 8.7 24.6 5.8l88.8-29.6c8.2-2.7 15.7-7.4 21.9-13.5L437.7 172.3 339.7 74.3 172.4 241.7zM96 64C43 64 0 107 0 160V416c0 53 43 96 96 96H352c53 0 96-43 96-96V320c0-17.7-14.3-32-32-32s-32 14.3-32 32v96c0 17.7-14.3 32-32 32H96c-17.7 0-32-14.3-32-32V160c0-17.7 14.3-32 32-32h96c17.3 0 32-14.3 32-32s-14.3-32-32-32H96z"/>
                            </svg>
                            <span>Edit</span>
                        `;
                        closeBtn.id = 'edit-profile-btn';
                    }
                }
            });

            // Form validation
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!form.checkValidity()) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    form.classList.add('was-validated');
                });
            });
        });
    </script>

</body>
</html>

















