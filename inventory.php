<?php 
include 'auth.php'; 
include 'db.php';   
include 'php/auto_waste_sync.php';
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$user_id = $_SESSION['user_id'];

// STATUS SYNC
$update_sql = "UPDATE inventory SET status = 'EXPIRED' WHERE expiry_date < CURDATE() AND status != 'EXPIRED' AND user_id = ?";
$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param("i", $user_id);
$update_stmt->execute();

// PAGINATION
$limit = 10; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// FILTERS, SORTING & TABS PARAMS
$sort = $_GET['sort'] ?? 'expiry_date'; 
$order = $_GET['order'] ?? 'ASC';      
$filter_status = $_GET['filter_status'] ?? '';
$active_tab = $_GET['tab'] ?? 'all'; // Default to 'all' tab

$allowed_sorts = [
    'product_name' => 'product_name', 
    'expiry_date' => 'expiry_date', 
    'date_scanned' => 'date_scanned',
    'days_remaining' => 'days_remaining', 
    'total_sold' => 'total_sold', 
    'remaining_stocks' => 'remaining_stocks'
];
$sort_column = $allowed_sorts[$sort] ?? 'expiry_date';
$order = (strtoupper($order) === 'DESC') ? 'DESC' : 'ASC';

// TAB FILTER LOGIC
$tab_where = "";
if ($active_tab === 'sold') {
    $tab_where = " AND total_sold > 0";
} elseif ($active_tab === 'return') {
    $tab_where = " AND total_returned > 0";
} elseif ($active_tab === 'wasted') {
    $tab_where = " AND total_wasted > 0";
}

// COUNT QUERY WITH TAB FILTER
$count_query = "SELECT COUNT(*) as total FROM inventory 
                WHERE user_id = ? 
                AND DATEDIFF(expiry_date, CURDATE()) >= -1" . $tab_where;
                
if (!empty($filter_status)) {
    $count_query .= " AND status COLLATE utf8mb4_unicode_ci = ?";
    $c_stmt = $conn->prepare($count_query);
    $c_stmt->bind_param("is", $user_id, $filter_status);
} else {
    $c_stmt = $conn->prepare($count_query);
    $c_stmt->bind_param("i", $user_id);
}
$c_stmt->execute();
$total_rows = $c_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);


// MAIN FETCH QUERY WITH TAB FILTER
$query = "SELECT *, 
          CASE 
            WHEN DATEDIFF(expiry_date, CURDATE()) < 0 THEN (total_wasted + remaining_stocks) 
            ELSE total_wasted 
          END as live_wasted,
          CASE 
            WHEN DATEDIFF(expiry_date, CURDATE()) < 0 THEN 0 
            ELSE remaining_stocks 
          END as live_stocks
          FROM inventory 
          WHERE user_id = ? 
          AND DATEDIFF(expiry_date, CURDATE()) >= -1" . $tab_where;
          

if (!empty($filter_status)) {
    $query .= " AND status COLLATE utf8mb4_unicode_ci = ?";
}

$query .= " ORDER BY $sort_column $order LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);

if (!empty($filter_status)) {
    $stmt->bind_param("isii", $user_id, $filter_status, $limit, $offset);
} else {
    $stmt->bind_param("iii", $user_id, $limit, $offset);
}

$stmt->execute();
$result = $stmt->get_result();

$inventory_display = [];
$usable_total = 0;

while($row = $result->fetch_assoc()) {
    $stocks = $row['remaining_stocks'];
    $days_left = $row['days_remaining'];
    
    $current_status = $row['status']; 

    if ($current_status === 'USABLE' || empty($current_status)) {
        if ($row['expiry_date'] < date('Y-m-d')) {
            $current_status = 'EXPIRED';
        } elseif ($stocks <= 0) {
            $current_status = 'SOLD OUT';
        } elseif ($row['expiry_date'] === date('Y-m-d')) {
            $current_status = 'EXPIRING TODAY';
        } elseif ($days_left <= 3) { 
            $current_status = 'EXPIRING SOON';
        } else {
            $current_status = 'USABLE';
        }
    }

    if (in_array($current_status, ['USABLE', 'EXPIRING SOON', 'EXPIRING TODAY'])) {
        $usable_total += $stocks;
    }

    $inventory_display[] = [
        'name' => $row['product_name'],
        'price' => $row['price'],
        'qty' => $row['original_qty'],
        'date_scanned' => $row['date_scanned'],
        'expiry_date' => $row['expiry_date'],
        'days_remaining' => $days_left, 
        'remaining_stocks' => $stocks,
        'sold' => $row['total_sold'],      
        'returned' => $row['total_returned'],
        'wasted' => $row['total_wasted'],    
        'status' => $current_status 
    ];
}

