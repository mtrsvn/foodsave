<?php
session_start();
include 'db.php';
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

$msg_list = []; 
$msg_type = "";
$show_modal = false; 

$branch = $_POST['branch'] ?? '';
$email = $_POST['email'] ?? '';
$number = $_POST['number'] ?? '';
$password_val = $_POST['password'] ?? '';        
$confirm_val = $_POST['confirm_password'] ?? ''; 
$otp_val = $_POST['otp'] ?? '';

$errors = [
    'otp' => false, 'password' => false, 'confirm' => false,
    'email_exists' => false, 'branch_exists' => false, 'number_exists' => false, 'number_format' => false
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_otp = $_POST['otp'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $password_regex = "/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,16}$/";
    $phone_regex = "/^(\+639|09)\d{9}$/";

    if (!isset($_SESSION['otp']) || $user_otp != $_SESSION['otp']) {
        $msg_list[] = "Incorrect Verification Code!";
        $errors['otp'] = true;
    }

    $checkBranch = $conn->prepare("SELECT branch_name FROM users WHERE branch_name = ? LIMIT 1");
    $checkBranch->bind_param("s", $branch);
    $checkBranch->execute();
    if ($checkBranch->get_result()->num_rows > 0) {
        $msg_list[] = "Branch name is already taken.";
        $errors['branch_exists'] = true;
    }

    $checkEmail = $conn->prepare("SELECT email FROM users WHERE email = ? LIMIT 1");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    if ($checkEmail->get_result()->num_rows > 0) {
        $msg_list[] = "Email is already registered.";
        $errors['email_exists'] = true;
    }

    if (!preg_match($phone_regex, $number)) {
        $msg_list[] = "Invalid PH Number format.";
        $errors['number_format'] = true;
    } else {
        $checkNum = $conn->prepare("SELECT number FROM users WHERE number = ? LIMIT 1");
        $checkNum->bind_param("s", $number);
        $checkNum->execute();
        if ($checkNum->get_result()->num_rows > 0) {
            $msg_list[] = "Phone number is already in use.";
            $errors['number_exists'] = true;
        }
    }

    if (!preg_match($password_regex, $password)) {
        $msg_list[] = "Password does not meet requirements.";
        $errors['password'] = true;
    }
    if ($password !== $confirm_password) {
        $msg_list[] = "Passwords do not match.";
        $errors['confirm'] = true;
    }
    
    if (empty($msg_list)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO users (branch_name, email, number, password) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $branch, $email, $number, $hashed_password);
        
        if ($stmt->execute()) {
            $new_user_id = $stmt->insert_id; 

            if ($new_user_id > 0) {
                unset($_SESSION['otp']);

                $_SESSION['user_id'] = $new_user_id;
                $_SESSION['branch_name'] = $branch;
                $_SESSION['user_email'] = $email;

                $show_modal = true; 
                $branch = $email = $number = $password_val = $confirm_val = ""; 
            } else {
                $msg_list[] = "Error: System failed to generate a Unique User ID. Please check database Auto-Increment.";
                $msg_type = "error";
            }
        } else {
            $msg_list[] = "Database Error: " . $stmt->error;
            $msg_type = "error";
        }
    }
    if ($errors['email_exists'] || $errors['otp']) {
        $otp_val = "";
        $errors['otp'] = true; 
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - FoodSave</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --primary-green: #8dae84;
        --bg-color: #8dae84;
        --error-red: #d93025;
    }

    body {
        margin: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        background-color: var(--bg-color);
        font-family: 'Segoe UI', sans-serif;
        padding: 20px;
    }

    .auth-card {
        background: white;
        padding: 40px;
        border-radius: 40px;
        width: 100%;
        max-width: 420px;
        text-align: center;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    }

    .auth-input {
        width: 100%;
        padding: 16px;
        margin-bottom: 12px;
        border: 2px solid transparent;
        border-radius: 15px;
        background: #f4f7f4;
        outline: none;
        font-size: 0.9rem;
        box-sizing: border-box;
        transition: 0.3s;
    }

    .input-error {
        border-color: var(--error-red) !important;
        background: #fff5f5 !important;
    }

    .error-hint {
        color: var(--error-red);
        font-size: 0.65rem;
        display: block;
        text-align: left;
        margin: -10px 0 10px 10px;
        font-weight: 600;
    }

    .info-text {
        color: #888;
        font-size: 0.65rem;
        display: block;
        text-align: left;
        margin: -8px 0 12px 10px;
        line-height: 1.2;
    }

    .otp-container {
        display: flex;
        gap: 10px;
        margin-bottom: 12px;
    }

    .btn-send {
        background: #d1dbcd;
        color: #5a6e54;
        border: none;
        padding: 0 15px;
        border-radius: 15px;
        font-weight: 700;
        cursor: pointer;
        font-size: 0.75rem;
        white-space: nowrap;
    }

    .btn-auth {
        width: 100%;
        padding: 16px;
        background: var(--primary-green);
        color: white;
        border: none;
        border-radius: 15px;
        font-weight: 700;
        cursor: pointer;
        margin-top: 10px;
    }

    .msg-box {
        padding: 12px;
        border-radius: 12px;
        font-size: 0.8rem;
        margin-bottom: 20px;
        font-weight: 600;
        text-align: left;
    }

    .password-wrapper {
        position: relative;
        width: 100%;
    }

    .password-wrapper .auth-input {
        padding-right: 45px;
    }

    .toggle-password {
        position: absolute;
        right: 15px;
        top: 22px;
        transform: translateY(-50%);
        cursor: pointer;
        color: #888;
        z-index: 10;
        font-size: 0.9rem;
    }

    .toggle-password:hover {
        color: var(--primary-green);
    }
    </style>
</head>

<body>
    <div class="auth-card">
        <h2>Create Account</h2>
        <p style="color:#777; margin-bottom:25px; font-size:0.95rem;">Join FoodSave today</p>

        <?php if(!empty($msg_list)): ?>
        <div class="msg-box"
            style="background: <?= ($msg_type == 'success') ? '#e6f4ea' : '#fce8e6' ?>; color: <?= ($msg_type == 'success') ? '#1e7e34' : '#d93025' ?>;">
            <ul style="margin:0; padding-left:15px;"><?php foreach($msg_list as $m) echo "<li>$m</li>"; ?></ul>
        </div>
        <?php endif; ?>

        <form action="signup" method="POST">
            <input type="hidden" id="email" name="email" value="<?= htmlspecialchars($email) ?>">

            <input type="text" name="branch" class="auth-input <?= $errors['branch_exists'] ? 'input-error' : '' ?>"
                placeholder="Branch Name" value="<?= htmlspecialchars($branch) ?>" required>

            <div class="otp-container">
                <input type="email" id="email_field"
                    class="auth-input <?= $errors['email_exists'] ? 'input-error' : '' ?>" placeholder="Email Address"
                    value="<?= htmlspecialchars($email) ?>">
                <button type="button" class="btn-send" onclick="sendOTP()">SEND CODE</button>
            </div>

            <input type="text" name="otp" class="auth-input <?= $errors['otp'] ? 'input-error' : '' ?>"
                placeholder="Verification Code" value="<?= htmlspecialchars($otp_val) ?>" required>

            <input type="text" name="number"
                class="auth-input <?= ($errors['number_exists'] || $errors['number_format']) ? 'input-error' : '' ?>"
                placeholder="Phone Number" value="<?= htmlspecialchars($number) ?>"
                oninput="this.value = this.value.replace(/[^0-9+]/g, '')" required>

            <div class="password-wrapper">
                <input type="password" name="password" id="password"
                    class="auth-input <?= $errors['password'] ? 'input-error' : '' ?>" placeholder="Password"
                    value="<?= htmlspecialchars($password_val) ?>" required>
                <i class="fas fa-eye toggle-password" onclick="toggleVisibility('password', this)"></i>
            </div>
            <span class="info-text">8-16 chars, 1 Uppercase, 1 Lowercase, 1 Number, 1 Special Char.</span>

            <div class="password-wrapper">
                <input type="password" name="confirm_password" id="confirm_password"
                    class="auth-input <?= $errors['confirm'] ? 'input-error' : '' ?>" placeholder="Confirm Password"
                    value="<?= htmlspecialchars($confirm_val) ?>" required>
                <i class="fas fa-eye toggle-password" onclick="toggleVisibility('confirm_password', this)"></i>
            </div>

            <button type="submit" class="btn-auth">Sign Up</button>
        </form>
        <p style="font-size: 0.9rem; margin-top:20px; color:#777;">Already have an account? <a href="index"
                style="color:var(--primary-green); font-weight:700; text-decoration:none;">Login</a></p>
    </div>

    <div id="customAlertModal"
        style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); justify-content: center; align-items: center; z-index: 10000; backdrop-filter: blur(5px);">
        <div
            style="background: white; padding: 30px; border-radius: 25px; width: 320px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-top: 5px solid #8dae84;">
            <i id="alertIcon" class="fas fa-check-circle"
                style="font-size: 3.5rem; color: #8dae84; margin-bottom: 15px;"></i>
            <h3 id="alertTitle" style="margin: 10px 0; color: #334155;">Success!</h3>
            <p id="alertMessage" style="color: #64748b; font-size: 0.9rem; line-height: 1.5; margin-bottom: 20px;"></p>
            <button onclick="closeCustomAlert()"
                style="width: 100%; padding: 12px; background: #8dae84; color: white; border: none; border-radius: 12px; font-weight: 700; cursor: pointer;">OK</button>
        </div>
    </div>

    <script>
    function showAlert(message, type = 'success') {
        const modal = document.getElementById('customAlertModal');
        const icon = document.getElementById('alertIcon');
        const title = document.getElementById('alertTitle');
        const msg = document.getElementById('alertMessage');
        const btn = modal.querySelector('button');
        const card = modal.querySelector('div');

        if (type === 'error') {
            card.style.borderTopColor = '#d93025';
            icon.className = 'fas fa-times-circle';
            icon.style.color = '#d93025';
            title.innerText = 'Oops!';
            btn.style.background = '#d93025';
        } else {
            card.style.borderTopColor = '#8dae84';
            icon.className = 'fas fa-check-circle';
            icon.style.color = '#8dae84';
            title.innerText = 'Verification';
            btn.style.background = '#8dae84';
        }

        msg.innerText = message;
        modal.style.display = 'flex';
    }

    function closeCustomAlert() {
        document.getElementById('customAlertModal').style.display = 'none';
    }

    function sendOTP() {
        const btn = document.querySelector('.btn-send');
        const emailField = document.getElementById('email_field');
        const emailVal = emailField.value.trim();

        if (!emailVal) {
            showAlert("Please enter your email address first.", "error");
            return;
        }

        btn.disabled = true;
        btn.style.opacity = "0.6";
        document.getElementById('email').value = emailVal;

        fetch('send_otp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'email=' + encodeURIComponent(emailVal)
            })
            .then(res => res.text())
            .then(data => {
                showAlert(data, data.includes("sent") ? "success" : "error");

                let timeLeft = 60;
                const timer = setInterval(() => {
                    if (timeLeft <= 0) {
                        clearInterval(timer);
                        btn.innerText = "SEND CODE";
                        btn.disabled = false;
                        btn.style.opacity = "1";
                    } else {
                        btn.innerText = `RESEND IN ${timeLeft}s`;
                        timeLeft--;
                    }
                }, 1000);
            })
            .catch(err => {
                btn.disabled = false;
                btn.style.opacity = "1";
                showAlert("Failed to send code. Please try again.", "error");
            });
    }

    function toggleVisibility(inputId, icon) {
        const input = document.getElementById(inputId);

        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
        } else {
            input.type = "password";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
        }
    }
    </script>

    <?php if(isset($show_modal) && $show_modal): ?>
    <div id="tosModal"
        style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: flex; justify-content: center; align-items: center; z-index: 9999; backdrop-filter: blur(5px);">
        <div
            style="background: white; padding: 30px; border-radius: 25px; width: 90%; max-width: 500px; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.2); position: relative;">

            <div style="text-align: center; margin-bottom: 20px;">
                <i class="fas fa-file-shield" style="font-size: 3rem; color: #8dae84;"></i>
                <h2 style="color: #5a6e54; margin-top: 10px;">Welcome to FoodSave!</h2>
            </div>

            <div style="font-size: 0.9rem; color: #4b5563; line-height: 1.6;">
                <h4 style="color: #1f2937; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">
                    📄 PRIVACY POLICY</h4>
                <p>FoodSave respects your privacy and is committed to protecting your personal data. We collect limited
                    information such as email and branch name for account management.</p>
                <p><strong>Camera Usage:</strong> The system uses a Raspberry Pi Camera to scan product labels (OCR).
                    Images are processed in real-time and are <strong>not stored</strong> on our servers.</p>

                <h4
                    style="color: #1f2937; margin-top: 20px; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">
                    📜 TERMS AND CONDITIONS</h4>
                <ul style="padding-left: 15px; margin-bottom: 20px;">
                    <li>System is for food inventory and expiration tracking only.</li>
                    <li>Users are responsible for data accuracy and hardware handling.</li>
                    <li>Camera integration must be used only within the authorized branch.</li>
                    <li>We are not liable for hardware damage or poor OCR results due to low image quality.</li>
                </ul>
            </div>

            <div style="margin-top: 25px;">
                <button onclick="acceptAndGo()"
                    style="width: 100%; padding: 15px; background: #8dae84; color: white; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 1rem; transition: 0.3s;">
                    I AGREE AND PROCEED
                </button>
            </div>
        </div>
    </div>

    <script>
    function acceptAndGo() {
        fetch('update_settings_ajax', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'camera_permission=0&terms_agreed=1'
        }).then(() => {
            window.location.href = 'setting';
        });
    }
    </script>
    <?php endif; ?>

</body>

</html>