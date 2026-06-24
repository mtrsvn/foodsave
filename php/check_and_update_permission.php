<?php
session_start();
include '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'get';

if ($action === 'get') {
    $stmt = $conn->prepare("SELECT camera_permission FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true, 
        'camera_permission' => (int)($result['camera_permission'] ?? 0)
    ]);
    $stmt->close();
} 

elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $permission = isset($_POST['camera_permission']) ? (int)$_POST['camera_permission'] : 0;

    $stmt = $conn->prepare("UPDATE users SET camera_permission = ? WHERE user_id = ?");
    $stmt->bind_param("ii", $permission, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'camera_permission' => $permission
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to update database tracking flag.'
        ]);
    }
    $stmt->close();
}

else {
    echo json_encode(['success' => false, 'message' => 'Invalid action or method']);
}

$conn->close();
?>