// Modal Query
$modal_query = "SELECT product_name as name, SUM(remaining_stocks) as total_qty 
                FROM inventory 
                WHERE user_id = ? 
                AND status COLLATE utf8mb4_unicode_ci NOT IN ('EXPIRED', 'SOLD OUT') 
                GROUP BY product_name";
$stmt_m = $conn->prepare($modal_query);
$stmt_m->bind_param("i", $user_id);
$stmt_m->execute();
$modal_result = $stmt_m->get_result();
$unique_products_count = $modal_result->num_rows;

// Expired Total Stat (Total Waste)
$exp_query = "SELECT SUM(total_wasted) as grand_waste 
              FROM inventory 
              WHERE user_id = ? 
              AND DATEDIFF(expiry_date, CURDATE()) >= -1";
              
$stmt_e = $conn->prepare($exp_query);
$stmt_e->bind_param("i", $user_id);
$stmt_e->execute();
$expired_total = $stmt_e->get_result()->fetch_assoc()['grand_waste'] ?? 0;

// Grand Total
$total_all_query = "SELECT SUM(remaining_stocks + total_sold + total_wasted) as grand_total 
                    FROM inventory 
                    WHERE user_id = ? 
                    AND DATEDIFF(expiry_date, CURDATE()) >= -1";
