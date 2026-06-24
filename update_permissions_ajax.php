<?php
include 'db.php';
session_start();

if (isset($_POST['camera_permission'])) {
    $user_id = $_SESSION['user_id'];
    $cam = $_POST['camera_permission'];
    $terms = $_POST['terms_agreed'];

    $stmt = $conn->prepare("UPDATE users SET camera_permission = ?, terms_agreed = ? WHERE id = ?");
    $stmt->bind_param("iii", $cam, $terms, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}