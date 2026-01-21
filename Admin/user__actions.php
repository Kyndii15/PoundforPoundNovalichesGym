<?php
include '../config.php';
include '../includes/activity_logger.php';

// Handle bulk archive operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_archive') {
  $user_ids = $_POST['user_ids'] ?? '';
  
  if (!empty($user_ids)) {
    $ids_array = explode(',', $user_ids);
    $ids_array = array_map('intval', $ids_array); // Convert to integers for security
    $ids_string = implode(',', $ids_array);
    
    // Archive all selected users
    mysqli_query($conn, "UPDATE users SET archived = 1 WHERE id IN ($ids_string)");
    
    // Redirect back to user list
    header("Location: user__list.php");
    exit;
  }
}

// Handle bulk unarchive operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_unarchive') {
  $user_ids = $_POST['user_ids'] ?? '';
  
  if (!empty($user_ids)) {
    $ids_array = explode(',', $user_ids);
    $ids_array = array_map('intval', $ids_array); // Convert to integers for security
    $ids_string = implode(',', $ids_array);
    
    // Unarchive all selected users
    mysqli_query($conn, "UPDATE users SET archived = 0 WHERE id IN ($ids_string)");
    
    // Check if this is a member (customer role) or staff member
    $user_check = mysqli_query($conn, "SELECT role FROM users WHERE id IN ($ids_string) LIMIT 1");
    if ($user_check && mysqli_num_rows($user_check) > 0) {
      $user_data = mysqli_fetch_assoc($user_check);
      if ($user_data['role'] === 'customer') {
        header("Location: members__management.php");
      } else {
        header("Location: user__list.php");
      }
    } else {
      header("Location: user__list.php");
    }
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = $_POST['id'] ?? null;
  $full_name = $_POST['full_name'] ?? '';
  $email = $_POST['email'] ?? '';
  $role = $_POST['role'] ?? '';
  $phone = $_POST['phone'] ?? null;
  $address = $_POST['address'] ?? null;

  if ($id) {
    // UPDATE user (don't update email in edit mode)
    if (in_array($role, ['admin', 'manager', 'coach'])) {
      // For admin/manager/coach, update phone in users table
      mysqli_query($conn, "UPDATE users SET full_name='$full_name', role='$role', phone='$phone' WHERE id=$id");
    } else {
      // For other roles, update normally
      mysqli_query($conn, "UPDATE users SET full_name='$full_name', role='$role' WHERE id=$id");
    }

    if ($role === 'customer') {
      $check = mysqli_query($conn, "SELECT id FROM members WHERE user_id = $id");
      if (mysqli_num_rows($check) > 0) {
        mysqli_query($conn, "UPDATE members SET phone='$phone', address='$address' WHERE user_id = $id");
      } else {
        mysqli_query($conn, "INSERT INTO members (user_id, phone, address) VALUES ($id, '$phone', '$address')");
      }
    }

  } else {
    // CREATE new user
    $password = $_POST['password'] ?? '';
    if (!empty($password)) {
      $password_hash = password_hash($password, PASSWORD_DEFAULT);
      
      if (in_array($role, ['admin', 'manager', 'coach'])) {
        // For admin/manager/coach, include phone and set email_verified to 1
        if (mysqli_query($conn, "INSERT INTO users (full_name, email, role, password_hash, phone, email_verified) VALUES ('$full_name', '$email', '$role', '$password_hash', '$phone', 1)")) {
          if (session_status() == PHP_SESSION_NONE) {
            session_start();
          }
          $_SESSION['success_message'] = "Staff account created successfully!<br><br><strong>Login Credentials:</strong><br>Email: " . htmlspecialchars($email) . "<br>Password: " . htmlspecialchars($password) . "<br><br>✅ Email verified status set to active.<br>✅ Account is ready for immediate use.";
          $newUserId = mysqli_insert_id($conn);
          // Log activity: New user account created
          $admin_id = $_SESSION['user_id'] ?? null;
          logActivity($conn, 'new_user_account', "New User Account Created: {$full_name} ({$email}) - Role: {$role}", $admin_id, $newUserId, 'user', ['role' => $role, 'email' => $email]);
        }
      } else {
        // For other roles, insert normally (email_verified defaults to 0)
        if (mysqli_query($conn, "INSERT INTO users (full_name, email, role, password_hash) VALUES ('$full_name', '$email', '$role', '$password_hash')")) {
          if (session_status() == PHP_SESSION_NONE) {
            session_start();
          }
          $_SESSION['success_message'] = "User account created successfully!<br><br><strong>Login Credentials:</strong><br>Email: " . htmlspecialchars($email) . "<br>Password: " . htmlspecialchars($password) . "<br><br>⚠️ Email verification required before login.";
          $newUserId = mysqli_insert_id($conn);
          // Log activity: New user account created
          $admin_id = $_SESSION['user_id'] ?? null;
          logActivity($conn, 'new_user_account', "New User Account Created: {$full_name} ({$email}) - Role: {$role}", $admin_id, $newUserId, 'user', ['role' => $role, 'email' => $email]);
        }
      }
      if (!isset($newUserId)) {
        $newUserId = mysqli_insert_id($conn);
      }
    }

    if ($role === 'customer' && isset($newUserId)) {
      mysqli_query($conn, "INSERT INTO members (user_id, phone, address) VALUES ($newUserId, '$phone', '$address')");
    }
  }

  header("Location: user__list.php");
  exit;
}

// ARCHIVE / UNARCHIVE logic
if (isset($_GET['action'], $_GET['id'])) {
  $id = (int) $_GET['id'];

  if ($_GET['action'] == 'archive') {
    mysqli_query($conn, "UPDATE users SET archived = 1 WHERE id = $id");
  } elseif ($_GET['action'] == 'unarchive') {
    mysqli_query($conn, "UPDATE users SET archived = 0 WHERE id = $id");
  }

  // Check if this is a member (customer role) or staff member
  $user_check = mysqli_query($conn, "SELECT role FROM users WHERE id = $id");
  if ($user_check && mysqli_num_rows($user_check) > 0) {
    $user_data = mysqli_fetch_assoc($user_check);
    if ($user_data['role'] === 'customer') {
      header("Location: members__management.php");
    } else {
      header("Location: user__list.php");
    }
  } else {
    header("Location: user__list.php");
  }
  exit;
}
?>
