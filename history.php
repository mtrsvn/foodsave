<?php
include 'auth.php';
include 'db.php';
include 'php/auto_waste_sync.php';
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

$user_id = $_SESSION['user_id'];

$view = isset($_GET['view']) ? $_GET['view'] : 'IN';
$allowed_views = ['IN', 'SOLD', 'RETURN', 'WASTED'];
if (!in_array($view, $allowed_views)) { $view = 'IN'; }

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'RECENT';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

$date_column = [
    'IN'     => 'h.created_at',
    'SOLD'   => 'h.created_at',      
    'RETURN' => 'h.created_at',  
    'WASTED' => 'h.created_at'
][$view];

$where_clause = "WHERE h.type = ? AND h.user_id = ?";
$params = [$view, $user_id];
$types = "si"; 

if (!empty($search)) {
    $where_clause .= " AND (h.product_name LIKE ? OR p.name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if ($filter !== 'RECENT' && $filter !== 'CUSTOM') {
    $interval = ['3DAYS' => '3 DAY', '1WEEK' => '7 DAY', '1MONTH' => '1 MONTH'][$filter];
    $where_clause .= " AND $date_column >= DATE_SUB(NOW(), INTERVAL $interval)";
} elseif ($filter === 'CUSTOM' && $start_date && $end_date) {
    $where_clause .= " AND DATE($date_column) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

// PAGINATION 
$limit = 10; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$count_query = "SELECT COUNT(*) as total FROM history h LEFT JOIN products p ON h.product_id = p.product_id $where_clause";
$c_stmt = $conn->prepare($count_query);
$c_stmt->bind_param($types, ...$params);
$c_stmt->execute();
$total_rows = $c_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// MAIN QUERY
$query = "SELECT 
    h.history_id, h.user_id, h.product_id, h.product_name, h.quantity, 
    h.type, h.return_reason, h.created_at as log_date,
    p.name as display_name, p.price, p.date_scanned, p.expiry_date,
    (h.quantity * COALESCE(p.price, 0)) as computed_total_price,
    
    -- Inayos para makuha ang pinakahuling benta ng produktong ito nang hindi nakatali sa history_id ng return
    (SELECT sold_at FROM sold WHERE product_id = h.product_id ORDER BY sold_at DESC LIMIT 1) as latest_sold_at,
    (SELECT returned_at FROM returned WHERE product_id = h.product_id AND returned_id = h.history_id ORDER BY returned_at DESC LIMIT 1) as latest_returned_at,
    (SELECT wasted_at FROM wasted WHERE product_id = h.product_id AND wasted_id = h.history_id ORDER BY wasted_at DESC LIMIT 1) as latest_wasted_at,
    
    CASE 
        WHEN h.type = 'WASTED' AND h.return_reason IS NOT NULL AND h.return_reason != '' THEN 'RETURNED'
        WHEN h.type = 'WASTED' THEN 'EXPIRED'
        ELSE h.return_reason 
    END as dynamic_reason,

    CASE 
        WHEN h.type = 'SOLD' THEN COALESCE((SELECT sold_at FROM sold WHERE product_id = h.product_id AND sold_id = h.history_id ORDER BY sold_at DESC LIMIT 1), h.created_at)
        WHEN h.type = 'RETURN' THEN COALESCE((SELECT returned_at FROM returned WHERE product_id = h.product_id AND returned_id = h.history_id ORDER BY returned_at DESC LIMIT 1), h.created_at)
        WHEN h.type = 'WASTED' THEN COALESCE((SELECT wasted_at FROM wasted WHERE product_id = h.product_id AND wasted_id = h.history_id ORDER BY wasted_at DESC LIMIT 1), h.created_at)
        ELSE h.created_at 
    END as sorting_date
    
FROM history h
LEFT JOIN products p ON h.product_id = p.product_id

$where_clause 
GROUP BY h.history_id
ORDER BY sorting_date DESC, h.history_id DESC
LIMIT ?, ?";

$stmt = $conn->prepare($query);

$final_types = $types . "ii"; 
$final_params = array_merge($params, [$offset, $limit]);

$stmt->bind_param($final_types, ...$final_params);
$stmt->execute();
$result = $stmt->get_result();

$active_color = '#6b8e61'; 
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Stock Activity - FoodSave</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/history.css">
    <style>
    .inventory-container {
        background: var(--active-theme);
        padding: 25px;
        border-radius: <?=($view=='IN') ? '25px': '25px'?> 25px 25px 25px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        position: relative;
        z-index: 1;
    }

    .custom-dates {
        display: <?=($filter==='CUSTOM') ? 'flex': 'none'?>;
        align-items: center;
        gap: 5px;
    }
    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>

    <main>
        <div class="header-card">
            <h2>Stock Activity</h2>
            <p>Monitor logs for: <span style="color: var(--primary-green); font-weight: 700;"><?= $view ?></span></p>
        </div>

        <nav class="history-nav">
            <a href="history.php?view=IN" class="nav-item <?= ($view == 'IN') ? 'active' : '' ?>">IN</a>
            <a href="history.php?view=SOLD" class="nav-item <?= ($view == 'SOLD') ? 'active' : '' ?>">SOLD</a>
            <a href="history.php?view=RETURN" class="nav-item <?= ($view == 'RETURN') ? 'active' : '' ?>">RETURN</a>
            <a href="history.php?view=WASTED" class="nav-item <?= ($view == 'WASTED') ? 'active' : '' ?>">WASTED</a>
        </nav>

        <div class="inventory-container">
            <div class="filter-inside">
                <button type="button" onclick="openExportModal()" class="btn-export">
                    <i class="fas fa-file-export"></i> EXPORT
                </button>
                <form action="history.php" method="GET" class="filter-form" style="display: flex; gap: 10px;">
                    <input type="hidden" name="view" value="<?= $view ?>">

                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search product..."
                            value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <div
                        style="display: flex; align-items: center; gap: 8px; background: rgba(255, 255, 255, 0.15); padding: 8px 15px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <label style="color: white; font-weight: 700; font-size: 0.75rem; white-space: nowrap;">FILTER
                            BY:</label>
                        <select name="filter" id="filterSelect" onchange="toggleCustomDates()">
                            <option value="RECENT" <?= ($filter == 'RECENT') ? 'selected' : '' ?>>Recent</option>
                            <option value="3DAYS" <?= ($filter == '3DAYS') ? 'selected' : '' ?>>3 Days Ago</option>
                            <option value="1WEEK" <?= ($filter == '1WEEK') ? 'selected' : '' ?>>1 Week Ago</option>
                            <option value="1MONTH" <?= ($filter == '1MONTH') ? 'selected' : '' ?>>1 Month Ago</option>
                            <option value="CUSTOM" <?= ($filter == 'CUSTOM') ? 'selected' : '' ?>>Custom Range</option>
                        </select>

                        <div id="customDates" class="custom-dates">
                            <input type="date" name="start_date" value="<?= $start_date ?>">
                            <span style="color: white; font-size: 0.8rem;">to</span>
                            <input type="date" name="end_date" value="<?= $end_date ?>">
                        </div>
                        <button type="submit" class="btn-filter" style="background: #8dae84; color: white;">Apply</button>
                    </div>
                </form>
            </div>

            <table class="inventory-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <?php if ($view == 'IN'): ?>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Date Scanned</th>
                        <th>Expiry Date</th>
                        <?php elseif ($view == 'SOLD'): ?>
                        <th>Quantity</th>
                        <th>Total Price</th>
                        <th>Date Scanned</th>
                        <th>Expiry Date</th>
                        <th>Date Sold</th>
                        <?php elseif ($view == 'RETURN'): ?>
                        <th>Quantity</th>
                        <th>Total Price</th>
                        <th>Date Scanned</th>
                        <th>Expiry Date</th>
                        <th>Date Sold</th>
                        <th>Date Returned</th>
                        <th>Reason</th>
                        <?php elseif ($view == 'WASTED'): ?>
                        <th>Quantity</th>
                        <th>Total Price</th>
                        <th>Date Scanned</th>
                        <th>Expiry Date</th>
                        <th>Logged Out</th>
                        <th>Reason</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <?php if ($result && $result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): 
                    $date_scanned = !empty($row['date_scanned']) ? $row['date_scanned'] : $row['log_date'];
                    $expiry_date = !empty($row['expiry_date']) ? $row['expiry_date'] : 'N/A';
    
                    $main_date = $row['sorting_date'];
                    if($view == 'SOLD' && !empty($row['sold_at'])) $main_date = $row['sold_at'];
                    if($view == 'RETURN' && !empty($row['returned_at'])) $main_date = $row['returned_at'];
                    if($view == 'WASTED' && !empty($row['wasted_at'])) $main_date = $row['wasted_at'];
                ?>
                <tr>
                    <td style="font-weight: 700;">
                        <?= htmlspecialchars($row['display_name'] ?: 'Unknown Product') ?>
                    </td>

                    <?php if ($view == 'IN'): ?>
                    <td><span class="qty-display"><?= $row['quantity'] ?></span><span class="qty-unit">PCS</span></td>
                    <td><span class="price-text price-in">₱<?= number_format($row['price'], 2) ?></span></td>
                    <td><?= date('M d, Y', strtotime($row['log_date'])) ?><span class="date-subtext"><i
                                class="far fa-clock"></i> <?= date('H:i', strtotime($row['log_date'])) ?></span></td>
                    <td><span
                            style="color: #dc2626; font-weight: 600;"><?= ($expiry_date != 'N/A') ? date('M d, Y', strtotime($expiry_date)) : 'N/A' ?></span>
                    </td>

                    <?php elseif ($view == 'SOLD'): ?>
                    <td><span class="qty-display"><?= $row['quantity'] ?></span><span class="qty-unit">PCS</span></td>
                    <td><span
                            class="price-text price-sold">₱<?= number_format($row['computed_total_price'], 2) ?></span>
                    </td>
                    <td><?= date('M d, Y', strtotime($date_scanned)) ?><span class="date-subtext"><i
                                class="far fa-clock"></i> <?= date('H:i', strtotime($date_scanned)) ?></span></td>
                    <td><span
                            style="color: #dc2626; font-weight: 600;"><?= ($expiry_date != 'N/A') ? date('M d, Y', strtotime($expiry_date)) : 'N/A' ?></span>
                    </td>
                    <td><span
                            style="color: var(--active-theme); font-weight: 700;"><?= date('M d, Y', strtotime($main_date)) ?></span><span
                            class="date-subtext"><i class="fas fa-shopping-cart"></i>
                            <?= date('H:i', strtotime($main_date)) ?></span></td>

                    <?php elseif ($view == 'RETURN'): ?>
                    <td><span class="qty-display"><?= $row['quantity'] ?></span><span class="qty-unit">PCS</span></td>
                    <td><span
                            class="price-text price-return">₱<?= number_format($row['computed_total_price'], 2) ?></span>
                    </td>
                    <td><?= date('M d, Y', strtotime($date_scanned)) ?><span class="date-subtext"><i
                                class="far fa-clock"></i> <?= date('H:i', strtotime($date_scanned)) ?></span></td>
                    <td><span
                            style="color: #dc2626; font-weight: 600;"><?= ($expiry_date != 'N/A') ? date('M d, Y', strtotime($expiry_date)) : 'N/A' ?></span>
                    </td>

                    <td>
                        <?php 
                        $display_sold_date = !empty($row['sold_at']) ? $row['sold_at'] : $row['latest_sold_at'];
            
                        if(!empty($display_sold_date)): 
                        ?>
                        <?= date('M d, Y', strtotime($display_sold_date)) ?>
                        <span class="date-subtext">
                            <i class="fas fa-shopping-cart"></i> <?= date('H:i', strtotime($display_sold_date)) ?>
                        </span>
                        <?php else: ?>
                        <span style="color: #999;">Not Recorded</span>
                        <?php endif; ?>
                    </td>
                    <td><span
                            style="color: var(--active-theme); font-weight: 700;"><?= date('M d, Y', strtotime($main_date)) ?></span><span
                            class="date-subtext"><i class="fas fa-undo"></i>
                            <?= date('H:i', strtotime($main_date)) ?></span></td>
                    <td class="col-reason"><span
                            class="status-pill pill-warning"><?= htmlspecialchars($row['return_reason'] ?? 'No Reason') ?></span>
                    </td>

                    <?php elseif ($view == 'WASTED'): ?>
                    <td><span class="qty-display"><?= $row['quantity'] ?></span><span class="qty-unit">PCS</span></td>
                    <td><span
                            class="price-text price-wasted">₱<?= number_format($row['computed_total_price'], 2) ?></span>
                    </td>
                    <td><?= date('M d, Y', strtotime($date_scanned)) ?><span class="date-subtext"><i
                                class="far fa-clock"></i> <?= date('H:i', strtotime($date_scanned)) ?></span></td>
                    <td><span
                            style="color: #dc2626; font-weight: 600;"><?= ($expiry_date != 'N/A') ? date('M d, Y', strtotime($expiry_date)) : 'N/A' ?></span>
                    </td>
                    <td><span
                            style="color: #dc2626; font-weight: 700;"><?= date('M d, Y', strtotime($main_date)) ?></span><span
                            class="date-subtext"><i class="fas fa-trash-alt"></i>
                            <?= date('H:i', strtotime($main_date)) ?></span></td>
                    <td class="col-reason">
                        <?php 
                        $reason = $row['dynamic_reason'];
                        $pill_class = ($reason == 'EXPIRED') ? 'pill-danger' : 'pill-warning';
                        ?>
                        <span class="status-pill <?= $pill_class ?>"
                            style="text-transform: uppercase; font-weight: 800; font-size: 0.7rem;">
                            <?= htmlspecialchars($reason) ?>
                        </span>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="11" style="text-align: center; padding: 60px;">No records found.</td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>

            <div class="pagination-wrapper"
                style="display: flex; flex-direction: column; align-items: center; gap: 10px; margin-top: 25px; padding-bottom: 20px;">
                <div class="pagination-info" style="font-size: 0.85rem; color: rgba(255,255,255,0.7);">
                    Showing <b><?= ($total_rows > 0) ? $offset + 1 : 0 ?></b> to
                    <b><?= min($offset + $limit, $total_rows) ?></b> of <b><?= $total_rows ?></b> entries
                </div>

                <div class="pagination-controls" style="display: flex; align-items: center; gap: 8px;">
                    <?php 
                    $query_params = $_GET;
                    $query_params['page'] = '';
        
                    function buildPaginationUrl($page_num) {
                    global $query_params;
                    $params = $query_params;
                    $params['page'] = $page_num;
                    return 'history.php?' . http_build_query($params);
                    }
                    ?>

                    <a href="<?= buildPaginationUrl(1) ?>" class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>"><i
                            class="fas fa-angle-double-left"></i></a>
                    <a href="<?= buildPaginationUrl(max(1, $page - 1)) ?>"
                        class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>">PREV</a>

                    <div class="page-numbers" style="display: flex; gap: 5px;">
                        <?php 
                        $range = 2;
                    for($i = 1; $i <= $total_pages; $i++): 
                        if($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)): ?>
                        <a href="<?= buildPaginationUrl($i) ?>"
                            class="num-btn <?= ($page == $i) ? 'active' : '' ?>"><?= $i ?></a>
                        <?php elseif($i == $page - $range - 1 || $i == $page + $range + 1): ?>
                        <span style="color: white;">...</span>
                        <?php endif; 
                    endfor; ?>
                    </div>

                    <a href="<?= buildPaginationUrl(min($total_pages, $page + 1)) ?>"
                        class="page-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>">NEXT</a>
                    <a href="<?= buildPaginationUrl($total_pages) ?>"
                        class="page-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>"><i
                            class="fas fa-angle-double-right"></i></a>
                </div>
            </div>
        </div>
    </main>

    <div id="exportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-export"></i> Export Report</h3>
                <span class="close-modal" onclick="closeExportModal()">&times;</span>
            </div>

            <form action="process_export" method="POST" target="_blank">
                <div class="export-option-group">
                    <label>Report Type</label>
                    <select name="report_type" id="reportType">
                        <option value="ALL">All History</option>
                        <option value="IN" <?= ($view == 'IN') ? 'selected' : '' ?>>Stock In Only</option>
                        <option value="SOLD" <?= ($view == 'SOLD') ? 'selected' : '' ?>>Sold Products Only</option>
                        <option value="RETURN" <?= ($view == 'RETURN') ? 'selected' : '' ?>>Returns Only</option>
                        <option value="WASTED" <?= ($view == 'WASTED') ? 'selected' : '' ?>>Wasted Only</option>
                    </select>
                </div>

                <div class="export-option-group">
                    <label>Date Range</label>
                    <select name="date_preset" id="datePreset" onchange="toggleModalDates()">
                        <option value="today">Today</option>
                        <option value="month">This Full Month</option>
                        <option value="year">This Full Year</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>

                <div id="modalCustomDates" style="display:none; gap:10px; margin-bottom:20px;">
                    <div style="flex:1;">
                        <label style="font-size: 0.75rem; font-weight:700;">Start Date</label>
                        <input type="date" name="m_start_date" style="width:100%">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size: 0.75rem; font-weight:700;">End Date</label>
                        <input type="date" name="m_end_date" style="width:100%">
                    </div>
                </div>

                <div class="export-option-group">
                    <label>Export Format</label>
                    <select name="format">
                        <option value="excel">Excel Spreadsheet (.xlsx)</option>
                        <option value="pdf">PDF Document</option>
                        <option value="image">Image (PNG)</option>
                    </select>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeExportModal()">Cancel</button>
                    <button type="submit" class="btn-confirm-export">Generate Report</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function toggleCustomDates() {
        const select = document.getElementById('filterSelect');
        const customDiv = document.getElementById('customDates');
        customDiv.style.display = (select.value === 'CUSTOM') ? 'flex' : 'none';
    }

    function openExportModal() {
        document.getElementById('exportModal').style.display = 'block';
    }

    function closeExportModal() {
        document.getElementById('exportModal').style.display = 'none';
    }

    function toggleModalDates() {
        const preset = document.getElementById('datePreset').value;
        const customDiv = document.getElementById('modalCustomDates');
        customDiv.style.display = (preset === 'custom') ? 'flex' : 'none';
    }

    window.onclick = function(event) {
        let modal = document.getElementById('exportModal');
        if (event.target == modal) {
            closeExportModal();
        }
    }
    </script>

</body>

</html>