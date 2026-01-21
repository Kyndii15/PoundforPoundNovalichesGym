<?php
include '../config.php';
include '../includes/membership_plans_helper.php';
include 'navbar.php';

// Check if membership_plans table exists
$table_exists = false;
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'membership_plans'");
if ($check_table && mysqli_num_rows($check_table) > 0) {
    $table_exists = true;
}

// Handle form submissions
$success_message = '';
$error_message = '';

// If table doesn't exist, show error and prevent further execution
if (!$table_exists) {
    $error_message = "The membership_plans table does not exist. Please run the database migration script: database/create_membership_plans_table.sql";
}

// Add new plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    if (!$table_exists) {
        $error_message = "The membership_plans table does not exist. Please run the database migration script first.";
    } else {
        $package_type = mysqli_real_escape_string($conn, $_POST['package_type']);
        $plan_name = mysqli_real_escape_string($conn, $_POST['plan_name']);
        $price = floatval($_POST['price']);
        $duration_months = intval($_POST['duration_months']);
        $inclusions = mysqli_real_escape_string($conn, $_POST['inclusions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $display_order = intval($_POST['display_order']);
        
        $query = "INSERT INTO membership_plans (package_type, plan_name, price, duration_months, inclusions, is_active, display_order) 
                  VALUES ('$package_type', '$plan_name', $price, $duration_months, '$inclusions', $is_active, $display_order)";
        
        if (mysqli_query($conn, $query)) {
            $success_message = "Membership plan added successfully!";
        } else {
            $error_message = "Error adding plan: " . mysqli_error($conn);
        }
    }
}

// Update plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    if (!$table_exists) {
        $error_message = "The membership_plans table does not exist. Please run the database migration script first.";
    } else {
        $id = intval($_POST['id']);
        $package_type = mysqli_real_escape_string($conn, $_POST['package_type']);
        $plan_name = mysqli_real_escape_string($conn, $_POST['plan_name']);
        $price = floatval($_POST['price']);
        $duration_months = intval($_POST['duration_months']);
        $inclusions = mysqli_real_escape_string($conn, $_POST['inclusions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $display_order = intval($_POST['display_order']);
        
        $query = "UPDATE membership_plans 
                  SET package_type = '$package_type', 
                      plan_name = '$plan_name', 
                      price = $price, 
                      duration_months = $duration_months, 
                      inclusions = '$inclusions', 
                      is_active = $is_active, 
                      display_order = $display_order 
                  WHERE id = $id";
        
        if (mysqli_query($conn, $query)) {
            $success_message = "Membership plan updated successfully!";
        } else {
            $error_message = "Error updating plan: " . mysqli_error($conn);
        }
    }
}

// Delete plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!$table_exists) {
        $error_message = "The membership_plans table does not exist. Please run the database migration script first.";
    } else {
        $id = intval($_POST['id']);
        
        // Check if plan is being used in subscriptions
        $check_query = "SELECT COUNT(*) as count FROM subscriptions WHERE plan_name = (SELECT plan_name FROM membership_plans WHERE id = $id)";
        $check_result = mysqli_query($conn, $check_query);
        $check_data = mysqli_fetch_assoc($check_result);
        
        if ($check_data['count'] > 0) {
            // Instead of deleting, deactivate it
            $query = "UPDATE membership_plans SET is_active = 0 WHERE id = $id";
            $success_message = "Plan deactivated (cannot delete as it's being used in existing subscriptions)";
        } else {
            $query = "DELETE FROM membership_plans WHERE id = $id";
            $success_message = "Plan deleted successfully!";
        }
        
        if (mysqli_query($conn, $query)) {
            // Success message already set above
        } else {
            $error_message = "Error deleting plan: " . mysqli_error($conn);
        }
    }
}

// Get all plans
$plans = [];
if ($table_exists) {
    $plans_query = "SELECT * FROM membership_plans ORDER BY display_order ASC, package_type ASC, duration_months ASC";
    $plans_result = mysqli_query($conn, $plans_query);
    if ($plans_result) {
        while ($row = mysqli_fetch_assoc($plans_result)) {
            $plans[] = $row;
        }
    }
}

