<?php
session_start();
include 'db.php';

if (isset($_POST['email'])) {
    $email = $_POST['email'];
    $otp = rand(100000, 999999);
    $_SESSION['otp'] = $otp;

    $subject = "Your FoodSave Sign Up Code";
    $message = "Your verification code is: " . $otp . "\nThis code will expire in 10 minutes.";
    $headers = "From: no-reply@foodsave.com";

    if (mail($email, $subject, $message, $headers)) {
        echo "Verification code sent to $email!";
    } else {
        echo "Failed to send email. (Check your server's mail config)";
    }
}
?>