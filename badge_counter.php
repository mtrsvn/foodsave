<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    $count_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
    $stmt = $conn->prepare($count_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unread_count = $stmt->get_result()->fetch_assoc()['unread'];
    
    echo json_encode([
        'success' => true,
        'total_unread' => (int)$unread_count
    ]);
} else {
    echo json_encode([
        'success' => false,
        'total_unread' => 0
    ]);
}
?>