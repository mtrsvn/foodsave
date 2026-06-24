<?php
session_start();
include 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['total_unread' => 0]);
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT COUNT(*) as total_unread FROM notifications WHERE user_id=? AND is_read=0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$count = $stmt->get_result()->fetch_assoc()['total_unread'] ?? 0;

echo json_encode([
    'total_unread' => (int)$count,
    'timestamp' => date('Y-m-d H:i:s')
]);
?>