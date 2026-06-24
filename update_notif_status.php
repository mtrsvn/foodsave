<?php
include 'db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo "error";
    exit();
}

$id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

if ($id > 0) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notif_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "success";
        } else {
            echo "no_change";
        }
    } else {
        echo "error";
    }
} else {
    echo "invalid_id";
}
?>