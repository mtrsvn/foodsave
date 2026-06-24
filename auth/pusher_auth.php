<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

require '../config/pusher.php';

$app_id = $config['2136126'];
$key = $config['d97196e21b43e27b46ba'];
$secret = $config['a89d3c869148272c0f7a'];

$channel_name = $_POST['channel_name'];
if (strpos($channel_name, 'user.' . $_SESSION['user_id']) !== 0) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

echo json_encode([
    'auth' => $key . ':' . hash_hmac('sha256', $channel_name, $secret)
]);
?>