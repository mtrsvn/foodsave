<?php
session_start();
header('Content-Type: application/json');

// Siguraduhin na POST request ang natatanggap
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Kunin ang OTP galing sa fetch/JavaScript
    $user_otp = $_POST['otp'] ?? '';
    
    // Kunin ang OTP na sinave sa session noong nag-email tayo
    $session_otp = $_SESSION['reset_otp'] ?? '';

    // 1. I-check kung empty ba o hindi match
    if (!empty($user_otp) && (string)$user_otp === (string)$session_otp) {
        
        // 2. MAG-SET NG SECURITY FLAG
        // Ito ay para sa reset-password.php, siguradong verified ang user.
        $_SESSION['reset_step'] = 4; 
        
        // 3. SIGURADUHIN NA NANDITO PA RIN ANG temp_user_id
        // (Optional: Pwede mo rin itong i-check para safe)
        if (isset($_SESSION['temp_user_id'])) {
            echo json_encode(['success' => true]);
        } else {
            // Kung biglang nawala ang session ng user ID
            echo json_encode(['success' => false, 'message' => 'Session expired. Please restart.']);
        }
        
    } else {
        // Kapag mali ang OTP na tinype
        echo json_encode(['success' => false, 'message' => 'Invalid OTP']);
    }
} else {
    // Kapag sinubukang i-access ang file nang hindi POST
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>