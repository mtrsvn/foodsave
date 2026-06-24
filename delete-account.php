<?php
include 'db.php';
session_start();
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

if (isset($_POST['confirm_delete'])) {
    $user_id = $_SESSION['user_id'];

    $sql = "DELETE FROM users WHERE user_id = '$user_id'";
    
    if ($conn->query($sql) === TRUE) {
        session_destroy();
        header("Location: index.php");
        exit();
    } else {
        echo "Error deleting account: " . $conn->error;
    }
}
?>