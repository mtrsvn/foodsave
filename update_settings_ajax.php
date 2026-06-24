<?php
ob_start();
session_start();
header('Content-Type: application/json');
include 'db.php';

if (ob_get_length()) ob_clean();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

if (isset($_POST['update_notif_ajax'])) {
    $low_stock_enabled = (int)$_POST['low_stock_on']; 
    $expiry_alert_enabled = (int)$_POST['expiry_on'];
    $push_notif_enabled = isset($_POST['push_on']) ? (int)$_POST['push_on'] : 0;
    $days_before_expiry = (int)$_POST['days'];
    $expired_notif_delay = (int)$_POST['expired_delay']; 
    $low_stock_threshold = (int)$_POST['threshold'];
    
    $notif_interval_hours = isset($_POST['interval']) ? (int)$_POST['interval'] : 0;

    $stmt = $conn->prepare("UPDATE users SET 
        low_stock_enabled = ?, 
        expiry_alert_enabled = ?, 
        push_notif_enabled = ?, 
        days_before_expiry = ?, 
        expired_notif_delay = ?, 
        low_stock_threshold = ?, 
        notif_interval_hours = ? 
        WHERE user_id = ?");
        
    $stmt->bind_param("iiiiiiii", 
        $low_stock_enabled, 
        $expiry_alert_enabled, 
        $push_notif_enabled, 
        $days_before_expiry, 
        $expired_notif_delay, 
        $low_stock_threshold, 
        $notif_interval_hours, 
        $user_id
    );
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

if (isset($_POST['camera_permission'])) {
    $cam = (int)$_POST['camera_permission'];
    $terms = (int)$_POST['terms_agreed'];

    $stmt = $conn->prepare("UPDATE users SET camera_permission = ?, terms_agreed = ? WHERE user_id = ?");
    $stmt->bind_param("iii", $cam, $terms, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

if (isset($_POST['verify_password'])) {
    $password = $_POST['password'];
    $user_id = $_SESSION['user_id'];

    $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if (password_verify($password, $result['password'])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}
?>