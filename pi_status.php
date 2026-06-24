<?php
/**
 * pi_status.php
 * Returns Pi online/offline status based on the last frame stored in the DB.
 */

include 'db.php';

header('Content-Type: application/json');

define('ONLINE_THRESHOLD_SEC', 8);

// Also read registration info if present
$reg_file = sys_get_temp_dir() . '/foodsave_pi_registration.json';
$reg = file_exists($reg_file)
    ? json_decode(file_get_contents($reg_file), true)
    : [];

// Get latest frame timestamp from DB
$result = $conn->query("
    SELECT UNIX_TIMESTAMP(updated_at) AS ts
    FROM   pi_frames
    WHERE  id = 1
    LIMIT  1
");

if (!$result || $result->num_rows === 0) {
    echo json_encode([
        'online'       => false,
        'age_seconds'  => 9999,
        'last_seen'    => 'never',
        'pi_ip'        => $reg['ip']       ?? 'unknown',
        'pi_hostname'  => $reg['hostname'] ?? 'unknown',
    ]);
    exit;
}

$row        = $result->fetch_assoc();
$age        = time() - (int)$row['ts'];
$online     = ($age <= ONLINE_THRESHOLD_SEC);
$last_seen  = ($row['ts'] > 0) ? date('H:i:s', (int)$row['ts']) : 'never';

echo json_encode([
    'online'      => $online,
    'age_seconds' => $age,
    'last_seen'   => $last_seen,
    'pi_ip'       => $reg['ip']       ?? 'unknown',
    'pi_hostname' => $reg['hostname'] ?? 'unknown',
]);