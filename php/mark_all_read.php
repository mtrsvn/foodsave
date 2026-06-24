<?php
include '../db.php'; 

session_start();

if (!isset($_SESSION['user_id'])) {
    echo "error_auth";
    exit();
}

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);

if ($stmt->execute()) {
    echo "success";
} else {
    echo "error_db";
}

$stmt->close();
$conn->close();
?>