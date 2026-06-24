<?php
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';

$conf = require __DIR__ . '/../config/pusher.php';

$pusher = new Pusher\Pusher(
    $conf['key'], 
    $conf['secret'], 
    $conf['app_id'], 
    ['cluster' => $conf['cluster'], 'useTLS' => true]
);

$users_result = $conn->query("SELECT * FROM users");

if ($users_result && $users_result->num_rows > 0) {
    while ($u = $users_result->fetch_assoc()) {
        $user_id = $u['user_id'];
        
        $inventory_query = "SELECT 
                                product_name, 
                                expiry_date, 
                                SUM(remaining_stocks) as total_stocks, 
                                MAX(days_remaining) as days_left,
                                MIN(product_id) as ref_product_id
                            FROM inventory 
                            WHERE user_id = $user_id 
                            GROUP BY product_name, expiry_date";
        
        $items = $conn->query($inventory_query);

        if ($items) {
            while ($item = $items->fetch_assoc()) {
                $p_name = $conn->real_escape_string($item['product_name']);
                $db_expiry = $item['expiry_date'];
                $stocks = (int)$item['total_stocks'];
                $days_left = (int)$item['days_left'];
                $p_id = $item['ref_product_id'];

                // --- A. NEAR EXPIRY LOGIC ---
                if ($u['expiry_alert_enabled'] == 1 && $stocks > 0 && $days_left <= $u['days_before_expiry'] && $days_left > 0) {
                    $title = "EXPIRING: $p_name";
                    if (canSendAlert($conn, $user_id, $title, $u['notif_interval_hours'], $db_expiry)) {
                        $msg = "Item $p_name (Expiry: $db_expiry) is approaching its expiration date with $stocks units left.";
                        insertAndPush($conn, $pusher, $user_id, $p_id, $title, $msg, 'Near Expiry', $db_expiry, $stocks);
                    }
                }

                // --- B. EXPIRED LOGIC (Strictly Once-Lifetime Only) ---
                if ($u['expiry_alert_enabled'] == 1 && $days_left <= 0 && $stocks > 0) {
                    $current_hour = (int)date('H');
                    if ($current_hour >= $u['expired_notif_delay']) {
                        $title = "EXPIRED: $p_name";
                        
                        $check_exp = $conn->query("SELECT notif_id FROM notifications 
                                                   WHERE user_id = $user_id 
                                                     AND title = '$title' 
                                                     AND expiry_date = '$db_expiry' 
                                                     AND category = 'Expired' 
                                                   LIMIT 1");
                        
                        if ($check_exp->num_rows == 0) {
                            $msg = "$p_name has expired on $db_expiry. Please dispose of the remaining $stocks units safely.";
                            insertAndPush($conn, $pusher, $user_id, $p_id, $title, $msg, 'Expired', $db_expiry, $stocks);
                        }
                    }
                }

                // --- C. LOW STOCK LOGIC ---
                if ($u['low_stock_enabled'] == 1 && $stocks > 0 && $stocks <= $u['low_stock_threshold'] && $days_left > 0) {
                    $title = "LOW STOCK: $p_name";
                    if (canSendAlert($conn, $user_id, $title, $u['notif_interval_hours'], $db_expiry)) {
                        $msg = "$p_name is running low. Only $stocks units remaining (Expiry: $db_expiry).";
                        insertAndPush($conn, $pusher, $user_id, $p_id, $title, $msg, 'Low Stock', $db_expiry, $stocks);
                    }
                }
            }
        }
    }
}

// --- HELPER FUNCTIONS ---

function insertAndPush($conn, $pusher, $user_id, $p_id, $title, $msg, $category, $expiry, $stock) {
    $lowStockRecos = [
        "Contact supplier for immediate restock.", 
        "Check warehouse for unrecorded items.", 
        "Verify physical count against system data.",
        "Check pending deliveries for replenishment."
    ];
    $nearExpiryRecos = [
        "Implement FEFO method immediately.", 
        "Create a flash sale or bundle promo.", 
        "Prioritize this item for daily specials.",
        "Place this item at the front of the shelf.",
        "Apply a discount tag to encourage quick sales."
    ];
    $expiredRecos = [
        "Remove from shelf immediately.", 
        "Record as inventory waste.", 
        "Analyze ordering patterns to prevent future waste.",
        "Dispose of item following safety protocols."
    ];

    // Default fallback
    $reco = "Monitor item and take necessary action.";
    
    if ($category == 'Low Stock') {
        $reco = $lowStockRecos[array_rand($lowStockRecos)];
    } elseif ($category == 'Near Expiry') {
        $reco = $nearExpiryRecos[array_rand($nearExpiryRecos)];
    } elseif ($category == 'Expired') {
        $reco = $expiredRecos[array_rand($expiredRecos)];
    }

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, product_id, title, message, category, expiry_date, remaining_stocks, recommendation, created_at, is_read) VALUES(?,?,?,?,?,?,?,?,NOW(),0)");
    $stmt->bind_param("iissssis", $user_id, $p_id, $title, $msg, $category, $expiry, $stock, $reco);
    $stmt->execute();
    $stmt->close();
    
    $pusher->trigger('user.' . $user_id, 'new-notification', [
        'title' => $title,
        'message' => $msg,
        'category' => $category
    ]);
}

function canSendAlert($conn, $user_id, $title, $hours, $expiry_date = null) {
    if ($expiry_date !== null) {
        $stmt = $conn->prepare("SELECT created_at FROM notifications WHERE user_id=? AND title=? AND expiry_date=? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("iss", $user_id, $title, $expiry_date);
    } else {
        $stmt = $conn->prepare("SELECT created_at FROM notifications WHERE user_id=? AND title=? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("is", $user_id, $title);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) return true;
    
    $last_time = strtotime($result->fetch_assoc()['created_at']);
    $stmt->close();
    
    return (time() - $last_time) >= ($hours * 3600);
}

echo "Logic Engine executed successfully at " . date('Y-m-d H:i:s');
?>