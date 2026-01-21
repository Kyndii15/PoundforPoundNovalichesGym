<?php
include '../config.php';

echo "<h2>Database Structure Test</h2>";

// Test database connection
echo "<h3>Database Connection</h3>";
if ($conn) {
    echo "✅ Database connection successful<br>";
} else {
    echo "❌ Database connection failed<br>";
    exit;
}

// Check if tables exist
$tables = ['users', 'members', 'subscriptions', 'transactions', 'walk_in_log'];

echo "<h3>Table Existence Check</h3>";
foreach ($tables as $table) {
    $result = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    if (mysqli_num_rows($result) > 0) {
        echo "✅ Table '$table' exists<br>";
    } else {
        echo "❌ Table '$table' does NOT exist<br>";
    }
}

// Check table structures
echo "<h3>Table Structures</h3>";

// Users table
echo "<h4>Users Table Structure:</h4>";
$result = mysqli_query($conn, "DESCRIBE users");
if ($result) {
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "<td>" . $row['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ Could not describe users table: " . mysqli_error($conn) . "<br>";
}

// Members table
echo "<h4>Members Table Structure:</h4>";
$result = mysqli_query($conn, "DESCRIBE members");
if ($result) {
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "<td>" . $row['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ Could not describe members table: " . mysqli_error($conn) . "<br>";
}

// Subscriptions table
echo "<h4>Subscriptions Table Structure:</h4>";
$result = mysqli_query($conn, "DESCRIBE subscriptions");
if ($result) {
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "<td>" . $row['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ Could not describe subscriptions table: " . mysqli_error($conn) . "<br>";
}

// Transactions table
echo "<h4>Transactions Table Structure:</h4>";
$result = mysqli_query($conn, "DESCRIBE transactions");
if ($result) {
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "<td>" . $row['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ Could not describe transactions table: " . mysqli_error($conn) . "<br>";
}

// Walk-in log table
echo "<h4>Walk-in Log Table Structure:</h4>";
$result = mysqli_query($conn, "DESCRIBE walk_in_log");
if ($result) {
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "<td>" . $row['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ Could not describe walk_in_log table: " . mysqli_error($conn) . "<br>";
}

// Test email functionality
echo "<h3>Email Test</h3>";
$test_email = "test@example.com";
$test_result = mail($test_email, "Test Email", "This is a test email from the gym system.");
if ($test_result) {
    echo "✅ Email function is working<br>";
} else {
    echo "❌ Email function is NOT working<br>";
}

echo "<h3>Test Complete</h3>";
?>



















