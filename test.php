<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$mail = new PHPMailer(true);

try {

    $mail->isSMTP();
    $mail->Host = 'smtp.hostinger.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'marikina@foodsave.shop';
    $mail->Password = 'Adminseven@7';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;

    $mail->setFrom('marikina@foodsave.shop', 'FoodSave Test');
    $mail->addAddress('villamorjulianamayhan@gmail.com');

    $mail->Subject = 'TEST EMAIL';
    $mail->Body = 'If you receive this, SMTP is working.';

    $mail->send();

    echo "TEST EMAIL SENT";

} catch (Exception $e) {
    echo "ERROR: " . $mail->ErrorInfo;
}
?>