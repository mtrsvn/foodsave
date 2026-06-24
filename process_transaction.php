<?php
include 'db.php';
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    $p_id = $_POST['product_id'];
    $p_name = $_POST['product_name'];
    $qty = $_POST['quantity'];
    $u_id = $_SESSION['user_id'];
    $reason = $_POST['return_reason'] ?? ''; 

    if ($action === 'sold') {
        $stmt = $conn->prepare("INSERT INTO sold (product_id, sold_item, sold_quantity, user_id, sold_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("isii", $p_id, $p_name, $qty, $u_id);
        $stmt->execute();

    } else if ($action === 'RETURN') {
        $spoiled_reasons = ['expired', 'spoiled', 'damage', 'damaged'];

        if (in_array(strtolower($reason), $spoiled_reasons)) {
            $reverse_sold = $conn->prepare("
                UPDATE sold 
                SET sold_quantity = sold_quantity - ? 
                WHERE product_id = ? 
                ORDER BY sold_at DESC 
                LIMIT 1
            ");
            $reverse_sold->bind_param("ii", $qty, $p_id);
            $reverse_sold->execute();

            $w_stmt = $conn->prepare("
                INSERT INTO wasted (product_id, wasted_item, wasted_quantity, user_id, wasted_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $w_stmt->bind_param("isii", $p_id, $p_name, $qty, $u_id);
            $w_stmt->execute();

        } else {
            $r_stmt = $conn->prepare("
                INSERT INTO returned (product_id, returned_item, returned_quantity, user_id, return_reason, returned_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $r_stmt->bind_param("isiis", $p_id, $p_name, $qty, $u_id, $reason);
            $r_stmt->execute();
        }
    }

    header("Location: inventory.php?success=1");
    exit;
}
?>