<?php
// Debug script to check phone number in database
include '../config.php';

// Start session
session_name('gym_admin_session');
session_start();

// Get user ID from session or from email parameter
$user_id = $_SESSION['user_id'] ?? null;
$check_email = $_GET['email'] ?? 'napolion1@gmail.com';

if (!$user_id) {
    // Try to get user ID from email
    $email_query = mysqli_query($conn, "SELECT id FROM users WHERE email = '$check_email'");
    if ($email_query && $email_row = mysqli_fetch_assoc($email_query)) {
        $user_id = $email_row['id'];
    }
}

if (!$user_id) {
    die("User not found for email: $check_email");
}

echo "<h2>Phone Number Debug for User ID: $user_id</h2>";

// Check if phone column exists
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'phone'");
echo "<h3>1. Phone Column Check:</h3>";
if (mysqli_num_rows($check_column) > 0) {
    $col_info = mysqli_fetch_assoc($check_column);
    echo "<pre>Column exists: " . print_r($col_info, true) . "</pre>";
} else {
    echo "<p style='color: red;'>Phone column does NOT exist!</p>";
}

// Get user data with SELECT *
echo "<h3>2. User Data (SELECT *):</h3>";
$query1 = "SELECT * FROM users WHERE id = $user_id";
$result1 = mysqli_query($conn, $query1);
if ($result1) {
    $data1 = mysqli_fetch_assoc($result1);
    echo "<pre>" . print_r($data1, true) . "</pre>";
    echo "<p><strong>Phone value:</strong> " . var_export($data1['phone'] ?? 'NOT SET', true) . "</p>";
    echo "<p><strong>Phone type:</strong> " . gettype($data1['phone'] ?? null) . "</p>";
    echo "<p><strong>Phone empty:</strong> " . (empty($data1['phone']) ? 'YES' : 'NO') . "</p>";
    echo "<p><strong>Phone isset:</strong> " . (isset($data1['phone']) ? 'YES' : 'NO') . "</p>";
} else {
    echo "<p style='color: red;'>Query failed: " . mysqli_error($conn) . "</p>";
}

// Get user data with explicit phone selection
echo "<h3>3. User Data (Explicit SELECT phone):</h3>";
$query2 = "SELECT id, full_name, email, phone FROM users WHERE id = $user_id";
$result2 = mysqli_query($conn, $query2);
if ($result2) {
    $data2 = mysqli_fetch_assoc($result2);
    echo "<pre>" . print_r($data2, true) . "</pre>";
    echo "<p><strong>Phone value:</strong> " . var_export($data2['phone'] ?? 'NOT SET', true) . "</p>";
} else {
    echo "<p style='color: red;'>Query failed: " . mysqli_error($conn) . "</p>";
}

// Check all columns in users table
echo "<h3>4. All Columns in users table:</h3>";
$columns_query = mysqli_query($conn, "SHOW COLUMNS FROM users");
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
while ($col = mysqli_fetch_assoc($columns_query)) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($col['Extra']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Direct SQL check
echo "<h3>5. Direct SQL Query Result:</h3>";
$query3 = "SELECT id, full_name, email, phone, HEX(phone) as phone_hex FROM users WHERE email = '$check_email'";
$result3 = mysqli_query($conn, $query3);
if ($result3) {
    $data3 = mysqli_fetch_assoc($result3);
    echo "<pre>" . print_r($data3, true) . "</pre>";
} else {
    echo "<p style='color: red;'>Query failed: " . mysqli_error($conn) . "</p>";
}

echo "<hr>";
echo "<p><a href='profile.php'>Back to Profile</a></p>";
?>