$stmt_all = $conn->prepare($total_all_query);
$stmt_all->bind_param("i", $user_id);
$stmt_all->execute();
$total_items_all = $stmt_all->get_result()->fetch_assoc()['grand_total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>FoodSave - Inventory</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght=400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/inventory.css">
    
    <style>
        .tab-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .tab-btn {
            padding: 10px 24px;
            border-radius: 12px;
            background: white;
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            font-family: 'Poppins';
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }
        .tab-btn:hover {
            background: #8dae84;
            color: #334155;
        }
        .tab-btn.active {
            background: #8dae84;
            color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 3px solid #8dae84;
        }
    </style>
</head>

<body>

    <?php include 'sidebar.php'; ?>

    <main>
        <div class="header-card" style="margin-bottom: 25px;">
            <h2>Food Inventory</h2>
            <p>Overview of all your food items</p>
        </div>

        <div class="stats-grid" style="margin-bottom: 25px; display:grid; grid-template-columns:repeat(3, 1fr); gap: 20px;">
            <div class="stat-card" onclick="openProductModal()" style="cursor: pointer;">
                <div class="stat-info">
                    <h3>Products</h3>
                    <p style="font-size: 1.5rem; font-weight: 700;"><?php echo $unique_products_count; ?></p>
                </div>
                <div class="icon-circle"><i class="fas fa-box"></i></div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Usable Items</h3>
                    <p style="font-size: 1.5rem; font-weight: 700; color: #16a34a;"><?php echo $usable_total; ?></p>
                </div>
                <div class="icon-circle icon-green"><i class="fas fa-check-circle"></i></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Expiring Soon</h3>
                    <p style="font-size: 1.5rem; font-weight: 700; color: #16a34a;"><?php echo $usable_total; ?></p>
                </div>
                <div class="icon-circle icon-green"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Expiring Today</h3>
                    <p style="font-size: 1.5rem; font-weight: 700; color: #16a34a;"><?php echo $usable_total; ?></p>
                </div>
                <div class="icon-circle icon-green"><i class="fas fa-exclamation-triangle"></i></div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Expired</h3>
                    <p style="font-size: 1.5rem; font-weight: 700; color: #dc2626;"><?php echo $expired_total; ?></p>
                </div>
                <div class="icon-circle icon-red"><i class="fas fa-trash-alt"></i></div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>Total Items</h3>
                    <p style="font-size: 1.5rem; font-weight: 700; color: #1e293b;"><?php echo $total_items_all; ?></p>
                </div>
                <div class="icon-circle icon-blue"><i class="fas fa-history"></i></div>
            </div>
        </div>

        <div class="inventory-main-card">

            <div class="tab-container">
                <a href="?tab=all&filter_status=<?= $filter_status ?>&sort=<?= $sort ?>&order=<?= $order ?>" class="tab-btn <?= $active_tab === 'all' ? 'active' : '' ?>">ALL</a>
                <a href="?tab=sold&filter_status=<?= $filter_status ?>&sort=<?= $sort ?>&order=<?= $order ?>" class="tab-btn <?= $active_tab === 'sold' ? 'active' : '' ?>">SOLD</a>
                <a href="?tab=return&filter_status=<?= $filter_status ?>&sort=<?= $sort ?>&order=<?= $order ?>" class="tab-btn <?= $active_tab === 'return' ? 'active' : '' ?>">RETURN</a>
                <a href="?tab=wasted&filter_status=<?= $filter_status ?>&sort=<?= $sort ?>&order=<?= $order ?>" class="tab-btn <?= $active_tab === 'wasted' ? 'active' : '' ?>">WASTED</a>
                <div class="search-box" style="flex: 1; position: relative;">
                    <i class="fas fa-search"
                        style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                    <input type="text" id="searchInput" onkeyup="searchTable()" placeholder="Search by product name..."
                        style="width: 100%; padding: 12px 12px 12px 45px; border-radius: 12px; border: 1px solid #e2e8f0; outline: none; font-family: 'Poppins';">
                </div>
            </div>

            <div class="inventory-controls" style="display: flex; gap: 15px; margin-bottom: 10px; align-items: center;">
                

                <form method="GET" id="filterForm" style="display: flex; gap: 10px;">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">

                    <select name="filter_status" onchange="this.form.submit()"
                        style="padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <option value="">All Status</option>
                        <?php 
                        $statuses = ['USABLE', 'EXPIRING SOON', 'EXPIRING TODAY', 'EXPIRED', 'SOLD OUT'];
                        foreach($statuses as $st): ?>
                        <option value="<?= $st ?>" <?= ($filter_status == $st) ? 'selected' : '' ?>>
                            <?= ucwords(strtolower($st)) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="sort_select" id="sort_select" onchange="updateSortAndSubmit()"
                        style="padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <option value="product_name|ASC"
                            <?= ($sort == 'product_name' && $order == 'ASC') ? 'selected' : '' ?>>Name (A-Z)</option>
                        <option value="product_name|DESC"
                            <?= ($sort == 'product_name' && $order == 'DESC') ? 'selected' : '' ?>>Name (Z-A)</option>
                        <option value="expiry_date|ASC"
                            <?= ($sort == 'expiry_date' && $order == 'ASC') ? 'selected' : '' ?>>Expiration (Soonest)</option>
                        <option value="remaining_stocks|DESC"
                            <?= ($sort == 'remaining_stocks' && $order == 'DESC') ? 'selected' : '' ?>>Stocks (Highest)</option>
                        <option value="total_sold|DESC"
                            <?= ($sort == 'total_sold' && $order == 'DESC') ? 'selected' : '' ?>>Most Sold</option>
                    </select>

                    <input type="hidden" name="sort" id="real_sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" id="real_order" value="<?= htmlspecialchars($order) ?>">
                </form>
            </div>

            <div class="inventory-container">
                <div class="table-responsive" style="overflow-x: auto; width: 100%; border-radius: 15px;">
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Date Scanned</th>
                            <th>Expiry Date</th>
                            <th>Days Remaining</th>
                            
                            <?php if ($active_tab === 'all' || $active_tab === 'sold'): ?>
                                <th>Sold</th>
                            <?php endif; ?>
                            
                            <?php if ($active_tab === 'all' || $active_tab === 'return'): ?>
                                <th>Returned</th>
                            <?php endif; ?>
                            
                            <?php if ($active_tab === 'all' || $active_tab === 'wasted'): ?>
                                <th>Wasted</th>
                            <?php endif; ?>

                            <th>Remaining Stocks</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventory_display as $data): ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($data['name']); ?></td>
                            <td>₱<?php echo number_format($data['price'], 2); ?></td>
                            <td style="font-weight:600;"><?php echo $data['qty']; ?></td>
                            <td style="white-space: nowrap;">
                                <div style="font-weight: 500; color: #1e293b;">
                                    <?php echo date('M d, Y', strtotime($data['date_scanned'])); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: #94a3b8;">
                                    <i class="far fa-clock" style="font-size: 0.7rem;"></i> 
                                    <?php echo date('h:i A', strtotime($data['date_scanned'])); ?>
                                </div>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($data['expiry_date'])); ?></td>
                            <td style="font-weight: bold; color: #1e293b;">
                                <?php echo $data['days_remaining']; ?> Days
                            </td>

                            <?php if ($active_tab === 'all' || $active_tab === 'sold'): ?>
                                <td style="color: #2563eb;"><?php echo $data['sold']; ?></td>
                            <?php endif; ?>

                            <?php if ($active_tab === 'all' || $active_tab === 'return'): ?>
                                <td style="color: #d97706;"><?php echo $data['returned']; ?></td>
                            <?php endif; ?>

                            <?php if ($active_tab === 'all' || $active_tab === 'wasted'): ?>
                                <td style="color: #dc2626;"><?php echo $data['wasted']; ?></td>
                            <?php endif; ?>

                            <td style="background: #f0fdf4; font-weight: 800; color: #166534;">
                                <?php echo max(0, $data['remaining_stocks']); ?>
                            </td>
                            <td>
                                <span class="status-pill status-<?php echo strtolower(str_replace(' ', '-', $data['status'])); ?>">
                                    <?php echo $data['status']; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                
                <div class="pagination-wrapper">
                    <div class="pagination-info">
                        Showing <b><?php echo ($total_rows > 0) ? $offset + 1 : 0; ?></b> to
                        <b><?php echo min($offset + $limit, $total_rows); ?></b> of <b><?php echo $total_rows; ?></b>
                        entries
                    </div>

                    <div class="pagination-controls">
                        <a href="?page=1&tab=<?php echo $active_tab; ?>&filter_status=<?php echo $filter_status; ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>"
                            class="page-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>" title="First Page">
                            <i class="fas fa-angle-double-left"></i>
                        </a>

                        <a href="?page=<?php echo max(1, $page - 1); ?>&tab=<?php echo $active_tab; ?>&filter_status=<?php echo $filter_status; ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>"
                            class="page-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            PREV
                        </a>

                        <div class="page-numbers">
                            <?php 
                            $range = 2; 
                            for($i = 1; $i <= $total_pages; $i++): 
                            if($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)): ?>
                            <a href="?page=<?php echo $i; ?>&tab=<?php echo $active_tab; ?>&filter_status=<?php echo $filter_status; ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>"
                                class="num-btn <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php elseif($i == $page - $range - 1 || $i == $page + $range + 1): ?>
                            <span class="dots">...</span>
                            <?php endif; 
                            endfor; ?>
                        </div>

                        <a href="?page=<?php echo min($total_pages, $page + 1); ?>&tab=<?php echo $active_tab; ?>&filter_status=<?php echo $filter_status; ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>"
                            class="page-btn <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            NEXT
                        </a>

                        <a href="?page=<?php echo $total_pages; ?>&tab=<?php echo $active_tab; ?>&filter_status=<?php echo $filter_status; ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>"
                            class="page-btn <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>" title="Last Page">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="productModal" class="modal"
        style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
        <div style="background:#fff; margin:10% auto; padding:25px; border-radius:15px; width:40%; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 style="margin:0;">Current Usable Products</h2>
                <span onclick="closeProductModal()" style="cursor:pointer; font-size:24px;">&times;</span>
            </div>
            <div style="max-height:400px; overflow-y:auto;">
                <table style="width:100%; border-collapse:collapse;">
                    <tr style="background:#f8fafc; text-align:left;">
                        <th style="padding:12px;">Product Name</th>
                        <th style="padding:12px; text-align:center;">Total Items</th>
                    </tr>
                    <?php 
                    $modal_result->data_seek(0);
                    while($p = $modal_result->fetch_assoc()): ?>
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:12px; font-weight:600;"><?php echo htmlspecialchars($p['name']); ?></td>
                        <td style="padding:12px; text-align:center; color:#16a34a; font-weight:700;">
                            <?php echo $p['total_qty']; ?> items
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            </div>
        </div>
    </div>

    <script>
    function openProductModal() {
        document.getElementById('productModal').style.display = 'block';
    }

    function closeProductModal() {
        document.getElementById('productModal').style.display = 'none';
    }
    window.onclick = function(event) {
        if (event.target == document.getElementById('productModal')) closeProductModal();
    }

    /* JavaScript table search fix to match conditional cell counts */
    function searchTable() {
        let input = document.getElementById("searchInput");
        let filter = input.value.toUpperCase();
        let table = document.querySelector(".inventory-table");
        let tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) {
            let td = tr[i].getElementsByTagName("td")[0];
            if (td) {
                let txtValue = td.textContent || td.innerText;
                tr[i].style.display = (txtValue.toUpperCase().indexOf(filter) > -1) ? "" : "none";
            }
        }
    }

    function updateSortAndSubmit() {
        const select = document.getElementById('sort_select');
        const value = select.value.split('|');

        document.getElementById('real_sort').value = value[0];
        document.getElementById('real_order').value = value[1];

        document.getElementById('filterForm').submit();
    }
    </script>
</body>
</html>