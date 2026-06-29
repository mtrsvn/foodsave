<?php
include 'db.php';

header('Content-Type: application/json');

// 1. Fetch the current data
$stmt = $conn->prepare("SELECT product_name, expiry_date, scan_status, updated_at FROM live_scan WHERE id = 1 LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'No record found']);
    exit;
}

// 2. Prepare the response
$scan_status = $row['scan_status'];
$product_name = $row['product_name'] ?? '';
$expiry_date = $row['expiry_date'] ?? '';

// Derive a human-readable pipeline stage
if ($scan_status === 'READY') {
    $pipeline = 'complete';
} elseif ($scan_status === 'PARTIAL' && $product_name !== '' && $expiry_date === '') {
    $pipeline = 'front_done';
} elseif ($scan_status === 'PARTIAL' && $expiry_date !== '' && $product_name === '') {
    $pipeline = 'back_done';
} else {
    $pipeline = 'idle';
}

$response = [
    'success' => true,
    'product_name' => $product_name,
    'expiry_date' => $expiry_date,
    'scan_status' => $scan_status,
    'pipeline' => $pipeline,
    'updated_at' => $row['updated_at']
];

// 3. OPTIONAL: Reset status so the UI doesn't "re-trigger" the same scan
// Only do this if your Raspberry Pi is programmed to wait for a reset.
/*
if ($row['scan_status'] === 'READY') {
    $reset = $conn->prepare("UPDATE live_scan SET scan_status = 'CONSUMED' WHERE id = 1");
    $reset->execute();
}
*/

echo json_encode($response);
?>