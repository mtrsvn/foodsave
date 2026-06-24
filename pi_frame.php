<?php
/**
 * pi_frame.php
 * Receives JPEG frames from Raspberry Pi and stores them in the database.
 * Also serves the latest frame to the browser.
 *
 * POST  → Pi pushes a JPEG frame
 * GET   → Browser fetches the latest frame as image/jpeg (or JSON if stale/missing)
 */

include 'db.php';

define('FRAME_TOKEN',    'foodsave_token_very_long');
define('FRAME_STALE_SEC', 8); // seconds before we consider the feed "offline"

// ─────────────────────────────────────────────────────────────────────────────
// ENSURE the frames table exists (runs once, negligible cost afterwards)
// ─────────────────────────────────────────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS pi_frames (
        id          INT UNSIGNED NOT NULL DEFAULT 1,
        frame_data  LONGBLOB     NOT NULL,
        updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─────────────────────────────────────────────────────────────────────────────
// POST — Pi is pushing a JPEG frame
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Accept token from POST body OR Authorization header
    $token = $_POST['token'] ?? '';
    if ($token === '') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (strpos($auth, 'Bearer ') === 0) {
            $token = substr($auth, 7);
        }
    }

    if ($token !== FRAME_TOKEN) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    if (!isset($_FILES['frame']) || $_FILES['frame']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No frame received or upload error']);
        exit;
    }

    $jpeg = file_get_contents($_FILES['frame']['tmp_name']);
    if ($jpeg === false || strlen($jpeg) < 100) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Empty or invalid frame data']);
        exit;
    }

    // Upsert into the single-row frames table
    $stmt = $conn->prepare("
        INSERT INTO pi_frames (id, frame_data, updated_at)
        VALUES (1, ?, NOW())
        ON DUPLICATE KEY UPDATE frame_data = VALUES(frame_data),
                                updated_at  = NOW()
    ");
    $null = null;
    $stmt->bind_param('b', $null);
    $stmt->send_long_data(0, $jpeg);

    if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'bytes' => strlen($jpeg)]);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'DB write failed: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// GET — Browser requests the latest frame
// ─────────────────────────────────────────────────────────────────────────────
$row = $conn->query("
    SELECT frame_data,
           UNIX_TIMESTAMP(updated_at) AS ts
    FROM   pi_frames
    WHERE  id = 1
    LIMIT  1
")->fetch_assoc();

if (!$row || empty($row['frame_data'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'no_frame']);
    exit;
}

$age = time() - (int)$row['ts'];

if ($age > FRAME_STALE_SEC) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'stale', 'age' => $age]);
    exit;
}

// Serve the JPEG
header('Content-Type: image/jpeg');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $row['frame_data'];