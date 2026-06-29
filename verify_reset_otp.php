<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $user_otp = $_POST['otp'] ?? '';
    
    $session_otp = $_SESSION['reset_otp'] ?? '';

    if (!empty($user_otp) && (string)$user_otp === (string)$session_otp) {
        
        
        $_SESSION['reset_step'] = 4; 
        
        if (isset($_SESSION['temp_user_id'])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please restart.']);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid OTP']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>