// Get plan for editing
$edit_plan = null;
if (isset($_GET['edit']) && $table_exists) {
    $edit_id = intval($_GET['edit']);
    $edit_plan = getMembershipPlanById($conn, $edit_id);
    if (!$edit_plan) {
        // Try to get inactive plan too for editing
        $edit_query = "SELECT * FROM membership_plans WHERE id = $edit_id";
        $edit_result = mysqli_query($conn, $edit_query);
        if ($edit_result) {
            $edit_plan = mysqli_fetch_assoc($edit_result);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Plans Management</title>
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .plans-container {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            color: var(--accent);
            margin: 0;
        }

        .btn-add {
            background: var(--accent);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s ease;
        }

        .btn-add:hover {
            background: rgba(94, 99, 255, 0.9);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.3);
            color: #28a745;
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #dc3545;
        }

        .plans-table {
            width: 100%;
            background: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--line-clr);
        }

        .plans-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .plans-table th,
        .plans-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--line-clr);
        }

        .plans-table th {
            background: #2a2b36;
            color: var(--accent);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .plans-table td {
            color: var(--text-clr);
        }

        .plans-table tr:hover {
            background: rgba(94, 99, 255, 0.05);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .status-inactive {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .btn-edit,
        .btn-delete {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            margin-right: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-edit {
            background: rgba(94, 99, 255, 0.1);
            color: var(--accent);
        }

        .btn-edit:hover {
            background: rgba(94, 99, 255, 0.2);
        }

        .btn-delete {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .btn-delete:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        .form-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .form-modal.active {
            display: flex;
        }

        .form-content {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--line-clr);
        }

        .form-header h2 {
            margin: 0;
            color: var(--accent);
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--text-clr);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-clr);
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--line-clr);
            border-radius: 6px;
            background: #2a2b36;
            color: var(--text-clr);
            font-size: 1rem;
            box-sizing: border-box;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group input[type="checkbox"] {
            width: auto;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .btn-submit {
            background: var(--accent);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-cancel {
            background: transparent;
            color: var(--text-clr);
            padding: 0.75rem 1.5rem;
            border: 1px solid var(--line-clr);
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="plans-container">
        <div class="page-header">
            <h1>Membership Plans Management</h1>
            <?php if ($table_exists): ?>
                <button class="btn-add" onclick="openAddModal()">+ Add New Plan</button>
            <?php endif; ?>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error_message) ?>
                <br><br>
                <strong>To fix this:</strong>
                <ol style="margin: 10px 0; padding-left: 20px;">
                    <li>Open phpMyAdmin</li>
                    <li>Select your database</li>
                    <li>Go to the SQL tab</li>
                    <li>Open and run the SQL script: <code>database/create_membership_plans_table.sql</code></li>
                </ol>
            </div>
        <?php endif; ?>

        <?php if (!$table_exists): ?>
            <div class="alert alert-error" style="margin-top: 2rem;">
                <strong>⚠️ Database Table Missing</strong>
                <p>The membership_plans table needs to be created before you can manage plans.</p>
                <p>Please run the database migration script: <code>database/create_membership_plans_table.sql</code></p>
            </div>
        <?php endif; ?>

        <?php if ($table_exists): ?>
        <div class="plans-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Package Type</th>
                        <th>Plan Name</th>
                        <th>Price</th>
                        <th>Duration</th>
                        <th>Inclusions</th>
                        <th>Status</th>
                        <th>Order</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 2rem; color: var(--secondary-text-clr);">
                                No membership plans found. Click "Add New Plan" to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($plans as $plan): ?>
                        <tr>
                            <td><?= $plan['id'] ?></td>
                            <td><?= htmlspecialchars($plan['package_type']) ?></td>
                            <td><?= htmlspecialchars($plan['plan_name']) ?></td>
                            <td>₱<?= number_format($plan['price'], 2) ?></td>
                            <td><?= $plan['duration_months'] ?> month<?= $plan['duration_months'] > 1 ? 's' : '' ?></td>
                            <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($plan['inclusions']) ?>">
                                <?= htmlspecialchars($plan['inclusions']) ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $plan['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $plan['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= $plan['display_order'] ?></td>
                            <td>
                                <button class="btn-edit" onclick="openEditModal(<?= $plan['id'] ?>)">Edit</button>
                                <button class="btn-delete" onclick="deletePlan(<?= $plan['id'] ?>)">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <?php if ($table_exists): ?>
    <div id="formModal" class="form-modal <?= $edit_plan ? 'active' : '' ?>">
        <div class="form-content">
            <div class="form-header">
                <h2><?= $edit_plan ? 'Edit Plan' : 'Add New Plan' ?></h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="<?= $edit_plan ? 'update' : 'add' ?>">
                <?php if ($edit_plan): ?>
                    <input type="hidden" name="id" value="<?= $edit_plan['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="package_type">Package Type *</label>
                    <input type="text" id="package_type" name="package_type" required 
                           value="<?= $edit_plan ? htmlspecialchars($edit_plan['package_type']) : '' ?>"
                           placeholder="e.g., Boxing, Circuit Training, Muay Thai">
                </div>

                <div class="form-group">
                    <label for="plan_name">Plan Name *</label>
                    <input type="text" id="plan_name" name="plan_name" required 
                           value="<?= $edit_plan ? htmlspecialchars($edit_plan['plan_name']) : '' ?>"
                           placeholder="e.g., Package 1: Boxing (1 Month)">
                </div>

                <div class="form-group">
                    <label for="price">Price (₱) *</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" required 
                           value="<?= $edit_plan ? $edit_plan['price'] : '' ?>">
                </div>

                <div class="form-group">
                    <label for="duration_months">Duration (Months) *</label>
                    <input type="number" id="duration_months" name="duration_months" min="1" required 
                           value="<?= $edit_plan ? $edit_plan['duration_months'] : '' ?>">
                </div>

                <div class="form-group">
                    <label for="inclusions">Inclusions *</label>
                    <textarea id="inclusions" name="inclusions" required 
                              placeholder="Comma-separated list of inclusions"><?= $edit_plan ? htmlspecialchars($edit_plan['inclusions']) : '' ?></textarea>
                    <small style="color: var(--secondary-text-clr);">Separate multiple inclusions with commas</small>
                </div>

                <div class="form-group">
                    <label for="display_order">Display Order</label>
                    <input type="number" id="display_order" name="display_order" min="0" 
                           value="<?= $edit_plan ? $edit_plan['display_order'] : '0' ?>">
                    <small style="color: var(--secondary-text-clr);">Lower numbers appear first</small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" value="1" 
                               <?= $edit_plan && $edit_plan['is_active'] ? 'checked' : ($edit_plan ? '' : 'checked') ?>>
                        Active (Available for purchase)
                    </label>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-submit"><?= $edit_plan ? 'Update Plan' : 'Add Plan' ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function openAddModal() {
            window.location.href = 'membership_plans.php';
        }

        function openEditModal(id) {
            window.location.href = 'membership_plans.php?edit=' + id;
        }

        function closeModal() {
            window.location.href = 'membership_plans.php';
        }

        function deletePlan(id) {
            if (confirm('Are you sure you want to delete this plan? If it\'s being used in existing subscriptions, it will be deactivated instead.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';
                form.appendChild(actionInput);
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id';
                idInput.value = id;
                form.appendChild(idInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>

