<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'no_session', 'new_alerts' => 0, 'total_unread' => 0]);
    exit();
}

$user_id = $_SESSION['user_id'];

$count_unread = $conn->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
$count_unread->bind_param("i", $user_id);
$count_unread->execute();
$total_unread = $count_unread->get_result()->fetch_assoc()['unread'];

$session_last_check = $_SESSION['last_alert_ui_check'] ?? 0;
$new_since_last = 0;

if ($session_last_check > 0) {
    $count_new = $conn->prepare("SELECT COUNT(*) as new FROM notifications WHERE user_id = ? AND created_at > FROM_UNIXTIME(?) AND is_read = 0");
    $count_new->bind_param("ii", $user_id, $session_last_check);
    $count_new->execute();
    $new_since_last = $count_new->get_result()->fetch_assoc()['new'];
}

$_SESSION['last_alert_ui_check'] = time();
$_SESSION['last_unread_count'] = $total_unread;

if (!isset($_SESSION['last_heavy_check']) || (time() - $_SESSION['last_heavy_check'] > 1800)) {
    $_SESSION['last_heavy_check'] = time();
    $generate_heavy = true;
} else {
    $generate_heavy = false;
}

echo json_encode([
    'status' => 'success',
    'new_alerts' => $new_since_last,
    'total_unread' => $total_unread,
    'heavy_generated' => $generate_heavy ? 'yes' : 'no',
    'message' => $generate_heavy ? 'Heavy generation + UI update' : 'UI update only'
]);
?>
