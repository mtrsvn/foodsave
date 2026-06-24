<?php
include 'db.php';

header('Content-Type: application/json');

$stmt = $conn->prepare("
    UPDATE live_scan
    SET product_name = '', expiry_date = NULL, scan_status = 'USED'
    WHERE id = 1
");

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Live scan cleared'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to clear live scan'
    ]);
}
?>