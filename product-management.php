<?php
include 'auth.php';
include 'db.php';
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($message)) $message = '';
if (!isset($_SESSION['history'])) $_SESSION['history'] = [];

$active_panel = $_GET['active_panel'] ?? 'scan';

function clearLiveScan($conn) {
    $s = $conn->prepare("UPDATE live_scan SET product_name='', expiry_date=NULL, scan_status='USED' WHERE id=1");
    if ($s) { $s->execute(); $s->close(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['active_panel'])) $active_panel = $_POST['active_panel'];

    if (isset($_POST['save_btn'])) {
        $name    = trim($_POST['product_name'] ?? '');
        $qty     = (int)($_POST['quantity'] ?? 1);
        $exp     = trim($_POST['exp_date'] ?? '');
        $action  = trim($_POST['action_type'] ?? 'IN');
        $user_id = $_SESSION['user_id'];
        $reason  = trim($_POST['return_reason'] ?? 'No reason provided');

        // Capture Retroactive Wizard Data from the Modal Submission
        $has_retro_sold   = isset($_POST['has_retro_sold']);
        $retro_sold_qty   = (int)($_POST['retro_sold_qty'] ?? 0);
        $retro_sold_ats = $_POST['retro_sold_at'] ?? [];

        $has_retro_return     = isset($_POST['has_retro_return']);
        $retro_return_qtys    = $_POST['retro_return_qty'] ?? [];
        $retro_return_reasons = $_POST['retro_return_reason'] ?? [];
        $retro_return_ats     = $_POST['retro_return_at'] ?? [];
        $waste_logged_at = trim($_POST['waste_logged_at'] ?? '');
        $scanned_date_raw = trim($_POST['scanned_date'] ?? '');

        if (!empty($scanned_date_raw)) {
            $clean_date = str_replace('T', ' ', $scanned_date_raw);
            
            if (strlen($clean_date) == 16) {
                $clean_date .= ':00';
            }
            
            $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $clean_date, new DateTimeZone('Asia/Manila'));
            
            if ($date_obj) {
                $scanned_date = $date_obj->format('Y-m-d H:i:s');
            } else {
                $scanned_date = date('Y-m-d H:i:s');
            }
        } else {
            $scanned_date = date('Y-m-d H:i:s'); 
        }

        if ($name === '' || $qty <= 0 || $exp === '') {
            $message = "Please complete all required fields.";
        } else {
            $validation_base_date = date('Y-m-d', strtotime($scanned_date));
            
            $raw_name        = trim($name);
            $formatted_name = ucwords(strtolower($raw_name));

            // ==========================================
            // ACTION: IN (INVENTORY STOCK ENTRY)
            // ==========================================
            if ($action === 'IN') {
                $cm = $conn->prepare("SELECT price FROM product_masterlist WHERE product_name=? AND user_id=? LIMIT 1");
                $cm->bind_param("si", $raw_name, $user_id);
                $cm->execute();
                $master_row = $cm->get_result()->fetch_assoc();
                $cm->close();

                if (!$master_row && $exp < $validation_base_date) {
                    $message = "FAILED: '$formatted_name' is UNIDENTIFIED and ALREADY EXPIRED based on scan date.";
                } elseif ($exp < $validation_base_date) {
                    $message = "FAILED: '$formatted_name' is ALREADY EXPIRED (Expiry: $exp | Scanned: $validation_base_date).";
                } elseif (!$master_row) {
                    $message = "PRODUCT UNIDENTIFIED: Please register '$raw_name' in Masterlist first.";
                } else {
                    $auto_price = $master_row['price'];

                    $conn->begin_transaction();
                    try {
                        // 1. Insert original batch baseline definition
                        $stmt = $conn->prepare("INSERT INTO products (user_id, name, quantity, expiry_date, date_scanned, status, price) VALUES (?, ?, ?, ?, ?, 'USABLE', ?)");
                        $stmt->bind_param("isisss", $user_id, $formatted_name, $qty, $exp, $scanned_date, $auto_price);
                        $stmt->execute();
                        $p_id = $conn->insert_id;
                        $stmt->close();

                        // Log history row matching baseline creation
                        $h = $conn->prepare("INSERT INTO history (user_id, product_id, product_name, quantity, price, type, expiry_date, created_at) VALUES (?, ?, ?, ?, ?, 'IN', ?, ?)");
                        $h->bind_param("iisidss", $user_id, $p_id, $formatted_name, $qty, $auto_price, $exp, $scanned_date);
                        $h->execute(); 
                        $h->close();

                        $remaining_stock = $qty;

                        // 2. PROCESS RETROACTIVE SALES
                        if ($has_retro_sold && $retro_sold_qty > 0) {
                            $take = min($retro_sold_qty, $remaining_stock);
                            
                            $clean_sold_at = str_replace('T', ' ', $retro_sold_at);
                            if (strlen($clean_sold_at) == 16) $clean_sold_at .= ':00';

                            $ss = $conn->prepare("INSERT INTO sold (product_id, sold_item, sold_quantity, user_id, sold_at) VALUES (?, ?, ?, ?, ?)");
                            $ss->bind_param("isiis", $p_id, $raw_name, $take, $user_id, $clean_sold_at);
                            $ss->execute(); $ss->close();

                            $hsold = $conn->prepare("INSERT INTO history (user_id, product_id, product_name, quantity, price, type, expiry_date, created_at) VALUES (?, ?, ?, ?, ?, 'SOLD', ?, ?)");
                            $hsold->bind_param("iisidss", $user_id, $p_id, $raw_name, $take, $auto_price, $exp, $clean_sold_at);
                            $hsold->execute(); $hsold->close();

                            $remaining_stock -= $take;
                        }

                        // 3. PROCESS RETROACTIVE DYNAMIC RETURNS
                        if ($has_retro_return && count($retro_return_qtys) > 0) {
                            foreach ($retro_return_qtys as $index => $r_qty) {
                                $r_qty = (int)$r_qty;
                                if ($r_qty <= 0) continue;

                                $r_reason = trim($retro_return_reasons[$index] ?? 'No reason');
                                $r_at     = trim($retro_return_ats[$index] ?? $scanned_date);
                                
                                $clean_ret_at = str_replace('T', ' ', $r_at);
                                if (strlen($clean_ret_at) == 16) $clean_ret_at .= ':00';

                                $negative_sold = $r_qty * -1;
                                $rs = $conn->prepare("INSERT INTO sold (product_id, sold_item, sold_quantity, user_id, sold_at) VALUES (?, ?, ?, ?, ?)");
                                $rs->bind_param("isiis", $p_id, $raw_name, $negative_sold, $user_id, $clean_ret_at);
                                $rs->execute(); $rs->close();

                                $spoiled = ['Expired upon Purchase', 'Spoiled Food'];

                                if (in_array($r_reason, $spoiled)) {
                                    $h1 = $conn->prepare("INSERT INTO history (user_id,product_id,product_name,quantity,price,type,expiry_date,return_reason,created_at) VALUES (?,?,?,?,?,'RETURN',?,?,?)");
                                    $h1->bind_param("iisidsss",$user_id,$p_id,$formatted_name,$r_qty,$auto_price,$exp,$r_reason,$clean_ret_at);
                                    $h1->execute(); $h1->close();
                                    
                                    $wr = "Returned Item: $r_reason";
                                    $h2 = $conn->prepare("INSERT INTO history (user_id,product_id,product_name,quantity,price,type,expiry_date,return_reason,created_at) VALUES (?,?,?,?,?,'WASTED',?,?,?)");
                                    $h2->bind_param("iisidsss",$user_id,$p_id,$formatted_name,$r_qty,$auto_price,$exp,$wr,$clean_ret_at);
                                    $h2->execute(); $h2->close();
                                    
                                    $ws = $conn->prepare("INSERT INTO wasted (product_id,wasted_item,wasted_quantity,user_id,wasted_at) VALUES (?,?,?,?,?)");
                                    $ws->bind_param("isiis",$p_id,$formatted_name,$r_qty,$user_id,$clean_ret_at);
                                    $ws->execute(); $ws->close();

                                    $msg_type = "WASTE";
                                } else {
                                    $h_return = $conn->prepare("INSERT INTO history (user_id, product_id, product_name, quantity, price, type, expiry_date, return_reason, created_at) VALUES (?, ?, ?, ?, ?, 'RETURN', ?, ?, ?)");
                                    $h_return->bind_param("iisidsss", $user_id, $p_id, $formatted_name, $r_qty, $auto_price, $exp, $r_reason, $clean_ret_at);
                                    $h_return->execute(); $h_return->close();

                                    $rr = $conn->prepare("INSERT INTO returned (product_id, returned_item, returned_quantity, user_id, return_reason, date_scanned, returned_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                    $rr->bind_param("isiisss", $p_id, $formatted_name, $r_qty, $user_id, $r_reason, $scanned_date, $clean_ret_at);
                                    $rr->execute(); $rr->close();

                                    $msg_type = "RESTOCKED";

                                    $remaining_stock += $r_qty;
                                }
                            }
                        }

                        if (!empty($waste_logged_at)) {
                            $clean_waste_at = str_replace('T', ' ', $waste_logged_at);
                            if (strlen($clean_waste_at) == 16) {
                                $clean_waste_at .= ':00'; 
                            }
                        
                            $waste_reason = "Staff Request: Logged out / Wasted from Shelves.";
                        
                            $hw = $conn->prepare("INSERT INTO history (user_id, product_id, product_name, quantity, price, type, expiry_date, return_reason, created_at) VALUES (?, ?, ?, ?, ?, 'WASTED', ?, ?, ?)");
                            $hw->bind_param("iisidsss", $user_id, $p_id, $formatted_name, $remaining_stock, $auto_price, $exp, $waste_reason, $clean_waste_at);
                            $hw->execute(); 
                            $hw->close();
                        
                            $w_stmt = $conn->prepare("INSERT INTO wasted (product_id, wasted_item, wasted_quantity, user_id, wasted_at) VALUES (?, ?, ?, ?, ?)");
                            $w_stmt->bind_param("isiis", $p_id, $formatted_name, $remaining_stock, $user_id, $clean_waste_at);
                            $w_stmt->execute(); 
                            $w_stmt->close();
                        
                            $u_status = $conn->prepare("UPDATE products SET status='WASTED' WHERE product_id=?");
                            $u_status->bind_param("i", $p_id); 
                            $u_status->execute(); 
                            $u_status->close();
                            
                            $remaining_stock = 0; 
                        }

                        $today_real = date('Y-m-d');
                        if ($exp < $today_real && $remaining_stock > 0) {
                            $auto_wasted_reason = "System Auto-Waste: Expired batch leftover detected on Manual Entry.";
                            
                            $hwaste = $conn->prepare("INSERT INTO history (user_id, product_id, product_name, quantity, price, type, expiry_date, return_reason, created_at) VALUES (?, ?, ?, ?, ?, 'WASTED', ?, ?, NOW())");
                            $hwaste->bind_param("iisidss", $user_id, $p_id, $formatted_name, $remaining_stock, $auto_price, $exp, $auto_wasted_reason);
                            $hwaste->execute(); $hwaste->close();

                            $ws_auto = $conn->prepare("INSERT INTO wasted (product_id, wasted_item, wasted_quantity, user_id, wasted_at) VALUES (?, ?, ?, ?, NOW())");
                            $ws_auto->bind_param("isii", $p_id, $formatted_name, $remaining_stock, $user_id);
                            $ws_auto->execute(); $ws_auto->close();
                            
                            $u_status = $conn->prepare("UPDATE products SET status='EXPIRED' WHERE product_id=?");
                            $u_status->bind_param("i", $p_id); $u_status->execute(); $u_status->close();
                        }

                        $conn->commit();
                        clearLiveScan($conn);
                        $_SESSION['success_message'] = "Successfully Processed Entry Batch Data for $formatted_name.";
                        header("Location: product-management.php?active_panel=".urlencode($active_panel));
                        exit();

                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "ERROR: Failed to save batch tracking transaction dependencies.";
                    }
                }

            // ==========================================
            // ACTION: OUT
            // ==========================================
            } elseif ($action === 'OUT') {
                $cm = $conn->prepare("SELECT product_name FROM product_masterlist WHERE product_name=? AND user_id=? LIMIT 1");
                $cm->bind_param("si", $raw_name, $user_id); 
                $cm->execute();
                $master_row = $cm->get_result()->fetch_assoc(); 
                $cm->close();

                if (!$master_row && $exp < $validation_base_date) { 
                    $message = "FAILED: '$formatted_name' is UNIDENTIFIED and EXPIRED."; 
                } elseif (!$master_row) { 
                    $message = "PRODUCT UNIDENTIFIED: Register '$raw_name' in Masterlist first."; 
                } elseif ($exp < $validation_base_date) { 
                    $message = "TRANSACTION DENIED: '$formatted_name' was already EXPIRED on $validation_base_date."; 
                } else {
                    $st = $conn->prepare("SELECT SUM(remaining_stocks) as total_qty FROM inventory WHERE product_name=? AND user_id=? AND expiry_date=?");
                    $st->bind_param("sis", $raw_name, $user_id, $exp);
                    $st->execute();
                    $total_row = $st->get_result()->fetch_assoc();
                    $total_available = $total_row['total_qty'] ?? 0;
                    $st->close();

                    if ($total_available < $qty) { 
                        $message = "INSUFFICIENT STOCK: Only $total_available left for this expiry date."; 
                    } else {
                        $fi = $conn->prepare("SELECT product_id, price, remaining_stocks FROM inventory WHERE product_name=? AND user_id=? AND expiry_date=? AND remaining_stocks > 0 ORDER BY product_id ASC");
                        $fi->bind_param("sis", $raw_name, $user_id, $exp);
                        $fi->execute();
                        $inventory_items = $fi->get_result();
                        $fi->close();

                        $to_deduct = $qty;
                        $conn->begin_transaction();

                        try {
                            while ($row = $inventory_items->fetch_assoc()) {
                                if ($to_deduct <= 0) break;

                                $p_id = $row['product_id'];
                                $db_price = $row['price'];
                                $current_row_stock = $row['remaining_stocks'];

                                $take = min($to_deduct, $current_row_stock);

                                $ss = $conn->prepare("INSERT INTO sold (product_id, sold_item, sold_quantity, user_id, sold_at) VALUES (?, ?, ?, ?, NOW())");
                                $ss->bind_param("isii", $p_id, $raw_name, $take, $user_id);
                                $ss->execute();
                                $ss->close();

                                $h = $conn->prepare("INSERT INTO history (user_id, product_id, product_name, quantity, price, type, expiry_date, created_at) VALUES (?, ?, ?, ?, ?, 'SOLD', ?, ?)");
                                $h->bind_param("iisidss", $user_id, $p_id, $raw_name, $take, $db_price, $exp, $scanned_date);
                                $h->execute();
                                $h->close();

                                $to_deduct -= $take;
                            }

                            $conn->commit();
                            $url = "https://foodsave.shop/live_alert_cron?urgent=1";
                            @file_get_contents($url);
                            clearLiveScan($conn);
                            $_SESSION['success_message'] = "SOLD: $qty $formatted_name";
                            header("Location: product-management.php?active_panel=" . urlencode($active_panel));
                            exit();

                        } catch (Exception $e) {
                            $conn->rollback();
                            $message = "ERROR: Transaction failed.";
                        }
                    }
                }

            // ==========================================
            // ACTION: RETURN
            // ==========================================
            } elseif ($action === 'RETURN') {
                $fi = $conn->prepare("SELECT product_id, price, date_scanned FROM products WHERE name=? AND expiry_date=? AND user_id=? ORDER BY product_id DESC");
                $fi->bind_param("ssi", $raw_name, $exp, $user_id);
                $fi->execute();
                $all_matches = $fi->get_result();
                $fi->close();

                $to_return = $qty;
                $processed = false;
                $msg_type = "RESTOCKED";

                while ($inv_row = $all_matches->fetch_assoc()) {
                    if ($to_return <= 0) break;

                    $p_id = $inv_row['product_id'];
                    $db_price = $inv_row['price'];
                    $orig_date_scanned = $inv_row['date_scanned'];

                    $cs = $conn->prepare("SELECT SUM(sold_quantity) as ts FROM sold WHERE product_id=?");
                    $cs->bind_param("i", $p_id);
                    $cs->execute();
                    $total_sold = $cs->get_result()->fetch_assoc()['ts'] ?? 0;
                    $cs->close();

                    if ($total_sold > 0) {
                        $can_take = min($to_return, $total_sold);
                        $rq = $can_take * -1;

                        $rs = $conn->prepare("INSERT INTO sold (product_id,sold_item,sold_quantity,user_id,sold_at) VALUES (?,?,?,?,NOW())");
                        $rs->bind_param("isii", $p_id, $raw_name, $rq, $user_id); 
                        $rs->execute();
                        $rs->close();

                        $spoiled = ['Expired upon Purchase','Spoiled Food'];
                        
                        if (in_array($reason, $spoiled) || $exp < $validation_base_date) {
                            $h1 = $conn->prepare("INSERT INTO history (user_id,product_id,product_name,quantity,price,type,expiry_date,return_reason,created_at) VALUES (?,?,?,?,?,'RETURN',?,?,?)");
                            $h1->bind_param("iisidsss",$user_id,$p_id,$formatted_name,$can_take,$db_price,$exp,$reason,$scanned_date);
                            $h1->execute(); 
                            $h1->close();
                            
                            $h2 = $conn->prepare("INSERT INTO history (user_id,product_id,product_name,quantity,price,type,expiry_date,return_reason,created_at) VALUES (?,?,?,?,?,'WASTED',?,?,?)");
                            $h2->bind_param("iisidsss",$user_id,$p_id,$formatted_name,$can_take,$db_price,$exp,$reason,$scanned_date);
                            $h2->execute(); 
                            $h2->close();

                            $ws = $conn->prepare("INSERT INTO wasted (product_id,wasted_item,wasted_quantity,user_id,wasted_at) VALUES (?,?,?,?,?)");
                            $ws->bind_param("isiis",$p_id,$formatted_name,$can_take,$user_id,$scanned_date);
                            $ws->execute(); 
                            $ws->close();
                            
                            $msg_type = "WASTE";
                        } else {
                            $h_return = $conn->prepare("INSERT INTO history (user_id, product_id, product_name, quantity, price, type, expiry_date, return_reason, created_at) VALUES (?, ?, ?, ?, ?, 'RETURN', ?, ?, NOW())");
                            $h_return->bind_param("iisidss", $user_id, $p_id, $formatted_name, $can_take, $db_price, $exp, $reason);
                            $h_return->execute();
                            $h_return->close();

                            $rr = $conn->prepare("INSERT INTO returned (product_id, returned_item, returned_quantity, user_id, return_reason, date_scanned, returned_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $rr->bind_param("isiisss", $p_id, $formatted_name, $can_take, $user_id, $reason, $orig_date_scanned, $scanned_date); 
                            $rr->execute(); 
                            $rr->close();

                            $msg_type = "RESTOCKED";
                        }

                        $to_return -= $can_take;
                        $processed = true;
                    }
                }

                if ($processed) {
                    clearLiveScan($conn);
                    $_SESSION['success_message'] = ($msg_type == "WASTE") ? "RETURN OK: $reason → WASTE" : "RETURN OK: $qty $formatted_name restocked";
                    header("Location: product-management.php?active_panel=".urlencode($active_panel));
                    exit();
                } else {
                    $message = "FAILED: No sold records found for '$formatted_name' with Expiry $exp.";
                }
            } else { 
                $message = "Invalid action."; 
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FoodSave - Add Products</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/management.css">
    <script src="https://js.pusher.com/8.0.1/pusher.min.js"></script>
    <style>
    .live-status-box {
        margin-top: 10px;
        padding: 12px 14px;
        border-radius: 12px;
        background: #f1f5f9;
        color: #334155;
        font-weight: 600;
        font-size: 0.95rem;
    }

    .scan-note {
        line-height: 1.6;
    }

    .message {
        margin: 16px 0;
        padding: 14px 16px;
        border-radius: 12px;
        background: #fef2f2;
        color: #991b1b;
        font-weight: 600;
    }

    #piStatusBar {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 12px 18px;
        margin-bottom: 18px;
    }

    #piDot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #94a3b8;
        flex-shrink: 0;
        transition: background 0.4s;
    }

    #piDot.online {
        background: #22c55e;
        box-shadow: 0 0 8px #22c55e;
        animation: pulse-dot 2s infinite;
    }

    #piDot.offline {
        background: #ef4444;
    }

    @keyframes pulse-dot {

        0%,
        100% {
            transform: scale(1);
            opacity: 1;
        }

        50% {
            transform: scale(1.3);
            opacity: 0.7;
        }
    }

    #piStatusText {
        font-weight: 700;
        font-size: 0.9rem;
        color: #475569;
    }

    #cameraFeedBox {
        width: 100%;
        min-height: 300px;
        background: #0f172a;
        border-radius: 16px;
        overflow: hidden;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    #cameraFeedImg {
        width: 100%;
        height: 300px;
        object-fit: cover;
        display: none;
    }

    #cameraFeedOff {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #475569;
        text-align: center;
        padding: 20px;
    }

    #cameraFeedOff i {
        font-size: 3rem;
        margin-bottom: 10px;
        opacity: 0.4;
    }

    #captureFeedback {
        position: absolute;
        top: 10px;
        left: 10px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 700;
        display: none;
        z-index: 5;
    }

    .key-hint-bar {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 12px;
    }

    .key-hint {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #f8fafc;
        padding: 7px 13px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        font-size: 0.82rem;
        font-weight: 600;
        color: #475569;
    }

    .key-badge {
        background: #1e293b;
        color: white;
        padding: 2px 8px;
        border-radius: 5px;
        font-size: 0.78rem;
        font-weight: 800;
    }

    .capture-btn {
        background: #8ea97f;
        color: white;
        border: none;
        padding: 10px 13px;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 700;
        white-space: nowrap;
        transition: 0.2s;
    }

    .capture-btn:hover {
        background: #759165;
    }

    .capture-btn:disabled {
        background: #94a3b8;
        cursor: not-allowed;
    }

    #returnModal .modal-cancel-btn {
        flex: 1;
        padding: 10px 16px;
        border-radius: 10px;
        background: #e2e8f0;
        color: #475569;
        border: none;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        height: 42px;
    }

    /* Permission Modal Styles */
    .permission-modal {
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
    }

    .permission-modal-content {
        background: #ffffff;
        margin: 12% auto;
        padding: 30px;
        border-radius: 16px;
        width: 90%;
        max-width: 420px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        text-align: center;
        border: 1px solid #e2e8f0;
    }

    .permission-icon-box {
        width: 64px;
        height: 64px;
        background: #fef2f2;
        color: #ef4444;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin: 0 auto 20px auto;
    }

    .permission-icon-box.active-status {
        background: #ecfdf5;
        color: #10b981;
    }

    .toggle-container {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f8fafc;
        padding: 14px 20px;
        border-radius: 12px;
        margin: 24px 0;
        border: 1px solid #e2e8f0;
    }

    .switch-label {
        font-weight: 600;
        color: #334155;
        font-size: 0.95rem;
    }

    .switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 26px;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #cbd5e1;
        transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 34px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 50%;
    }

    input:checked+.slider {
        background-color: #10b981;
    }

    input:checked+.slider:before {
        transform: translateX(24px);
    }

    .modal-close-btn {
        width: 100%;
        padding: 12px;
        background: #0f172a;
        color: white;
        border: none;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }

    .modal-close-btn:hover {
        background: #1e293b;
    }

    .retro-modal-backdrop {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .retro-modal-card {
        background: #ffffff;
        padding: 40px 32px;
        border-radius: 16px;
        max-width: 440px;
        width: 90%;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        border: 1px solid rgba(241, 245, 249, 0.8);
        box-sizing: border-box;
    }

    .retro-modal-card.wide-card {
        padding: 36px;
        max-width: 580px;
        width: 92%;
        max-height: 85vh;
        overflow-y: auto;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
    }

    /* Typography elements */
    .retro-modal-icon {
        font-size: 2rem;
        margin-bottom: 16px;
        animation: scaleIn 0.3s ease;
    }

    .retro-modal-title {
        margin: 0 0 12px 0;
        font-size: 1.35rem;
        font-weight: 600;
        color: #0f172a;
        letter-spacing: -0.025em;
    }

    .wide-card .retro-modal-title {
        margin-bottom: 8px;
        font-size: 1.4rem;
        letter-spacing: -0.02em;
    }

    .retro-modal-description {
        margin: 0 0 32px 0;
        font-size: 0.95rem;
        color: #64748b;
        line-height: 1.6;
    }

    .retro-modal-description.padded-desc {
        padding: 0 10px;
    }

    .retro-modal-sub-description {
        margin: 0 0 28px 0;
        font-size: 0.9rem;
        color: #64748b;
        line-height: 1.5;
    }

    .highlight-text {
        color: #0f172a;
        font-weight: 500;
    }

    /* Structural & Form Framing Layouts */
    .retro-grid-frame {
        display: grid;
        gap: 16px;
        margin-bottom: 28px;
    }

    .retro-section-row {
        background: #f8fafc;
        padding: 20px;
        border-radius: 12px;
        border: 1px solid #edf2f7;
    }

    .retro-checkbox-label {
        font-weight: 600;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        font-size: 0.95rem;
    }

    .retro-checkbox-label input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #0f172a;
    }

    .retro-animated-inputs {
        animation: fadeIn 0.2s ease;
    }

    .retro-animated-inputs.hidden-inputs {
        display: none;
        margin-top: 16px;
        padding-top: 16px;
    }

    .border-dashed-top {
        border-top: 1px dashed #e2e8f0;
    }

    .retro-input-split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .field-label {
        font-size: 0.8rem;
        font-weight: 500;
        color: #64748b;
        display: block;
        margin-bottom: 6px;
    }

    .retro-input-control {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.9rem;
        box-sizing: border-box;
    }

    /* Buttons & Dynamic Interactions */
    .retro-modal-actions {
        gap: 12px;
    }

    .retro-btn {
        flex: 1;
        max-width: 140px;
        padding: 12px 24px;
        font-size: 0.9rem;
        font-weight: 500;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        box-sizing: border-box;
    }

    .retro-btn-secondary {
        background: #f1f5f9;
        color: #475569;
    }

    .retro-btn-secondary:hover {
        background: #e2e8f0;
    }

    .retro-btn-primary {
        background: #0f172a;
        color: #ffffff;
        box-shadow: 0 4px 6px -1px rgba(15, 23, 42, 0.15);
    }

    .retro-btn-primary:hover {
        background: #1e293b;
    }

    .retro-btn-add {
        margin-top: 4px;
        background: #ffffff;
        color: #0f172a;
        border: 1px solid #cbd5e1;
        padding: 8px 16px;
        font-size: 0.85rem;
        font-weight: 500;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .retro-btn-add:hover {
        background: #f1f5f9;
    }

    .retro-footer-actions {
        text-align: right;
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        border-top: 1px solid #f1f5f9;
        padding-top: 24px;
    }

    .retro-footer-actions .retro-btn-primary {
        max-width: none;
        flex: none;
        padding: 10px 24px;
    }

    .retro-btn-cancel {
        padding: 10px 20px;
        background: transparent;
        color: #64748b;
        border: 1px solid transparent;
        font-size: 0.9rem;
        font-weight: 500;
        border-radius: 8px;
        cursor: pointer;
        transition: color 0.2s;
    }

    .retro-btn-cancel:hover {
        color: #0f172a;
    }

    /* Utilities */
    .text-center {
        text-align: center;
    }

    .flex-center {
        display: flex;
        justify-content: center;
    }

    .flex-align-center {
        display: flex;
        align-items: center;
    }

    .emoji-space {
        margin-right: 8px;
    }

    /* Dynamic Added Rows Inside JS (Naka-class na rin) */
    .retro-return-row {
        border: 1px solid #e2e8f0;
        padding: 16px;
        margin-bottom: 12px;
        border-radius: 8px;
        position: relative;
        background: #ffffff;
        animation: fadeIn 0.2s ease;
    }

    /* Keyframes */
    @keyframes scaleIn {
        from {
            transform: scale(0.96);
            opacity: 0;
        }

        to {
            transform: scale(1);
            opacity: 1;
        }
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-4px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>
    <main>
        <div class="header-card">
            <h2>Add Products</h2>
            <p>Use your Raspberry Pi scanner or enter product details manually.</p>
        </div>

        <?php if (!empty($message)): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="message" style="background:#ecfdf5;color:#166634;">
            <?= htmlspecialchars($_SESSION['success_message']) ?>
            <?php unset($_SESSION['success_message']); ?>
        </div>
        <?php endif; ?>

        <div class="top-buttons">
            <button class="top-btn" type="button" onclick="showPanel('scan')">SCAN</button>
            <button class="top-btn" type="button" onclick="showPanel('manual')">MANUAL</button>
        </div>

        <div class="entry-panel" id="scanPanel">
            <form method="POST" action="" onsubmit="return beforeSubmit('scan')">
                <input type="hidden" name="save_product" value="1">
                <input type="hidden" name="mode" value="SCAN">
                <input type="hidden" name="action_type" id="scan_action_type" value="IN">
                <input type="hidden" name="return_reason" id="scan_return_reason" value="">
                <input type="hidden" name="active_panel" value="scan">

                <div id="piStatusBar">
                    <div id="piDot"></div>
                    <span id="piStatusText">Checking Raspberry Pi connection…</span>
                    <span id="piLastSeen" style="font-size:0.78rem; color:#94a3b8; margin-left:auto;"></span>
                </div>

                <div class="main-grid">
                    <div class="card">
                        <div class="card-title">Live Camera Feed</div>

                        <div id="cameraFeedBox">
                            <img id="cameraFeedImg" alt="Pi Camera Feed">
                            <div id="cameraFeedOff">
                                <i class="fas fa-camera-slash"></i>
                                <p style="margin:0;font-weight:700;">Camera offline</p>
                                <small>Start main.py on the Raspberry Pi</small>
                            </div>
                            <div id="captureFeedback"></div>
                        </div>

                        <div class="key-hint-bar">
                            <div class="key-hint"><span class="key-badge">F</span> Capture Front (Product Name)</div>
                            <div class="key-hint"><span class="key-badge">B</span> Capture Back (Expiry Date)</div>
                        </div>

                        <div id="piScanStatus" class="live-status-box">Waiting for Pi…</div>
                    </div>

                    <div class="card">
                        <div class="card-title">Product Details</div>

                        <div class="form-group">
                            <label>Product Name *</label>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="text" class="form-control" id="product_name" name="product_name"
                                    placeholder="Waiting for scan…" readonly
                                    style="background:#f1f5f9;cursor:not-allowed;color:#475569;">
                                <button type="button" class="capture-btn" id="btnFront"
                                    onclick="triggerCapture('FRONT')">
                                    <i class="fas fa-camera"></i> F
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Quantity *</label>
                            <div class="qty-wrap">
                                <button class="qty-btn minus-btn" type="button"
                                    onclick="changeQty('scan_quantity',-1)">-</button>
                                <input type="number" class="form-control" id="scan_quantity" name="quantity" min="1"
                                    value="1">
                                <button class="qty-btn plus-btn" type="button"
                                    onclick="changeQty('scan_quantity', 1)">+</button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Expiration Date * <small style="color:#64748b;">(auto-filled from
                                    scan)</small></label>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="date" class="form-control" id="expiry_date" name="exp_date" readonly
                                    style="background:#f1f5f9;cursor:not-allowed;color:#475569;" onfocus="this.blur()">
                                <button type="button" class="capture-btn" id="btnBack" onclick="triggerCapture('BACK')">
                                    <i class="fas fa-calendar"></i> B
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Choose Action</label>
                            <div class="action-buttons">
                                <button type="button" class="action-btn active"
                                    onclick="setAction('scan','IN',this)">IN</button>
                                <button type="button" class="action-btn"
                                    onclick="setAction('scan','OUT',this)">OUT</button>
                                <button type="button" class="action-btn"
                                    onclick="setAction('scan','RETURN',this)">RETURN</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="scanDetailsCard" class="retro-modal-backdrop" style="display:none; justify-content:center; align-items:center;">
                    <div class="retro-modal-card text-center" style="max-width:400px; padding:30px;">
                        <div class="retro-modal-icon" style="font-size:3rem; margin-bottom:15px; display:block;">📋</div>
                        <h3 class="retro-modal-title" style="margin-bottom:15px;">Confirm Details</h3>
                        <div class="details-content retro-modal-description" id="scanDetailsContent" style="text-align:left; background:#f1f5f9; padding:20px; border-radius:8px; margin-bottom:20px; font-weight:500; font-size:1.1rem; line-height:1.6;">
                            Product Name: --<br>Quantity: --<br>Expiration Date: --<br>Action: IN
                        </div>
                        <div class="retro-modal-actions" style="gap:10px; display:flex; justify-content:center;">
                            <button type="button" class="modal-cancel-btn" onclick="document.getElementById('scanDetailsCard').style.display='none';" style="flex:1;">Cancel</button>
                            <button type="submit" name="save_btn" class="save-btn" style="flex:1; padding:12px; border-radius:8px; border:none; background:#16a34a; color:white; font-weight:bold; cursor:pointer;">CONFIRM &amp; SAVE</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="entry-panel" id="manualPanel" style="display:none;">
            <form id="manualProductForm" method="POST" action="" onsubmit="return beforeSubmit('manual')">
                <input type="hidden" name="save_product" value="1">
                <input type="hidden" name="mode" value="MANUAL">
                <input type="hidden" name="action_type" id="manual_action_type" value="IN">
                <input type="hidden" name="return_reason" id="manual_return_reason" value="">
                <input type="hidden" name="active_panel" value="manual">

                <div class="main-grid">
                    <div class="card">
                        <div class="card-title">Manual Entry</div>
                        <div class="manual-box">
                            <div class="manual-icon">✎</div>
                            <div class="manual-text">MANUAL INPUT</div>
                        </div>
                        <div class="scan-note">Enter product details manually using the keyboard.</div>
                    </div>
                    <div class="card">
                        <div class="card-title">Product Details</div>
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" class="form-control" id="manual_product_name" name="product_name"
                                placeholder="Enter product name">
                        </div>
                        <div class="form-group">
                            <label>Quantity *</label>
                            <div class="qty-wrap">
                                <button class="qty-btn minus-btn" type="button"
                                    onclick="changeQty('manual_quantity',-1)">-</button>
                                <input type="number" class="form-control" id="manual_quantity" name="quantity" min="1"
                                    value="1">
                                <button class="qty-btn plus-btn" type="button"
                                    onclick="changeQty('manual_quantity', 1)">+</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Expiration Date *</label>
                            <input type="date" class="form-control" id="manual_exp_date" name="exp_date">
                        </div>

                        <div class="form-group">
                            <label>Date Scanned <small style="color:#64748b;">(Optional: defaults to system
                                    real-time)</small></label>
                            <input type="datetime-local" class="form-control" id="manual_scanned_date"
                                name="scanned_date" onchange="updateDetailsCard('manual')">
                        </div>

                        <div class="form-group">
                            <label>Choose Action</label>
                            <div class="action-buttons">
                                <button type="button" class="action-btn active"
                                    onclick="setAction('manual','IN',this)">IN</button>
                                <button type="button" class="action-btn"
                                    onclick="setAction('manual','OUT',this)">OUT</button>
                                <button type="button" class="action-btn"
                                    onclick="setAction('manual','RETURN',this)">RETURN</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="details-card" id="manualDetailsCard">
                    <h3>Product Details</h3>
                    <div class="details-content" id="manualDetailsContent">
                        Product Name: --<br>Quantity: --<br>Expiration Date: --<br>Action: IN
                    </div>
                    <button type="submit" name="save_btn" class="save-btn">CONFIRM</button>
                </div>

                <div id="retroQuestionModal" class="retro-modal-backdrop">
                    <div class="retro-modal-card text-center">
                        <div class="retro-modal-icon">⚠️</div>
                        <h3 class="retro-modal-title">Back-Dated Entry Detected</h3>
                        <p class="retro-modal-description padded-desc">
                            The system detected that the <span class="highlight-text">"Date Scanned"</span> is
                            configured to a past date. Did any <b>SOLD</b> or <b>RETURN</b> transactions occur for this
                            batch prior to processing?
                        </p>
                        <div class="retro-modal-actions flex-center">
                            <button type="button" id="btnRetroNo" class="retro-btn retro-btn-secondary">NO</button>
                            <button type="button" id="btnRetroYes" class="retro-btn retro-btn-primary">YES</button>
                        </div>
                    </div>
                </div>

                <div id="retroFormModal" class="retro-modal-backdrop">
                    <div class="retro-modal-card wide-card">
                        <h3 class="retro-modal-title flex-align-center">
                            <span class="emoji-space">📝</span> Historical Transaction Logs
                        </h3>
                        <p class="retro-modal-sub-description">
                            Log preceding sales or item returns that occurred while this specific batch was on shelves
                            to establish synchronized inventory reconciliation.
                        </p>

                        <div class="retro-grid-frame">
                            <div class="retro-section-row">
                                <label class="retro-checkbox-label">
                                    <input type="checkbox" id="chkRetroSold" name="has_retro_sold" value="1">
                                    Include Sales Records
                                </label>
                                <div id="retroSoldInputs" class="retro-animated-inputs hidden-inputs border-dashed-top"
                                    style="display:none;">
                                    <div id="salesRowsContainer"></div>
                                    <button type="button" id="btnAddingSalesRow" class="retro-btn-add">
                                        + Add Dynamic Entry Row
                                    </button>
                                </div>
                            </div>

                            <div class="retro-section-row">
                                <label class="retro-checkbox-label">
                                    <input type="checkbox" id="chkRetroReturn" name="has_retro_return" value="1">
                                    Include Return Logs
                                </label>
                                <div id="retroReturnInputs"
                                    class="retro-animated-inputs hidden-inputs border-dashed-top" style="display:none;">
                                    <div id="returnRowsContainer"></div>
                                    <button type="button" id="btnAddReturnRow" class="retro-btn-add">
                                        + Add Dynamic Entry Row
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="retro-footer-actions">
                            <button type="button" id="btnCancelRetroForm" class="retro-btn-cancel">Cancel</button>
                            <button type="button" id="btnSubmitRetroForm" class="retro-btn retro-btn-primary">Finalize &
                                Save All</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div id="returnModal"
            style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.6);backdrop-filter:blur(3px);">
            <div
                style="background:white;margin:15% auto;padding:25px;border-radius:15px;width:320px;text-align:center;">
                <h3 style="margin-bottom:15px;">Return Reason</h3>
                <select id="reasonSelect" class="form-control"
                    style="margin-bottom:20px;width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
                    <option>Wrong Item</option>
                    <option>Customer Change of Mind</option>
                    <option>Expired upon Purchase</option>
                    <option>Spoiled Food</option>
                </select>
                <div style="display:flex;gap:10px;">
                    <button type="button" class="modal-cancel-btn" onclick="confirmReturnReason()"
                        style="background:#8ea97f;color:white;">Confirm</button>
                    <button type="button" class="modal-cancel-btn" onclick="closeReturnModal()">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ══════════════ ERROR CUSTOM MODAL ══════════════ -->
        <div id="retroErrorModal" class="retro-modal-backdrop"
            style="display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(3px); align-items:center; justify-content:center;">
            <div class="retro-modal-card text-center"
                style="background:white; padding:25px; border-radius:15px; width:360px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
                <div class="retro-modal-icon" style="font-size: 2.5rem; margin-bottom: 10px;">❌</div>

                <h3 class="retro-modal-title"
                    style="color:#e11d48; margin-bottom: 15px; font-size: 1.2rem; font-weight: bold;">
                    INACCURATE DATA ERROR
                </h3>

                <p id="errorModalMessage" class="retro-modal-description"
                    style="font-size:0.9rem; color:#475569; line-height:1.5; margin-bottom:20px; text-align:left;">
                </p>

                <div class="flex-center">
                    <button type="button" id="btnDismissError" class="retro-btn retro-btn-primary"
                        style="background:#e11d48; color:white; border:none; padding:10px 25px; border-radius:8px; cursor:pointer; width:100%; font-weight:600;">
                        OK
                    </button>
                </div>
            </div>
        </div>

        <div id="wasteFormModal" class="retro-modal-backdrop"
            style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px); align-items:center; justify-content:center;">
            <div class="retro-modal-card wide-card"
                style="background:white; padding:25px; border-radius:12px; width:450px; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
                <h3 class="retro-modal-title flex-align-center"
                    style="margin-top:0; font-size:1.25rem; font-weight:700; color:#1e293b;">
                    <span class="emoji-space">🗑️</span> Waste / Removal Log
                </h3>
                <p class="retro-modal-sub-description"
                    style="font-size:0.85rem; color:#64748b; margin-bottom:20px; line-height:1.4;">
                    Specify the exact date and time this specific batch was officially wasted or logged out from the
                    active store shelves.
                </p>

                <div class="retro-grid-frame" style="margin-bottom:20px;">
                    <div class="form-field">
                        <label class="field-label"
                            style="font-size:0.8rem; font-weight:600; color:#475569; display:block; margin-bottom:5px;">WASTED
                            / LOGOUT TIMESTAMP</label>
                        <input type="datetime-local" id="txtWasteLoggedAt" name="waste_logged_at"
                            class="retro-input-control"
                            style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                    </div>
                </div>

                <div class="retro-footer-actions" style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" id="btnCancelWasteForm" class="retro-btn-cancel"
                        style="background:#f1f5f9; color:#475569; border:none; padding:8px 16px; border-radius:6px; cursor:pointer;">Cancel</button>
                    <button type="button" id="btnSubmitWasteForm" class="retro-btn retro-btn-primary"
                        style="background:#e11d48; color:white; border:none; padding:8px 20px; border-radius:6px; cursor:pointer; font-weight:600;">Confirm
                        & Save Waste</button>
                </div>
            </div>
        </div>

        <div id="cameraPermissionModal" class="permission-modal">
            <div class="permission-modal-content">
                <div id="permIconBox" class="permission-icon-box"><i class="fas fa-camera"></i></div>
                <h3 style="margin: 0 0 10px 0; font-size: 1.25rem; color: #1e293b;">System Permissions Required</h3>
                <p style="margin: 0; font-size: 0.88rem; color: #64748b; line-height: 1.5;">
                    Allow the website to process image data via OCR for automated logging under Raspberry Pi 3B with
                    Camera Integration.
                </p>
                <div class="toggle-container">
                    <span class="switch-label" id="toggleStatusText">Camera Permission OFF</span>
                    <label class="switch">
                        <input type="checkbox" id="cameraPermToggle"
                            onchange="toggleCameraDatabasePermission(this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>
                <button type="button" class="modal-close-btn" onclick="closePermissionModal()">DONE</button>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const form = document.getElementById("manualProductForm");
        const wasteModal = document.getElementById("wasteFormModal");
        const btnCancelWaste = document.getElementById("btnCancelWasteForm");
        const btnSubmitWaste = document.getElementById("btnSubmitWasteForm");

        if (!form) {
            console.error("BUG ERROR: manualProductForm NOT SEEN.");
            return;
        }

        const btnAddSales = document.getElementById("btnAddingSalesRow");
        if (btnAddSales) {
            btnAddSales.addEventListener("click", addSalesRow);
        }

        function forceSubmitForm() {
            form.dataset.wizardPassed = "true";

            const wasteTimeInput = document.getElementById("txtWasteLoggedAt");
            if (wasteTimeInput && wasteTimeInput.value) {
                const oldHiddenWaste = document.getElementById("hidden_waste_logged_at");
                if (oldHiddenWaste) oldHiddenWaste.remove();

                const hiddenWaste = document.createElement("input");
                hiddenWaste.type = "hidden";
                hiddenWaste.id = "hidden_waste_logged_at";
                hiddenWaste.name = "waste_logged_at";
                hiddenWaste.value = wasteTimeInput.value;
                form.appendChild(hiddenWaste);
            }

            if (!document.getElementById("hidden_save_btn")) {
                const hiddenSubmit = document.createElement("input");
                hiddenSubmit.type = "hidden";
                hiddenSubmit.id = "hidden_save_btn";
                hiddenSubmit.name = "save_btn";
                hiddenSubmit.value = "1";
                form.appendChild(hiddenSubmit);
            }

            form.submit();
        }

        function triggerWasteLogging() {
            if (wasteModal) {
                const baseScanDate = document.getElementById("manual_scanned_date").value || "";
                const wasteTimeInput = document.getElementById("txtWasteLoggedAt");

                if (wasteTimeInput) {
                    wasteTimeInput.value = baseScanDate;
                }
                wasteModal.style.display = "flex";
            }
        }

        if (btnCancelWaste) {
            btnCancelWaste.addEventListener("click", function() {
                wasteModal.style.display = "none";
            });
        }

        if (btnSubmitWaste) {
            btnSubmitWaste.addEventListener("click", function() {
                const wasteTimeInput = document.getElementById("txtWasteLoggedAt");

                if (wasteTimeInput && !wasteTimeInput.value) {
                    alert("❌ Please select a valid date and time before saving.");
                    return;
                }

                wasteModal.style.display = "none";
                forceSubmitForm();
            });
        }

        form.addEventListener("submit", function(e) {
            const actionInput = document.getElementById("manual_action_type");
            const scannedDateInput = document.getElementById("manual_scanned_date");
            const actionType = actionInput ? actionInput.value.toUpperCase() : '';

            if (actionType === 'IN' && scannedDateInput && scannedDateInput.value !== "") {
                const selectedDate = new Date(scannedDateInput.value);
                const now = new Date();

                const pureSelected = new Date(selectedDate.getFullYear(), selectedDate.getMonth(),
                    selectedDate.getDate());
                const pureNow = new Date(now.getFullYear(), now.getMonth(), now.getDate());

                if (pureSelected.getTime() !== pureNow.getTime() && pureSelected < pureNow) {
                    if (!form.dataset.wizardPassed) {
                        e.preventDefault();
                        e.stopImmediatePropagation();

                        const questionModal = document.getElementById("retroQuestionModal");
                        if (questionModal) {
                            questionModal.style.display = "flex";
                        }
                        return false;
                    }
                }
            }
        }, {
            capture: true
        });

        const btnNo = document.getElementById("btnRetroNo");
        if (btnNo) {
            btnNo.addEventListener("click", function() {
                document.getElementById("retroQuestionModal").style.display = "none";
                triggerWasteLogging();
            });
        }

        const btnYes = document.getElementById("btnRetroYes");
        if (btnYes) {
            btnYes.addEventListener("click", function() {
                document.getElementById("retroQuestionModal").style.display = "none";
                document.getElementById("retroFormModal").style.display = "flex";

                const container = document.getElementById("returnRowsContainer");
                if (container && container.children.length === 0) {
                    addReturnRow();
                }
            });
        }

        const chkSold = document.getElementById("chkRetroSold");
        if (chkSold) {
            chkSold.addEventListener("change", function() {
                const container = document.getElementById("salesRowsContainer");
                if (this.checked) {
                    document.getElementById("retroSoldInputs").style.display = "block";
                    if (container && container.children.length === 0) {
                        addSalesRow();
                    }
                } else {
                    document.getElementById("retroSoldInputs").style.display = "none";
                }
            });
        }

        const chkReturn = document.getElementById("chkRetroReturn");
        if (chkReturn) {
            chkReturn.addEventListener("change", function() {
                document.getElementById("retroReturnInputs").style.display = this.checked ? "block" :
                    "none";
            });
        }

        const btnAddRow = document.getElementById("btnAddReturnRow");
        if (btnAddRow) {
            btnAddRow.addEventListener("click", addReturnRow);
        }

        function addSalesRow() {
            const container = document.getElementById("salesRowsContainer");
            if (!container) return;

            const rowId = Date.now();
            const baseScanDate = document.getElementById("manual_scanned_date").value || "";

            const rowHTML = `
            <div class="retro-section-row row-entry-item" id="sales_row_${rowId}" style="position:relative; margin-bottom:15px; padding-top:10px;">
                <span class="remove-row-btn" style="position:absolute; top:0; right:5px; color:#ef4444; cursor:pointer; font-weight:bold; font-size:1.1rem;" onclick="document.getElementById('sales_row_${rowId}').remove()">✕</span>
                <div class="retro-input-split">
                    <div class="form-field">
                        <label class="field-label">QUANTITY SOLD</label>
                        <input type="number" name="retro_sold_qty[]" value="1" min="1" class="retro-input-control">
                    </div>
                    <div class="form-field">
                        <label class="field-label">SOLD TIMESTAMP</label>
                        <input type="datetime-local" name="retro_sold_at[]" value="${baseScanDate}" class="retro-input-control">
                    </div>
                </div>
            </div>
        `;
            container.insertAdjacentHTML('beforeend', rowHTML);
        }

        function addReturnRow() {
            const container = document.getElementById("returnRowsContainer");
            if (!container) return;

            const rowId = Date.now();
            const baseScanDate = document.getElementById("manual_scanned_date").value || "";

            const rowHTML = `
            <div class="retro-section-row row-entry-item" id="return_row_${rowId}" style="position:relative; margin-bottom:15px; padding-top:10px;">
                <span class="remove-row-btn" style="position:absolute; top:0; right:5px; color:#ef4444; cursor:pointer; font-weight:bold; font-size:1.1rem;" onclick="document.getElementById('return_row_${rowId}').remove()">✕</span>
                
                <div class="retro-input-split" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                    <div class="form-field" style="flex:1; min-width:120px;">
                        <label class="field-label">RETURN QUANTITY</label>
                        <input type="number" name="retro_return_qty[]" value="1" min="1" class="retro-input-control">
                    </div>
                    <div class="form-field" style="flex:1; min-width:160px;">
                        <label class="field-label">RETURNED AT</label>
                        <input type="datetime-local" name="retro_return_at[]" value="${baseScanDate}" class="retro-input-control">
                    </div>
                </div>
                
                <div class="form-field" style="width:100%;">
                    <label class="field-label">REASON</label>
                    <select name="retro_return_reason[]" class="retro-input-control" style="width:100%;">
                        <option value="Expired upon Purchase">Expired upon Purchase</option>
                        <option value="Damaged Packaging">Damaged Packaging</option>
                        <option value="Customer Return">Customer Return</option>
                        <option value="Staff Error / Reconciled">Staff Error / Reconciled</option>
                    </select>
                </div>
            </div>
        `;
            container.insertAdjacentHTML('beforeend', rowHTML);
        }

        const btnCancel = document.getElementById("btnCancelRetroForm");
        if (btnCancel) {
            btnCancel.addEventListener("click", function() {
                document.getElementById("retroFormModal").style.display = "none";
                delete form.dataset.wizardPassed;
            });
        }

        const btnDismissError = document.getElementById("btnDismissError");
        if (btnDismissError) {
            btnDismissError.addEventListener("click", function() {
                document.getElementById("retroErrorModal").style.display = "none";
            });
        }

        const btnSubmitForm = document.getElementById("btnSubmitRetroForm");
        if (btnSubmitForm) {
            btnSubmitForm.addEventListener("click", function() {
                const mainQtyInput = document.getElementById("manual_quantity");
                const mainQty = mainQtyInput ? parseInt(mainQtyInput.value, 10) || 0 : 0;

                let totalHistoricalQty = 0;

                const chkSoldActive = document.getElementById("chkRetroSold");
                if (chkSoldActive && chkSoldActive.checked) {
                    const soldQtyInputs = document.querySelectorAll("input[name='retro_sold_qty[]']");
                    soldQtyInputs.forEach(function(input) {
                        totalHistoricalQty += parseInt(input.value, 10) || 0;
                    });
                }

                const chkReturnActive = document.getElementById("chkRetroReturn");
                if (chkReturnActive && chkReturnActive.checked) {
                    const returnQtyInputs = document.querySelectorAll(
                        "input[name='retro_return_qty[]']");
                    returnQtyInputs.forEach(function(input) {
                        totalHistoricalQty += parseInt(input.value, 10) || 0;
                    });
                }

                if (totalHistoricalQty > mainQty) {
                    const errorMsgEl = document.getElementById("errorModalMessage");
                    if (errorMsgEl) {
                        errorMsgEl.innerHTML =
                            `Cannot process submission! The combined Sold and Return quantity (<b>${totalHistoricalQty} pcs</b>) exceeds the declared Stock Entry Quantity (<b>${mainQty} pcs</b>) for this batch.<br><br>Please reconcile the quantities before saving.`;
                    }
                    document.getElementById("retroErrorModal").style.display = "flex";
                    return;
                }

                document.getElementById("retroFormModal").style.display = "none";
                forceSubmitForm();
            });
        }

    });
    </script>

    <script>
    // ══════════════════════════════════════════════════
    //  CONFIG
    // ══════════════════════════════════════════════════
    const FRAME_URL = 'pi_frame.php'; // serves latest JPEG or JSON status
    const STATUS_URL = 'pi_status.php'; // online / offline
    const SCAN_URL = 'get_live_scan.php'; // product_name / expiry_date from Pi

    let piOnline = false;
    let isCapturing = false;
    let frameInterval = null;
    let lastBlobUrl = null; // revoke previous object URL to free memory
    let currentMode = 'scan';

    // ══════════════════════════════════════════════════
    //  PI STATUS POLLING  (every 3 s)
    // ══════════════════════════════════════════════════
    function pollPiStatus() {
        fetch(STATUS_URL + '?_=' + Date.now())
            .then(r => r.json())
            .then(data => {
                piOnline = data.online;
                const dot = document.getElementById('piDot');
                const text = document.getElementById('piStatusText');
                const seen = document.getElementById('piLastSeen');

                if (piOnline) {
                    dot.className = 'online';
                    text.textContent = 'Raspberry Pi Connected ✓';
                    text.style.color = '#16a34a';
                    seen.textContent = 'Last frame: ' + (data.last_seen || '—');
                    setPiScanStatus('Pi connected — press F (product) or B (expiry) to capture.', true);
                    if (!frameInterval) startFramePolling();
                } else {
                    dot.className = 'offline';
                    text.textContent = 'Raspberry Pi Offline';
                    text.style.color = '#ef4444';
                    const ago = data.age_seconds < 9000 ?
                        data.last_seen + ' (' + data.age_seconds + 's ago)' :
                        (data.last_seen !== 'never' ? data.last_seen : 'Never connected');
                    seen.textContent = 'Last seen: ' + ago;
                    setPiScanStatus('Pi is offline. Start main.py on your Raspberry Pi.', false);
                    stopFramePolling();
                }
            })
            .catch(() => {
                piOnline = false;
                document.getElementById('piDot').className = 'offline';
                setPiScanStatus('Cannot reach server.', false);
            });
    }

    // ══════════════════════════════════════════════════
    //  FRAME POLLING  (every 300 ms while Pi is online)
    //  The server returns either image/jpeg OR
    //  application/json (stale / no_frame).
    // ══════════════════════════════════════════════════
    function startFramePolling() {
        if (frameInterval) return;
        frameInterval = setInterval(fetchFrame, 300);
    }

    function stopFramePolling() {
        if (frameInterval) {
            clearInterval(frameInterval);
            frameInterval = null;
        }
        const img = document.getElementById('cameraFeedImg');
        const off = document.getElementById('cameraFeedOff');
        img.style.display = 'none';
        off.style.display = 'flex';
    }

    function fetchFrame() {
        const toggleInput = document.getElementById('cameraPermToggle');
        if (toggleInput && !toggleInput.checked) {
            stopFramePolling();
            return;
        }

        const img = document.getElementById('cameraFeedImg');
        const off = document.getElementById('cameraFeedOff');

        fetch(FRAME_URL + '?_=' + Date.now())
            .then(response => {
                const ct = response.headers.get('Content-Type') || '';

                // Server returned JSON → stale or no_frame
                if (ct.includes('application/json')) {
                    return response.json().then(j => {
                        // Don't hide the last good frame immediately on one stale response;
                        // wait for the status poller to mark Pi offline.
                        if (j.status === 'no_frame') {
                            img.style.display = 'none';
                            off.style.display = 'flex';
                        }
                        return null; // signal: no blob to show
                    });
                }

                // Server returned JPEG
                if (!response.ok) return null;
                return response.blob();
            })
            .then(blob => {
                if (!blob) return;

                // Revoke previous object URL to avoid memory leaks
                if (lastBlobUrl) {
                    URL.revokeObjectURL(lastBlobUrl);
                    lastBlobUrl = null;
                }

                lastBlobUrl = URL.createObjectURL(blob);
                img.src = lastBlobUrl;
                img.style.display = 'block';
                off.style.display = 'none';
            })
            .catch(() => {
                // Silently skip bad frames
            });
    }

    // ══════════════════════════════════════════════════
    //  SCAN RESULT & LIVE POLLING (Every 1.5 s)
    // ══════════════════════════════════════════════════
    let lastScanTimestamp = '';

    function pollScanResult() {
        const scanPanel = document.getElementById('scanPanel');
        if (!scanPanel || scanPanel.style.display === 'none') return;

        fetch(SCAN_URL + '?_=' + Date.now())
            .then(r => r.json())
            .then(data => {
                const nameInput = document.getElementById('product_name');
                const expiryInput = document.getElementById('expiry_date');
                const detailsContent = document.getElementById('scanDetailsContent');
                const currentAction = document.getElementById('scan_action_type').value;

                if (!data.success || !data.product_name || data.scan_status === 'USED' || data.product_name
                    .trim() === '') {
                    if (nameInput.value !== '') {
                        nameInput.value = '';
                        nameInput.placeholder = 'Waiting for scan…';
                        expiryInput.value = '';

                        lastScanTimestamp = '';

                        if (detailsContent) {
                            detailsContent.innerHTML =
                                "Product Name: --<br>Quantity: --<br>Expiration Date: --<br>Action: " + esc(
                                    currentAction);
                        }
                    }
                    return;
                }

                if (data.scan_status === 'READY' && data.updated_at !== lastScanTimestamp) {
                    lastScanTimestamp = data.updated_at;
                    // Show pipeline stage in the status bar
                    const stageMessages = {
                        'front_done': '✓ Front scanned — product name received. Now scan the BACK label.',
                        'back_done':  '✓ Back scanned — expiry date received. Now scan the FRONT label.',
                        'complete':   '✓ Both labels scanned! Review details and confirm.',
                        'idle':       'Waiting for Pi...',
                    };
                    if (data.pipeline && stageMessages[data.pipeline]) {
                        setPiScanStatus(stageMessages[data.pipeline], data.pipeline !== 'idle');
                    }

                    if (data.product_name && data.product_name.trim() !== '') {
                        nameInput.value = data.product_name;
                        setPiScanStatus('✓ Product name filled: "' + data.product_name + '"', true);
                        isCapturing = false;
                        resetCaptureButtons();
                    }
                    if (data.expiry_date && data.expiry_date.trim() !== '') {
                        expiryInput.value = data.expiry_date;
                        setPiScanStatus('✓ Expiry date filled: ' + data.expiry_date, true);
                        isCapturing = false;
                        resetCaptureButtons();
                    }

                    const q = document.getElementById('scan_quantity').value || '1';
                    const r = document.getElementById('scan_return_reason').value || '';
                    updatePreview('scan', currentAction, r);
                }
            })
            .catch(err => console.log("Polling error: ", err));
    }
    // ══════════════════════════════════════════════════
    //  CAPTURE TRIGGER (button click or F/B key)
    // ══════════════════════════════════════════════════
    function triggerCapture(mode) {
        if (!piOnline) {
            alert('Raspberry Pi is offline. Start main.py first.');
            return;
        }
        if (isCapturing) return;

        isCapturing = true;
        const feedback = document.getElementById('captureFeedback');
        document.getElementById('btnFront').disabled = true;
        document.getElementById('btnBack').disabled = true;

        feedback.textContent = mode === 'FRONT' ?
            '📸 Press "f" on the Pi keyboard to scan product name…' :
            '📸 Press "b" on the Pi keyboard to scan expiry date…';
        feedback.style.display = 'block';

        setPiScanStatus(
            mode === 'FRONT' ?
            'Waiting for Pi to capture FRONT label…' :
            'Waiting for Pi to capture BACK label…',
            true
        );

        setTimeout(() => {
            isCapturing = false;
            feedback.style.display = 'none';
            resetCaptureButtons();
        }, 20000);
    }

    function resetCaptureButtons() {
        document.getElementById('btnFront').disabled = false;
        document.getElementById('btnBack').disabled = false;
    }

    // ══════════════════════════════════════════════════
    //  KEYBOARD SHORTCUTS  F / B
    // ══════════════════════════════════════════════════
    document.addEventListener('keydown', function(e) {
        const scanPanel = document.getElementById('scanPanel');
        if (!scanPanel || scanPanel.style.display === 'none') return;
        const tag = document.activeElement.tagName.toLowerCase();
        if (['input', 'textarea', 'select'].includes(tag)) return;

        if (e.key.toLowerCase() === 'f') {
            e.preventDefault();
            triggerCapture('FRONT');
        } else if (e.key.toLowerCase() === 'b') {
            e.preventDefault();
            triggerCapture('BACK');
        }
    });

    // ══════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════
    function setPiScanStatus(msg, ok) {
        const el = document.getElementById('piScanStatus');
        el.textContent = msg;
        el.style.background = ok ? '#f0fdf4' : '#fef2f2';
        el.style.color = ok ? '#166534' : '#991b1b';
    }

    // ══════════════════════════════════════════════════
    //  PANEL / FORM LOGIC
    // ══════════════════════════════════════════════════
    let currentReturnMode = 'scan';

    document.addEventListener('DOMContentLoaded', function() {
        pollPiStatus();
        setInterval(pollPiStatus, 3000);
        setInterval(pollScanResult, 1500);

        let isRefresh = false;
        if (window.performance && window.performance.getEntriesByType) {
            const navs = window.performance.getEntriesByType('navigation');
            if (navs.length > 0 && navs[0].type === 'reload') {
                isRefresh = true;
            }
        } else {
            if (window.performance && window.performance.navigation.type === 1) {
                isRefresh = true;
            }
        }

        const savedTab = sessionStorage.getItem('selectedTab');
        const phpPanel = "<?= htmlspecialchars($active_panel ?? '') ?>";

        let targetPanel = 'scan';

        if (isRefresh && savedTab) {
            targetPanel = savedTab;
        } else {
            sessionStorage.removeItem('selectedTab');
            if (phpPanel && phpPanel.trim() !== '') {
                targetPanel = phpPanel;
            } else {
                targetPanel = 'scan';
            }
        }

        showPanel(targetPanel);
        bindPreviewEvents();

        setTimeout(function() {
            if (targetPanel === 'scan') {
                checkCameraSystemPermission();
            }
        }, 100);
    });

    function showPanel(t) {
        sessionStorage.setItem('selectedTab', t);

        const scanPanel = document.getElementById('scanPanel');
        const manualPanel = document.getElementById('manualPanel');
        const piStatusBar = document.getElementById('piStatusBar');

        scanPanel.style.display = t === 'scan' ? 'block' : 'none';
        manualPanel.style.display = t === 'manual' ? 'block' : 'none';

        if (piStatusBar) {
            piStatusBar.style.display = t === 'scan' ? 'flex' : 'none';
        }

        document.querySelectorAll('.top-btn').forEach((b, i) => {
            b.classList.toggle('active', (i === 0 && t === 'scan') || (i === 1 && t === 'manual'));
        });

        if (t === 'scan') {
            checkCameraSystemPermission();
        }
    }

    function changeQty(id, d) {
        const el = document.getElementById(id);
        el.value = Math.max(1, (parseInt(el.value) || 1) + d);
        const m = id.startsWith('scan') ? 'scan' : 'manual';
        updatePreview(m, document.getElementById(m + '_action_type').value,
            document.getElementById(m + '_return_reason').value || '');
    }

    function setAction(mode, action, btn) {
        currentReturnMode = mode;
        document.getElementById(mode + '_action_type').value = action;
        btn.parentElement.querySelectorAll('.action-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        if (action === 'RETURN') {
            document.getElementById('returnModal').style.display = 'block';
        } else {
            document.getElementById(mode + '_return_reason').value = '';
            updatePreview(mode, action, '');
        }
    }

    function beforeSubmit(mode) {
        const nameInput = document.getElementById('product_name');
        const expiryInput = document.getElementById('expiry_date');
        const qtyInput = document.getElementById('scan_quantity');
        const detailsContent = document.getElementById('scanDetailsContent');
        const scanStatus = document.getElementById('piScanStatus');

        const currentAction = document.getElementById('scan_action_type').value;

        if (mode === 'scan') {
            if (!nameInput.value || !expiryInput.value) {
                alert("Please scan product name and expiry date first.");
                return false;
            }

            setTimeout(() => {
                nameInput.value = '';
                nameInput.placeholder = 'Waiting for scan…';
                expiryInput.value = '';
                qtyInput.value = '1';
                lastScanTimestamp = '';

                if (detailsContent) {
                    detailsContent.innerHTML =
                        "Product Name: --<br>Quantity: --<br>Expiration Date: --<br>Action: " + esc(
                            currentAction);
                }
                if (scanStatus) {
                    scanStatus.textContent = "Waiting for scan…";
                }
            }, 50);
        }
        return true;
    }

    function confirmReturnReason() {
        const r = document.getElementById('reasonSelect').value;
        document.getElementById(currentReturnMode + '_return_reason').value = r;
        document.getElementById('returnModal').style.display = 'none';
        updatePreview(currentReturnMode,
            document.getElementById(currentReturnMode + '_action_type').value, r);
    }

    function closeReturnModal() {
        document.getElementById('returnModal').style.display = 'none';
    }

    function updatePreview(mode, action, reason) {
        const nm = mode === 'scan' ? 'product_name' : 'manual_product_name';
        const qm = mode === 'scan' ? 'scan_quantity' : 'manual_quantity';
        const em = mode === 'scan' ? 'expiry_date' : 'manual_exp_date';
        const n = document.getElementById(nm).value || '--';
        const q = document.getElementById(qm).value || '--';
        const e = document.getElementById(em).value || '--';

        let html = `Product Name: ${esc(n)}<br>Quantity: ${esc(q)}<br>Expiration Date: ${esc(e)}<br>`;

        if (mode === 'manual') {
            const dateScanned = document.getElementById('manual_scanned_date')?.value;
            const dateDisplay = dateScanned ? dateScanned.replace('T', ' ') : 'Real-Time (Now)';
            html += `Date Scanned: <strong>${esc(dateDisplay)}</strong><br>`;
        }

        html += `Action: ${esc(action)}`;
        if (action === 'RETURN' && reason) html += `<br><strong style="color:#e74c3c;">Reason: ${esc(reason)}</strong>`;

        document.getElementById(mode + 'DetailsContent').innerHTML = html;
        if (mode === 'scan') {
            document.getElementById(mode + 'DetailsCard').style.display = 'flex';
        } else {
            document.getElementById(mode + 'DetailsCard').classList.add('show');
        }
    }

    function bindPreviewEvents() {
        ['manual_product_name', 'manual_quantity', 'manual_exp_date', 'manual_scanned_date'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', () =>
                updatePreview('manual', document.getElementById('manual_action_type').value,
                    document.getElementById('manual_return_reason').value || ''));
        });

        document.getElementById('manual_scanned_date')?.addEventListener('change', () =>
            updatePreview('manual', document.getElementById('manual_action_type').value,
                document.getElementById('manual_return_reason').value || ''));

        ['product_name', 'scan_quantity', 'expiry_date'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', () =>
                updatePreview('scan', document.getElementById('scan_action_type').value,
                    document.getElementById('scan_return_reason').value || ''));
        });
    }

    function esc(v) {
        return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function checkCameraSystemPermission() {
        fetch('php/check_and_update_permission?action=get&_=' + Date.now())
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const isGranted = data.camera_permission === 1;

                    const toggleInput = document.getElementById('cameraPermToggle');
                    const statusText = document.getElementById('toggleStatusText');
                    const iconBox = document.getElementById('permIconBox');

                    if (toggleInput) toggleInput.checked = isGranted;

                    if (isGranted) {
                        if (statusText) {
                            statusText.textContent = "Camera Permission ON";
                            statusText.style.color = "#10b981";
                        }
                        if (iconBox) iconBox.className = "permission-icon-box active-status";
                    } else {
                        if (statusText) {
                            statusText.textContent = "Camera Permission OFF";
                            statusText.style.color = "#475569";
                        }
                        if (iconBox) iconBox.className = "permission-icon-box";

                        const modal = document.getElementById('cameraPermissionModal');
                        if (modal) modal.style.display = 'block';
                        stopFramePolling();
                    }
                }
            })
            .catch(err => console.error("Error fetching application logs permission:", err));
    }

    function toggleCameraDatabasePermission(isChecked) {
        const valueToSend = isChecked ? 1 : 0;

        const formData = new URLSearchParams();
        formData.append('camera_permission', valueToSend);

        fetch('php/check_and_update_permission?action=update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const statusText = document.getElementById('toggleStatusText');
                    const iconBox = document.getElementById('permIconBox');

                    if (data.camera_permission === 1) {
                        statusText.textContent = "Camera Permission ON";
                        statusText.style.color = "#10b981";
                        iconBox.className = "permission-icon-box active-status";

                        if (piOnline && !frameInterval) startFramePolling();
                    } else {
                        statusText.textContent = "Camera Permission OFF";
                        statusText.style.color = "#475569";
                        iconBox.className = "permission-icon-box";
                        stopFramePolling();
                    }
                } else {
                    alert("Database integration error: " + data.message);
                }
            })
            .catch(err => console.error("Error tracking permission update:", err));
    }

    function closePermissionModal() {
        const toggleInput = document.getElementById('cameraPermToggle');

        if (!toggleInput || !toggleInput.checked) {
            alert("Camera scanner is disabled. Switching to Manual Input tab.");

            document.getElementById('cameraPermissionModal').style.display = 'none';

            showPanel('manual');
            return;
        }

        document.getElementById('cameraPermissionModal').style.display = 'none';
    }
    </script>

</body>

</html>