<?php
include '../config.php';

$role = $_GET['role'] ?? '';

// Only allow coach role now
if ($role !== 'coach') {
  echo json_encode([]);
  exit;
}

$query = "SELECT id, full_name FROM users WHERE role = 'coach' AND archived = 0 ORDER BY full_name";
$result = $conn->query($query);

$users = [];
while ($row = $result->fetch_assoc()) {
  $users[] = $row;
}

echo json_encode($users);
?>

