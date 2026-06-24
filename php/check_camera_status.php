<?php

include '../db.php';

header('Content-Type: application/json');

define('ONLINE_THRESHOLD_SEC', 8);

$result = $conn->query("
    SELECT UNIX_TIMESTAMP(updated_at) AS ts
    FROM   pi_frames
    WHERE  id = 1
    LIMIT  1
");

if (!$result || $result->num_rows === 0) {
    echo json_encode(['status' => 'offline']);
    exit;
}

$row = $result->fetch_assoc();
$age = time() - (int)$row['ts'];

echo json_encode([
    'status' => ($age <= ONLINE_THRESHOLD_SEC) ? 'connected' : 'offline',
    'age'    => $age,
]);