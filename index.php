<?php
include 'db.php';
session_start();
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

if (isset($_SESSION['user_id'])) {
    header("Location: inventory");
    exit();
}

$error = "";
$success_msg = "";

if (isset($_GET['signup']) && $_GET['signup'] == 'success') {
    $success_msg = "Account created successfully! You can now log in.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            
            $_SESSION['branch_name'] = $user['branch_name']; 
            
            $_SESSION['user_email'] = $user['email'];
            
            header("Location: inventory");
            exit();
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "No account found with that email.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FoodSave - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --primary-green: #8dae84;
        --bg-color: #8dae84;
        --dark-green: #5a6e54;
        --text-grey: #777;
        --error-red: #d93025;
    }

    body {
        margin: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        background-color: var(--bg-color);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .auth-card {
        background: white;
        padding: 50px 40px;
        border-radius: 40px;
        width: 100%;
        max-width: 420px;
        text-align: center;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
    }

    .brand-icon {
        width: 70px;
        height: 70px;
        background: #f4f7f4;
        color: var(--primary-green);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        border-radius: 20px;
        margin: 0 auto 25px;
    }

    h2 {
        color: var(--dark-green);
        margin-bottom: 8px;
        font-size: 2rem;
        font-weight: 800;
    }

    .subtitle {
        color: var(--text-grey);
        margin-bottom: 35px;
        font-size: 1rem;
    }

    .auth-input {
        width: 100%;
        padding: 18px;
        margin-bottom: 15px;
        border: 2px solid transparent;
        border-radius: 15px;
        background: #f4f7f4;
        outline: none;
        font-size: 1rem;
        box-sizing: border-box;
        transition: 0.3s;
    }

    .auth-input:focus {
        border-color: var(--primary-green);
        background: white;
        box-shadow: 0 0 10px rgba(151, 171, 141, 0.2);
    }

    .btn-auth {
        width: 100%;
        padding: 18px;
        background: var(--primary-green);
        color: white;
        border: none;
        border-radius: 15px;
        font-weight: 700;
        font-size: 1.1rem;
        cursor: pointer;
        margin-top: 10px;
        transition: 0.3s;
    }
    
    /* Password Toggle Styles */
    .password-wrapper {
        position: relative;
        width: 100%;
    }

    .password-wrapper .auth-input {
        padding-right: 50px;
    }

    .toggle-password {
        position: absolute;
        right: 18px;
        top: 26px;
        transform: translateY(-50%);
        cursor: pointer;
        color: var(--text-grey);
        z-index: 10;
        transition: 0.2s;
    }

    .toggle-password:hover {
        color: var(--primary-green);
    }

    .btn-auth:hover {
        background: #869a7c;
        transform: translateY(-2px);
    }

    .error-msg {
        color: var(--error-red);
        background: #fce8e6;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 0.9rem;
        font-weight: 600;
        border: 1px solid #fad2cf;
    }

    .signup-link {
        margin-top: 30px;
        font-size: 0.95rem;
        color: var(--text-grey);
    }

    .signup-link a {
        color: var(--primary-green);
        font-weight: 700;
        text-decoration: none;
    }

    .signup-link a:hover {
        text-decoration: underline;
    }
    </style>
</head>

<body>
    <div class="auth-card">
        <div class="brand-icon">
            <i class="fas fa-shopping-cart"></i>
        </div>

        <h2>Welcome to FoodSave</h2>
        <p class="subtitle">Please enter your details</p>


        <?php if($error): ?>
        <div class="error-msg">
            <i class="fas fa-exclamation-circle"></i>
            <?php 
            if(trim($error) == "Invalid password.") {
                echo "Invalid password. Have you forgotten your password? <a href='forgot-password.php' style='color:inherit; text-decoration:underline;'>Click Forgot Password</a>";
            } else {
                echo $error;
            }
        ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="email" name="email" class="auth-input" placeholder="Email Address" required>
            <div class="password-wrapper">
                <input type="password" name="password" id="login_pass" class="auth-input" placeholder="Password" required>
                <i class="fas fa-eye toggle-password" onclick="toggleVisibility('login_pass', this)"></i>
            </div>
            <button type="submit" class="btn-auth">Sign In</button>
        </form>

        <p class="signup-link">
            New here? <a href="signup.php">Create Account</a><br>
            <a href="forgot-password.php"
                style="font-size: 0.85rem; display: inline-block; margin-top: 10px; color: #777;">Forgot Password?</a>
        </p>
    </div>
    
    <script>
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
    
</body>

</html>