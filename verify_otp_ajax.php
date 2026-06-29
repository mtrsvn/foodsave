<?php
session_start();
header('Content-Type: application/json');

$input_otp = $_POST['otp'] ?? '';
$verified_email = $_POST['email'] ?? ''; 

if (isset($_SESSION['otp']) && $input_otp == $_SESSION['otp']) {
    
    if (!empty($verified_email)) {
        $_SESSION['email_verified'] = $verified_email;
        unset($_SESSION['otp']); 
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Email is missing in request.']);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid OTP code.']);
}
?>