<?php
include 'db.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$current_page = "forgot-password"; 

// FORCE RESET: Kapag may ?new=1 o kapag walang email na naka-save sa session
if (isset($_GET['new']) || !isset($_SESSION['temp_identifier'])) {
    $_SESSION['reset_step'] = 2; // Balik sa Email Input
    unset($_SESSION['temp_user_id'], $_SESSION['reset_otp'], $_SESSION['temp_identifier']);
}

$step = $_SESSION['reset_step'] ?? 2;
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['send_otp'])) {
        $identifier = mysqli_real_escape_string($conn, $_POST['identifier']);
        
        $stmt = $conn->prepare("SELECT user_id, email FROM users WHERE email = ?");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $_SESSION['temp_user_id'] = $user['user_id'];
            $_SESSION['temp_identifier'] = $identifier; 
            $target_email = $user['email'];
            $otp = rand(100000, 999999);
            $_SESSION['reset_otp'] = $otp;

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.hostinger.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'marikina@foodsave.shop';
                $mail->Password   = 'Adminseven@7'; 
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = 465;
                $mail->setFrom('marikina@foodsave.shop', 'FoodSave Security');
                $mail->addAddress($target_email);
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Code';
                $mail->Body    = "<h1>$otp</h1>";
                $mail->send();

                $_SESSION['reset_step'] = 3; // Ngayon pa lang tayo lilipat sa boxes
                header("Location: " . $current_page);
                exit();
            } catch (Exception $e) {
                $error = "Mailer Error: " . $mail->ErrorInfo;
            }
        } else {
            $error = "Account not found.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FoodSave - Forgot Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --primary-green: #8dae84;
        --bg-color: #8dae84;
    }
    body {
        margin: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        background: var(--bg-color);
        font-family: 'Segoe UI', sans-serif;
    }
    .auth-card {
        background: white;
        padding: 40px;
        border-radius: 40px;
        width: 100%;
        max-width: 400px;
        text-align: center;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }
    .auth-input {
        width: 100%;
        padding: 18px;
        margin-bottom: 15px;
        border-radius: 15px;
        border: 2px solid #f4f7f4;
        background: #f4f7f4;
        box-sizing: border-box;
        font-size: 1rem;
        outline: none;
    }
    .auth-input:focus {
        border-color: var(--primary-green);
        background: white;
    }
    .btn-auth {
        width: 100%;
        padding: 18px;
        background: var(--primary-green);
        color: white;
        border: none;
        border-radius: 15px;
        font-weight: 700;
        cursor: pointer;
        font-size: 1rem;
        transition: 0.3s;
    }
    .btn-auth:hover { background: #7a9a72; }
    .method-btn {
        width: 100%;
        padding: 20px;
        border: 2px solid #f4f7f4;
        border-radius: 15px;
        cursor: pointer;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 15px;
        font-weight: 600;
        color: #555;
        background: white;
        transition: 0.3s;
        font-size: 1rem;
    }
    .method-btn:hover {
        border-color: var(--primary-green);
        color: var(--primary-green);
        background: #f9fbf9;
    }
    .otp-inputs {
        display: flex;
        gap: 8px;
        justify-content: center;
        margin: 25px 0;
    }
    .otp-box {
        width: 45px;
        height: 55px;
        text-align: center;
        font-size: 1.5rem;
        font-weight: bold;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        background: #f8fafc;
        outline: none;
        transition: all 0.2s ease;
    }
    .otp-box:focus { border-color: var(--primary-green); }
    .error-msg {
        color: #d93025;
        background: #fce8e6;
        padding: 12px;
        border-radius: 10px;
        margin-bottom: 15px;
        font-size: 0.85rem;
        font-weight: 600;
        border: 1px solid #fad2cf;
    }
    .resend-container {
        margin-top: 20px;
        font-size: 0.85rem;
        color: #666;
    }
    #resendBtn {
        background: none;
        border: none;
        color: var(--primary-green);
        font-weight: 700;
        cursor: pointer;
        text-decoration: underline;
        padding: 0;
        display: none;
    }
    #resendBtn:disabled { color: #ccc; cursor: not-allowed; }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        50% { transform: translateX(5px); }
        75% { transform: translateX(-5px); }
    }
    .otp-box.invalid {
        border-color: #ef4444 !important;
        background-color: #fce8e6 !important;
        animation: shake 0.2s ease-in-out 0s 2;
    }
    </style>
