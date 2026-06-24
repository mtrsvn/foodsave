<?php
$servername = "localhost";  
$username = "u381336937_foodsave_db";        
$password = "Adminseven@7";              
$dbname = "u381336937_foodsave_db";      

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

date_default_timezone_set('Asia/Manila');

$conn->query("SET time_zone = '+08:00'");
$conn->set_charset("utf8mb4");
?>