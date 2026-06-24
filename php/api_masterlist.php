<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit("Error: Unauthorized");
}

$user_id = $_SESSION['user_id'];
// Basahin ang action mula sa POST o GET
$action = $_REQUEST['action'] ?? '';

if ($action == 'fetch') {
    $stmt = $conn->prepare("SELECT * FROM product_masterlist WHERE user_id = ? ORDER BY product_name ASC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
}

if ($action == 'save') {
    $id    = $_POST['master_id'] ?? '';
    $name  = trim($_POST['product_name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);

    if (empty($name)) {
        exit("Error: Name required");
    }

    if (!empty($id)) {
        // UPDATE Logic
        $stmt = $conn->prepare("UPDATE product_masterlist SET product_name=?, price=? WHERE master_id=? AND user_id=?");
        $stmt->bind_param("sdii", $name, $price, $id, $user_id);
    } else {
        // INSERT Logic
        $stmt = $conn->prepare("INSERT INTO product_masterlist (user_id, product_name, price) VALUES (?, ?, ?)");
        $stmt->bind_param("isd", $user_id, $name, $price);
    }

    if ($stmt->execute()) {
        echo 'success';
    } else {
        echo 'Error: ' . $stmt->error;
    }
    exit;
}

if ($action == 'delete') {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM product_masterlist WHERE master_id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $user_id);
    echo $stmt->execute() ? 'success' : 'error';
    exit;
}
?>