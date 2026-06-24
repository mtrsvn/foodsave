<?php
session_start();
header('Content-Type: application/json');

$input_otp = $_POST['otp'] ?? '';
$verified_email = $_POST['email'] ?? ''; 

// 1. I-check kung may session at kung tama ang code
if (isset($_SESSION['otp']) && $input_otp == $_SESSION['otp']) {
    
    // 2. Siguraduhin na may email na pinasa para sa verification
    if (!empty($verified_email)) {
        $_SESSION['email_verified'] = $verified_email;
        unset($_SESSION['otp']); // Burahin ang OTP pagkatapos magamit
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Email is missing in request.']);
    }

} else {
    // 3. IMPORTANT: Response kapag mali ang OTP
    echo json_encode(['success' => false, 'message' => 'Invalid OTP code.']);
}
?>