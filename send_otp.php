<?php
session_start();
include 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$type = $_POST['type'] ?? 'signup';
$email = $_POST['email'] ?? '';

if (!empty($email)) {
    
    if ($type === 'change_email') {
        $current_user_id = $_SESSION['user_id'] ?? 0;

        $getCurrent = $conn->prepare("SELECT email FROM users WHERE user_id = ?");
        $getCurrent->bind_param("i", $current_user_id);
        $getCurrent->execute();
        $current_res = $getCurrent->get_result()->fetch_assoc();
        $db_email = $current_res['email'] ?? '';

        if ($email === $db_email) {
            echo "This email is the same as your current one. Please enter a new email address to proceed.";
            exit();
        }

        $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $checkEmail->bind_param("si", $email, $current_user_id);
        $checkEmail->execute();
        $result = $checkEmail->get_result();

        if ($result->num_rows > 0) {
            echo "The email address you entered is already linked to an existing account. Please use a different email.";
            exit();
        }
    }
    
    $otp = rand(100000, 999999);
    $_SESSION['otp'] = $otp;

    if ($type === 'change_email') {
        $subject = 'FoodSave - Confirm New Email Address';
        $title_text = 'Change Email Verification';
        $message_text = 'You requested to change your email address. Use the code below to verify this change:';
    } else {
        $subject = 'Your FoodSave Verification Code';
        $title_text = 'Email Verification';
        $message_text = 'Thank you for signing up with FoodSave. Use the code below to verify your account:';
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'marikina@foodsave.shop'; 
        $mail->Password   = 'Adminseven@7';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        $mail->setFrom('marikina@foodsave.shop', 'FoodSave Verification');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        $mail->Body    = "
            <div style='font-family: sans-serif; text-align: center; padding: 20px; border: 1px solid #eee; border-radius: 20px;'>
                <h2 style='color: #8dae84;'>$title_text</h2>
                <p>$message_text</p>
                <h1 style='background: #f4f7f4; display: inline-block; padding: 10px 20px; border-radius: 10px; letter-spacing: 5px; color: #5a6e54;'>$otp</h1>
                <p style='color: #777; font-size: 0.8rem; margin-top: 20px;'>This code will expire soon. Do not share this with anyone.</p>
            </div>
        ";

        $mail->send();
        echo "Verification code has been sent to your email!";
    } catch (Exception $e) {
        echo "Error: Email could not be sent. Mailer Error: {$mail->ErrorInfo}";
    }

} else {
    echo "Email is required.";
}
?>