</head>
<body>
    <div class="auth-card">
    <h2 style="color: #5a6e54; margin-bottom: 10px;">Reset Password</h2>
    
    <?php if($error): ?> 
        <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
    <?php endif; ?>

    <?php if($step == 2): ?>
        <p style="color: #777; margin-bottom: 20px;">Enter your registered Email</p>
        <form method="POST">
            <input type="email" name="identifier" class="auth-input" placeholder="Email Address" required autofocus>
            <button type="submit" name="send_otp" class="btn-auth">Send Code</button>
        </form>

    <?php elseif($step == 3): ?>
        <p style="color: #777;">Enter the 6-digit code sent to your email</p>
        <div class="otp-inputs">
            <?php for($i=1; $i<=6; $i++): ?>
                <input type="text" class="otp-box" id="otp-<?= $i ?>" maxlength="1" oninput="moveNext(this, <?= $i ?>)">
            <?php endfor; ?>
        </div>
        <button onclick="verifyOTP()" class="btn-auth">Verify OTP</button>
        <div class="resend-container">
            <span id="timerText">Resend code in 60s</span>
            <button type="button" id="resendBtn" onclick="resendOTP()">Resend OTP</button>
        </div>
        <p style="margin-top:15px;">
    <a href="forgot-password?new=1" style="color:#999; font-size:0.85rem; text-decoration:none;">
        <i class="fas fa-undo"></i> Use a different email
    </a>
</p>
    <?php endif; ?>

    <p style="margin-top: 25px;">
    <a href="index" onclick="<?php unset($_SESSION['reset_step']); ?>" style="color: var(--primary-green); text-decoration:none; font-weight:700;">
        Back to Login
    </a>
</p>
</div>

    <script>
    function moveNext(curr, i) {
        if (curr.value.length === 1 && i < 6) {
            document.getElementById('otp-' + (i + 1)).focus();
        }
    }
    function verifyOTP() {
        let code = "";
        const boxes = [];
        for (let i = 1; i <= 6; i++) {
            const box = document.getElementById('otp-' + i);
            boxes.push(box);
            code += box.value;
        }
        if (code.length < 6) {
            alert("Please enter all 6 digits.");
            return;
        }
        fetch('verify_reset_otp', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'otp=' + encodeURIComponent(code)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'reset-password';
            } else {
                boxes.forEach((box, index) => {
                    setTimeout(() => { box.classList.add('invalid'); }, index * 50);
                });
                setTimeout(() => {
                    boxes.forEach(box => {
                        box.classList.remove('invalid');
                        box.value = "";
                    });
                    boxes[0].focus();
                }, 800);
            }
        })
        .catch(err => {
            console.error("Error:", err);
            alert("Something went wrong. Please try again.");
        });
    }
    let countdown;
    function startTimer() {
        let seconds = 60;
        const timerText = document.getElementById('timerText');
        const resendBtn = document.getElementById('resendBtn');
        if (!timerText || !resendBtn) return;
        resendBtn.style.display = "none";
        timerText.style.display = "inline";
        clearInterval(countdown);
        countdown = setInterval(() => {
            seconds--;
            timerText.innerText = "Resend code in " + seconds + "s";
            if (seconds <= 0) {
                clearInterval(countdown);
                timerText.style.display = "none";
                resendBtn.style.display = "inline";
            }
        }, 1000);
    }
    function resendOTP() {
        const resendBtn = document.getElementById('resendBtn');
        resendBtn.disabled = true;
        resendBtn.innerText = "Sending...";
        fetch('forgot-password', { // Inalis ang .php
    method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                'send_otp': '1',
                'identifier': '<?= $_SESSION['temp_identifier'] ?? "" ?>'
            })
        })
        .then(() => {
            alert("A new OTP has been sent!");
            resendBtn.innerText = "Resend OTP";
            resendBtn.disabled = false;
            startTimer();
        })
        .catch(err => {
            alert("Failed to resend. Please try again.");
            resendBtn.disabled = false;
        });
    }
    window.onload = function() {
        if (document.getElementById('timerText')) { startTimer(); }
    };
    </script>
</body>
</html>