<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
        $user_email = $u['email'];
        
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
                        insertAndPush($conn, $pusher, $user_id, $p_id, $title, $msg, 'Near Expiry', $db_expiry, $stocks, $user_email, $p_name, $days_left);
                        echo "CHECKING NEAR EXPIRY: $p_name<br>";
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
                            insertAndPush($conn, $pusher, $user_id, $p_id, $title, $msg, 'Expired', $db_expiry, $stocks, $user_email, $p_name, $days_left);
                            echo "CHECKING EXPIRED: $p_name<br>";
                        }
                    }
                }

                // --- C. LOW STOCK LOGIC ---
                if ($u['low_stock_enabled'] == 1 && $stocks > 0 && $stocks <= $u['low_stock_threshold'] && $days_left > 0) {
                    $title = "LOW STOCK: $p_name";
                    if (canSendAlert($conn, $user_id, $title, $u['notif_interval_hours'], $db_expiry)) {
                        $msg = "$p_name is running low. Only $stocks units remaining (Expiry: $db_expiry).";
                        insertAndPush($conn, $pusher, $user_id, $p_id, $title, $msg, 'Low Stock', $db_expiry, $stocks, $user_email, $p_name, $days_left);
                        echo "CHECKING LOW STOCK: $p_name<br>";
                    }
                }
            }
        }
    }
}

// --- HELPER FUNCTIONS ---

function insertAndPush($conn, $pusher, $user_id, $p_id, $title, $msg, $category, $expiry, $stock, $user_email, $p_name, $days_left) {
    
echo "INSERT FUNCTION CALLED<br>";
    echo "User Email: " . $user_email . "<br>";
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

    if (!empty($user_email)) {

    echo "EMAIL BLOCK ENTERED<br>";

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {

        echo "BEFORE SEND<br>";

        $mail->SMTPDebug = 0; 
            
            $mail->isSMTP();
            $mail->Host       = 'smtp.hostinger.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'marikina@foodsave.shop'; 
            $mail->Password   = 'Adminseven@7';            
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = 465;
            
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $mail->setFrom('marikina@foodsave.shop', 'FoodSave Alerts');
            $mail->addAddress($user_email);

            $mail->isHTML(true);
            $mail->Subject = "FoodSave System Alert: " . $title;
            
            $formatted_expiry = (!empty($expiry) && $expiry !== '0000-00-00') ? date('m/d/Y', strtotime($expiry)) : '--';
            $daysColor = ($days_left <= 3) ? '#dc2626' : '#f59e0b';

            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>
                <div style='background-color: #8dae84; padding: 20px; text-align: center; color: white;'>
                    <h1 style='margin: 0; font-size: 24px;'>FoodSave Alert</h1>
                    <p style='margin: 5px 0 0; opacity: 0.9;'>Automated Operational Notification</p>
                </div>
                <div style='padding: 30px; background-color: #ffffff;'>
                    <h2 style='color: #1e293b; margin-top: 0;'>{$title}</h2>
                    <p style='color: #475569; font-size: 16px; line-height: 1.6;'>{$msg}</p>
                    
                    <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 25px 0;'>
                    
                    <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                        <tr>
                            <td style='padding: 8px 0; color: #94a3b8;'>Category:</td>
                            <td style='padding: 8px 0; font-weight: bold; color: #1e293b;'>{$category}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #94a3b8;'>Product Name:</td>
                            <td style='padding: 8px 0; font-weight: bold; color: #1e293b;'>{$p_name}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #94a3b8;'>Stocks Left:</td>
                            <td style='padding: 8px 0; font-weight: bold; color: #dc2626;'>{$stock} unit(s)</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #94a3b8;'>Expiration Date:</td>
                            <td style='padding: 8px 0; font-weight: bold; color: #1e293b;'>{$formatted_expiry}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #94a3b8;'>Days Remaining:</td>
                            <td style='padding: 8px 0; font-weight: bold; color: {$daysColor};'>{$days_left} day(s)</td>
                        </tr>
                    </table>
                    
                    <div style='margin-top: 25px; padding: 15px; background-color: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 4px;'>
                        <h4 style='margin: 0; color: #92400e;'>💡 Recommendation:</h4>
                        <p style='margin: 5px 0 0; color: #b45309; font-style: italic;'>\"{$reco}\"</p>
                    </div>
                </div>
                <div style='background-color: #f8fafc; padding: 15px; text-align: center; color: #94a3b8; font-size: 12px; border-top: 1px solid #e2e8f0;'>
                    This is an automated system message. Please check your up-to-date dashboard for immediate action.
                </div>
            </div>
            ";


        $mail->send();

        echo "AFTER SEND<br>";

    } catch (Exception $e) {

        echo "MAIL ERROR: " . $mail->ErrorInfo . "<br>";
    }
}

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
    
    if ($result->num_rows == 0) {
        $stmt->close();
        return true;
    }
    
    $last_time = strtotime($result->fetch_assoc()['created_at']);
    $stmt->close();
    
    return (time() - $last_time) >= ($hours * 3600);
}
echo "Logic Engine executed successfully at " . date('Y-m-d H:i:s');
?>