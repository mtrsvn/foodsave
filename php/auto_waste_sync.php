<?php
$find_expired_sql = "
SELECT p.product_id, p.name, p.user_id, 
       (p.quantity - (
           COALESCE((SELECT SUM(sold_quantity) FROM sold WHERE product_id = p.product_id), 0) + 
           COALESCE((SELECT SUM(returned_quantity) FROM returned WHERE product_id = p.product_id), 0) + 
           COALESCE((SELECT SUM(wasted_quantity) FROM wasted WHERE product_id = p.product_id), 0)
       )) AS remaining_to_waste
FROM products p
-- Importante: I-check kung may record na sa wasted para hindi paulit-ulit ang insert
WHERE p.status = 'EXPIRED' 
AND p.expiry_date <= CURDATE()
HAVING remaining_to_waste > 0";

$result = $conn->query($find_expired_sql);

if ($result && $result->num_rows > 0) {
while($row = $result->fetch_assoc()) {
    $p_id = $row['product_id'];
    $p_name = $row['name'];
    $u_id = $row['user_id'];
    $qty = $row['remaining_to_waste'];

    $conn->begin_transaction();

    try {
$now = date('Y-m-d H:i:s');

$stmt_wasted = $conn->prepare("INSERT INTO wasted (product_id, wasted_item, wasted_quantity, user_id, wasted_at) VALUES (?, ?, ?, ?, NOW())");
$stmt_wasted->bind_param("isii", $p_id, $p_name, $qty, $u_id);
$stmt_wasted->execute();

$stmt_history = $conn->prepare("INSERT INTO history (product_id, user_id, product_name, quantity, type, created_at, logged_out_at) VALUES (?, ?, ?, ?, 'WASTED', NOW(), NOW())");
$stmt_history->bind_param("iisi", $p_id, $u_id, $p_name, $qty);
$stmt_history->execute();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Sync Error: " . $e->getMessage());
    }
}
}
?>