<?php
// 1. Laging unahin ang session_start()
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Check kung logged in ang user
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// 3. Kunin ang success message KUNG MERON (at i-clear agad para hindi na lumitaw ulit)
$message = '';
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>