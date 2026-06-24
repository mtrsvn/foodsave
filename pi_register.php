<?php
/**
 * pi_register.php
 * Called by the Pi on startup to register its IP/hostname.
 * Stores info in a temp JSON file (non-critical, informational only).
 */

header('Content-Type: application/json');

define('FRAME_TOKEN', 'foodsave_token_very_long');

$token    = $_POST['token']    ?? '';
$ip       = $_POST['ip']       ?? '';
$port     = $_POST['port']     ?? '5000';
$hostname = $_POST['hostname'] ?? '';

if ($token !== FRAME_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$reg_file = sys_get_temp_dir() . '/foodsave_pi_registration.json';
$data = [
    'ip'            => $ip,
    'port'          => $port,
    'hostname'      => $hostname,
    'registered_at' => time(),
];

@file_put_contents($reg_file, json_encode($data));

echo json_encode([
    'success' => true,
    'message' => "Pi registered: {$hostname} ({$ip}:{$port})",
]);