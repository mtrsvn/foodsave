<?php
include 'db.php';
header('Content-Type: application/json');

$expected_token = 'foodsave_token_very_long';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$token        = $_POST['token'] ?? '';
$product_name = trim($_POST['product_name'] ?? '');
$expiry_input = trim($_POST['expiry_date'] ?? '');

// 1. Check Token
if ($token !== $expected_token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Decide what we are updating
if ($product_name !== '' && $expiry_input === '') {
    // Only updating NAME (Front Scan)
    $stmt = $conn->prepare("UPDATE live_scan SET product_name = ?, scan_status = 'PENDING' WHERE id = 1");
    $stmt->bind_param("s", $product_name);
} 
elseif ($expiry_input !== '' && $product_name === '') {
    // Only updating DATE (Back Scan)
    // First, try to format the date
    $date_obj = DateTime::createFromFormat('m/d/Y', $expiry_input);
    if (!$date_obj) { $date_obj = date_create($expiry_input); } // Fallback for Gemini's YYYY-MM-DD
    
    if (!$date_obj) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date format']);
        exit;
    }
    
    $formatted_date = $date_obj->format('Y-m-d');
    $stmt = $conn->prepare("UPDATE live_scan SET expiry_date = ?, scan_status = 'READY' WHERE id = 1");
    $stmt->bind_param("s", $formatted_date);
}
elseif ($product_name !== '' && $expiry_input !== '') {
    // Updating BOTH (Final Sync)
    $date_obj = date_create($expiry_input);
    $formatted_date = $date_obj ? $date_obj->format('Y-m-d') : null;
    $stmt = $conn->prepare("UPDATE live_scan SET product_name = ?, expiry_date = ?, scan_status = 'READY' WHERE id = 1");
    $stmt->bind_param("ss", $product_name, $formatted_date);
}
else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No data provided']);
    exit;
}

// 3. Execute
if ($stmt->execute()) {
    // Determine pipeline stage for the response
    if ($product_name !== '' && $expiry_input === '') {
        $pipeline = 'front_done';
    } elseif ($expiry_input !== '' && $product_name === '') {
        $pipeline = 'back_done';
    } else {
        $pipeline = 'complete';
    }

    echo json_encode([
        'success'  => true,
        'message'  => 'Live scan updated',
        'pipeline' => $pipeline
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
}
?>