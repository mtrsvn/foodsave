<?php
include 'db.php';
session_start();
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

if (!isset($_SESSION['temp_user_id'])) {
    header("Location: index");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_pass = $_POST['new_password'];
    $conf_pass = $_POST['confirm_password'];

    $password_regex = "/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,16}$/";

    if ($new_pass !== $conf_pass) {
        $error = "Passwords do not match!";
    } elseif (!preg_match($password_regex, $new_pass)) {
        $error = "Password must be 8-16 chars, include Uppercase, Lowercase, Number, and Special Character.";
    } else {
        $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $hashed_password, $_SESSION['temp_user_id']);
        
        if ($stmt->execute()) {
            session_unset();
            session_destroy(); 
            echo "<script>alert('Password updated successfully! Please log in with your new password.'); window.location.href='index.php';</script>";
            exit();
        } else {
            $error = "Something went wrong. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FoodSave - New Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-green: #8dae84; --bg-color: #8dae84; }
        body { margin: 0; display: flex; justify-content: center; align-items: center; height: 100vh; background: var(--bg-color); font-family: 'Segoe UI', Tahoma, sans-serif; }
        .auth-card { background: white; padding: 40px; border-radius: 40px; width: 100%; max-width: 400px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .auth-input { width: 100%; padding: 18px; margin-bottom: 15px; border-radius: 15px; border: 2px solid #f4f7f4; background: #f4f7f4; box-sizing:border-box; font-size: 1rem; outline: none; transition: 0.3s; }
        .auth-input:focus { border-color: var(--primary-green); background: white; }
        .btn-auth { width: 100%; padding: 18px; background: var(--primary-green); color: white; border: none; border-radius: 15px; font-weight: 700; cursor: pointer; font-size: 1rem; transition: 0.3s; }
        .btn-auth:hover { background: #7a9a72; transform: translateY(-1px); }
        .error-msg { color: #d93025; background: #fce8e6; padding: 12px; border-radius: 10px; margin-bottom: 20px; font-size: 0.85rem; font-weight: 600; border: 1px solid #fad2cf; }
    </style>
</head>
<body>
    <div class="auth-card">
        <h2 style="color: #5a6e54; margin-bottom: 10px;">Set New Password</h2>
        <p style="color: #777; margin-bottom: 25px; font-size: 0.9rem;">
            Please create a strong password to secure your account.
        </p>

        <?php if($error): ?> 
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div> 
        <?php endif; ?>

        <form method="POST">
            <input type="password" name="new_password" class="auth-input" placeholder="New Password" required autofocus>
            <input type="password" name="confirm_password" class="auth-input" placeholder="Confirm New Password" required>
            
            <ul style="text-align: left; color: #777; font-size: 0.75rem; margin-bottom: 20px; padding-left: 20px;">
                <li>8-16 characters long</li>
                <li>At least one uppercase & lowercase letter</li>
                <li>At least one number & special character (@$!%*?&)</li>
            </ul>

            <button type="submit" class="btn-auth">Update Password</button>
        </form>
    </div>
</body>
</html>