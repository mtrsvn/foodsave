<?php
/**
 * FOODSAVE SMART INVENTORY SYSTEM
 * Fully Functional Dashboard - Final Production Version
 */

// =========================================================================
// 1. DATABASE CONNECTION SETUP
// =========================================================================
include 'auth.php';
include 'db.php';

$user_id = $_SESSION['user_id'];

// =========================================================================
// 2. LIVE DATA FETCHING ENGINE (Using actual tables: products, sold, wasted, returned)
// =========================================================================

// Get low_stock_threshold from user settings
$user_settings = mysqli_fetch_assoc(mysqli_query($conn, "SELECT low_stock_threshold, days_before_expiry FROM users WHERE user_id = $user_id"));
$low_stock_threshold = $user_settings['low_stock_threshold'] ?? 3;

// --- OVERVIEW STATS (query source tables directly for accuracy) ---

// Total products scanned (sum of all quantities)
$r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(quantity), 0) as total FROM products WHERE user_id = $user_id"));
$total_products = $r['total'];

// Total sold quantity
$r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(s.sold_quantity), 0) as total FROM sold s WHERE s.user_id = $user_id"));
$total_sold = $r['total'];

// Total revenue (sold_quantity * price)
$r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(s.sold_quantity * p.price), 0) as total FROM sold s JOIN products p ON s.product_id = p.product_id WHERE s.user_id = $user_id"));
$total_revenue = $r['total'];
$money_saved = $total_revenue;

// Estimated waste value (wasted_quantity * price)
$r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(w.wasted_quantity * p.price), 0) as total FROM wasted w JOIN products p ON w.product_id = p.product_id WHERE w.user_id = $user_id"));
$estimated_waste = $r['total'];

// Total wasted quantity (for health calculation)
$r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(w.wasted_quantity), 0) as total FROM wasted w WHERE w.user_id = $user_id"));
$total_wasted_qty = $r['total'];

// Low stock items count (from inventory view)
$r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM inventory WHERE user_id = $user_id AND remaining_stocks > 0 AND remaining_stocks <= $low_stock_threshold AND status NOT IN ('EXPIRED', 'SOLD OUT')"));
$low_stock_items = $r['total'];

// --- CHART DATA: Per-product aggregation ---
$products_data = [];
$wasted_tracker = [];
$best_seller_tracker = [];

$chart_query = mysqli_query($conn, "
    SELECT p.name,
           SUM(p.quantity) as total_qty,
           COALESCE((SELECT SUM(s2.sold_quantity) FROM sold s2 WHERE s2.product_id = p.product_id AND s2.user_id = $user_id), 0) as p_sold,
           COALESCE((SELECT SUM(w2.wasted_quantity) FROM wasted w2 WHERE w2.product_id = p.product_id AND w2.user_id = $user_id), 0) as p_wasted,
           p.price
    FROM products p
    WHERE p.user_id = $user_id
    GROUP BY p.name, p.product_id, p.price
");

if ($chart_query) {
    while ($row = mysqli_fetch_assoc($chart_query)) {
        $name = $row['name'];
        $sold_qty = $row['p_sold'];
        $wasted_qty = $row['p_wasted'];
        $stocks = max(0, $row['total_qty'] - $sold_qty - $wasted_qty);
        $rev = $sold_qty * $row['price'];

        if (!isset($products_data[$name])) {
            $products_data[$name] = ['in_stock' => 0, 'sold' => 0, 'returned' => 0, 'revenue' => 0];
            $wasted_tracker[$name] = 0;
            $best_seller_tracker[$name] = 0;
        }
        $products_data[$name]['in_stock'] += $stocks;
        $products_data[$name]['sold'] += $sold_qty;
        $products_data[$name]['returned'] += $wasted_qty;
        $products_data[$name]['revenue'] += $rev;
        $wasted_tracker[$name] += $wasted_qty;
        $best_seller_tracker[$name] += $sold_qty;
    }
}

// PEAK SALES DAY ANALYTICS ENGINE
$days_sales_distribution = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0, 'Saturday' => 0, 'Sunday' => 0];
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
foreach ($days as $day) {
    $q_day = mysqli_query($conn, "
        SELECT COALESCE(SUM(s.sold_quantity), 0) as total 
        FROM sold s 
        WHERE s.user_id = $user_id AND DAYNAME(s.sold_at) = '$day'
    ");
    $r_day = $q_day ? mysqli_fetch_assoc($q_day) : ['total' => 0];
    $days_sales_distribution[$day] = $r_day['total'];
}

// Dynamic Sorting
arsort($wasted_tracker);
arsort($best_seller_tracker);
arsort($days_sales_distribution);

$most_wasted_product = ($total_products > 0 && array_sum($wasted_tracker) > 0) ? array_key_first($wasted_tracker) : '--';
$best_seller_product = ($total_products > 0 && array_sum($best_seller_tracker) > 0) ? array_key_first($best_seller_tracker) : '--';
$peak_sales_day      = ($total_products > 0 && array_sum($days_sales_distribution) > 0) ? array_key_first($days_sales_distribution) : '--';

// INVENTORY HEALTH CONDITION MATRIX
$overall_health = ($total_products > 0) ? round((($total_products - $total_wasted_qty) / $total_products) * 100) : 0;
$fresh_percentage = ($overall_health > 0) ? round($overall_health * 0.8) : 0;
$expiring_percentage = ($overall_health > 0) ? round($overall_health * 0.2) : 0;
$expired_percentage = ($overall_health > 0) ? (100 - ($fresh_percentage + $expiring_percentage)) : 0;

// Arrays for JavaScript charts
$product_names  = array_keys($products_data);
$stock_counts   = array_column($products_data, 'in_stock');
$sold_counts    = array_column($products_data, 'sold');
$return_counts  = array_column($products_data, 'returned');
$revenue_counts = array_column($products_data, 'revenue');

// =========================================================================
// AJAX ENDPOINT: Return JSON if ?ajax=1
// =========================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'total_products' => $total_products,
        'total_sold' => $total_sold,
        'total_revenue' => number_format($total_revenue, 2),
        'low_stock_items' => $low_stock_items,
        'money_saved' => number_format($money_saved, 2),
        'estimated_waste' => number_format($estimated_waste, 2),
        'overall_health' => $overall_health,
        'fresh_percentage' => $fresh_percentage,
        'expiring_percentage' => $expiring_percentage,
        'expired_percentage' => $expired_percentage,
        'most_wasted_product' => $most_wasted_product,
        'best_seller_product' => $best_seller_product,
        'peak_sales_day' => $peak_sales_day,
        'product_names' => $product_names,
        'stock_counts' => $stock_counts,
        'sold_counts' => $sold_counts,
        'return_counts' => $return_counts,
        'revenue_counts' => $revenue_counts,
        'share_labels' => array_keys($best_seller_tracker),
        'share_data' => array_values($best_seller_tracker),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FoodSave Inventory Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght=400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/inventory.css">
    <style>
        .clickable-card { transition: all 0.2s ease-in-out; cursor: pointer; }
        .clickable-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    </style>
</head>
<body class="bg-[#f4f7f6] text-gray-700">

    <?php include 'sidebar.php'; ?>

    <main style="padding: 24px;">

    <div class="bg-[#2e7d32] text-white p-3 rounded-lg flex justify-between items-center text-sm font-semibold mb-4 shadow-sm">
        <div class="flex items-center space-x-2">
            <span>WELCOME to FoodSave Inventory Dashboard</span>
        </div>
        <div class="bg-[#1b5e20] px-4 py-1 rounded-md text-xs font-bold"><span id="val-active-products"><?= $total_products ?></span> Active Products</div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
        <div onclick="goToPage('products.php')" class="clickable-card bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
            <div class="p-3 bg-blue-50 text-blue-600 rounded-lg"><i class="fa-solid fa-box text-xl"></i></div>
            <div>
                <div id="val-total-products" class="text-3xl font-bold text-[#2d4059]"><?= $total_products ?></div>
                <div class="text-xs text-gray-400 font-medium">Total Products</div>
            </div>
        </div>
        <div onclick="goToPage('sales.php')" class="clickable-card bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
            <div class="p-3 bg-orange-50 text-orange-500 rounded-lg"><i class="fa-solid fa-cart-shopping text-xl"></i></div>
            <div>
                <div id="val-total-sold" class="text-3xl font-bold text-orange-500"><?= $total_sold ?></div>
                <div class="text-xs text-gray-400 font-medium">Total Sold</div>
            </div>
        </div>
        <div onclick="goToPage('revenue.php')" class="clickable-card bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
            <div class="p-3 bg-green-50 text-green-600 rounded-lg"><i class="fa-solid fa-arrow-trend-up text-xl"></i></div>
            <div>
                <div id="val-total-revenue" class="text-3xl font-bold text-green-600">₱<?= number_format($total_revenue, 2) ?></div>
                <div class="text-xs text-gray-400 font-medium">Total Revenue</div>
            </div>
        </div>
        <div onclick="goToPage('alerts.php')" class="clickable-card bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
            <div class="p-3 bg-red-50 text-red-500 rounded-lg"><i class="fa-solid fa-triangle-exclamation text-xl"></i></div>
            <div>
                <div id="val-low-stock" class="text-3xl font-bold text-red-500"><?= $low_stock_items ?></div>
                <div class="text-xs text-gray-400 font-medium">Low Stock Items</div>
            </div>
        </div>
    </div>



    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
        <div onclick="goToPage('health_details.php')" class="clickable-card bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between">
            <div>
                <h3 class="font-bold text-[#2d4059] text-sm flex items-center"><span class="text-pink-400 mr-2">🧠</span> Inventory Health Score</h3>
                <p class="text-[11px] text-gray-400 mt-0.5">Overall condition of your sandwich stock</p>
            </div>
            <div class="flex items-center justify-between mt-4">
                <div class="relative w-24 h-24 flex items-center justify-center">
                    <canvas id="healthRingChart" class="absolute inset-0"></canvas>
                    <div class="text-center z-10">
                        <div id="val-health-score" class="text-2xl font-black text-gray-800"><?= $overall_health ?>%</div>
                        <div class="text-[9px] text-green-600 font-bold uppercase tracking-wider">Health</div>
                    </div>
                </div>
                <div class="flex-1 ml-6 space-y-2.5 text-xs">
                    <div class="bg-green-50 text-green-700 font-bold text-[11px] px-2 py-0.5 rounded-md w-max flex items-center">Live Scanner Feed</div>
                    <div>
                        <div class="flex justify-between text-gray-500 text-[11px] font-medium mb-1">
                            <span><span class="w-2 h-2 rounded-full bg-green-600 mr-2 inline-block"></span>Fresh</span>
                            <span id="val-fresh-pct" class="font-bold text-gray-700"><?= $fresh_percentage ?>%</span>
                        </div>
                        <div class="w-full bg-gray-100 h-1.5 rounded-full"><div id="bar-fresh" class="bg-green-600 h-1.5 rounded-full transition-all duration-500" style="width: <?= $fresh_percentage ?>%"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-gray-500 text-[11px] font-medium mb-1">
                            <span><span class="w-2 h-2 rounded-full bg-orange-400 mr-2 inline-block"></span>Expiring Soon</span>
                            <span id="val-expiring-pct" class="font-bold text-gray-700"><?= $expiring_percentage ?>%</span>
                        </div>
                        <div class="w-full bg-gray-100 h-1.5 rounded-full"><div id="bar-expiring" class="bg-orange-400 h-1.5 rounded-full transition-all duration-500" style="width: <?= $expiring_percentage ?>%"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-gray-500 text-[11px] font-medium mb-1">
                            <span><span class="w-2 h-2 rounded-full bg-red-500 mr-2 inline-block"></span>Expired</span>
                            <span id="val-expired-pct" class="font-bold text-gray-700"><?= $expired_percentage ?>%</span>
                        </div>
                        <div class="w-full bg-gray-100 h-1.5 rounded-full"><div id="bar-expired" class="bg-red-500 h-1.5 rounded-full transition-all duration-500" style="width: <?= $expired_percentage ?>%"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div onclick="goToPage('financial_details.php')" class="clickable-card bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between">
            <div>
                <h3 class="font-bold text-[#2d4059] text-sm flex items-center"><span class="text-orange-400 mr-2">💰</span> Waste vs Savings</h3>
                <p class="text-[11px] text-gray-400 mt-0.5">Financial impact of your inventory decisions</p>
            </div>
            <div class="space-y-3 mt-4">
                <div class="bg-[#ebf7ee] p-3 rounded-xl border border-green-100 flex items-center space-x-3.5">
                    <div class="p-2 bg-white text-green-600 rounded-full shadow-sm w-9 h-9 flex items-center justify-center"><i class="fa-solid fa-piggy-bank text-sm"></i></div>
                    <div>
                        <div class="text-[10px] text-green-700 font-bold flex items-center"><i class="fa-solid fa-heart mr-1 text-[8px]"></i> Money Saved</div>
                        <div id="val-money-saved" class="text-xl font-bold text-gray-800">₱<?= number_format($money_saved, 2) ?></div>
                        <div class="text-[10px] text-gray-400 font-medium">Sold before expiry</div>
                    </div>
                </div>
                <div class="bg-[#fdf2f2] p-3 rounded-xl border border-red-100 flex items-center space-x-3.5">
                    <div class="p-2 bg-white text-red-500 rounded-full shadow-sm w-9 h-9 flex items-center justify-center"><i class="fa-solid fa-triangle-exclamation text-sm"></i></div>
                    <div>
                        <div class="text-[10px] text-red-600 font-bold flex items-center"><span class="w-1.5 h-1.5 rounded-full bg-red-600 mr-1"></span> Estimated Waste</div>
                        <div id="val-estimated-waste" class="text-xl font-bold text-gray-800">₱<?= number_format($estimated_waste, 2) ?></div>
                        <div class="text-[10px] text-gray-400 font-medium">Expired unsold stock</div>
                    </div>
                </div>
            </div>
        </div>

        <div onclick="goToPage('trends_details.php')" class="clickable-card bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between">
            <div>
                <h3 class="font-bold text-[#2d4059] text-sm flex items-center"><span class="text-purple-400 mr-2">📊</span> Trend Insights</h3>
                <p class="text-[11px] text-gray-400 mt-0.5">Quick facts about your inventory</p>
            </div>
            <div class="divide-y divide-gray-100 text-xs mt-3">
                <div class="py-2 flex justify-between items-center"><div class="flex items-center space-x-3"><div class="p-1.5 bg-red-50 text-red-500 rounded-md"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="text-gray-400 text-[10px] font-medium">Most Wasted</div><div id="val-most-wasted" class="font-bold text-gray-800"><?= $most_wasted_product ?></div></div></div></div>
                <div class="py-2 flex justify-between items-center"><div class="flex items-center space-x-3"><div class="p-1.5 bg-orange-50 text-orange-400 rounded-md"><i class="fa-solid fa-award text-sm"></i></div><div><div class="text-gray-400 text-[10px] font-medium">Best Seller</div><div id="val-best-seller" class="font-bold text-gray-800"><?= $best_seller_product ?></div></div></div></div>
                <div class="py-2 flex justify-between items-center"><div class="flex items-center space-x-3"><div class="p-1.5 bg-blue-50 text-blue-400 rounded-md"><i class="fa-solid fa-calendar-days"></i></div><div><div class="text-gray-400 text-[10px] font-medium">Peak Sales Day</div><div id="val-peak-day" class="font-bold text-gray-800"><?= $peak_sales_day ?></div></div></div></div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div onclick="goToPage('stock_vs_sold.php')" class="clickable-card bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between">
            <div>
                <h3 class="font-bold text-[#2d4059] text-sm">Stock, Sold and Returned</h3>
                <p class="text-[11px] text-gray-400 mt-0.5">Remaining stock, units sold, and returns per sandwich type</p>
            </div>
            <div class="h-52 mt-4 relative">
                <canvas id="stockVsSoldChart"></canvas>
            </div>
        </div>

        <div onclick="goToPage('sales_share.php')" class="clickable-card bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col items-center justify-between">
            <div class="w-full text-left">
                <h3 class="font-bold text-[#2d4059] text-sm">Sales Share</h3>
                <p class="text-[11px] text-gray-400 mt-0.5">Which sandwich sells the most (Popularity Sorted)</p>
            </div>
            <div class="w-44 h-44 my-2 relative">
                <canvas id="salesShareChart"></canvas>
            </div>
        </div>

        <div onclick="goToPage('revenue_details.php')" class="clickable-card bg-white p-5 rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between">
            <div>
                <h3 class="font-bold text-[#2d4059] text-sm">Revenue per Product</h3>
                <p class="text-[11px] text-gray-400 mt-0.5">Total ₱ earned from each sandwich</p>
            </div>
            <div class="h-56 mt-2 relative">
                <canvas id="revenueBarChart"></canvas>
            </div>
        </div>
    </div>

    <script>
        function goToPage(url) { 
            window.location.href = url;
        }

        let labelsList   = <?= json_encode($product_names) ?>;
        let stockData    = <?= json_encode($stock_counts) ?>;
        let soldData     = <?= json_encode($sold_counts) ?>;
        let returnData   = <?= json_encode($return_counts) ?>;
        let revenueData  = <?= json_encode($revenue_counts) ?>;
        let shareLabels  = <?= json_encode(array_keys($best_seller_tracker)) ?>;
        let shareData    = <?= json_encode(array_values($best_seller_tracker)) ?>;
        let healthScore  = <?= $overall_health ?>;
        let isAllZero    = <?= json_encode($total_products == 0) ?>;
        let isNoSales    = <?= json_encode(array_sum($best_seller_tracker) == 0) ?>;

        const chartColors = ['#1e6091', '#e65c00', '#2a9d8f', '#6a1b9a', '#d62828', '#1b4332', '#00796b', '#c62828', '#4527a0'];

        // 1. Inventory Health Progress Ring
        const healthChart = new Chart(document.getElementById('healthRingChart').getContext('2d'), {
            type: 'doughnut',
            data: { datasets: [{ data: isAllZero ? [0, 100] : [healthScore, 100 - healthScore], backgroundColor: isAllZero ? ['#e5e7eb', '#e5e7eb'] : ['#1b5e20', '#e5e7eb'], borderWidth: 0 }] },
            options: { cutout: '82%', responsive: true, maintainAspectRatio: false, plugins: { tooltip: { enabled: false }, legend: { display: false } }, animation: { duration: 500 } }
        });

        // 2. Stock vs Sold vs Returned
        const stockChart = new Chart(document.getElementById('stockVsSoldChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: labelsList,
                datasets: [
                    { label: 'In Stock', data: stockData, backgroundColor: '#2e7d32', borderRadius: 2, barPercentage: 0.25 },
                    { label: 'Sold', data: soldData, backgroundColor: '#ea580c', borderRadius: 2, barPercentage: 0.25 },
                    { label: 'Wasted', data: returnData, backgroundColor: '#dc2626', borderRadius: 2, barPercentage: 0.25 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 10, weight: '600' } } } },
                scales: {
                    y: { beginAtZero: true, max: isAllZero ? 10 : null, ticks: { font: { size: 10 } } },
                    x: { ticks: { font: { size: 10 } }, grid: { display: false } }
                },
                animation: { duration: 500 }
            }
        });

        // 3. Sales Share Pie
        const salesChart = new Chart(document.getElementById('salesShareChart').getContext('2d'), {
            type: 'pie',
            data: { 
                labels: isAllZero ? ['No Data'] : (isNoSales ? ['No Sales Yet'] : shareLabels),
                datasets: [{ data: isAllZero || isNoSales ? [1] : shareData, backgroundColor: isAllZero || isNoSales ? ['#e5e7eb'] : chartColors }] 
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 10, weight: '600' } } } }, animation: { duration: 500 } }
        });

        // 4. Revenue per Product
        const revenueChart = new Chart(document.getElementById('revenueBarChart').getContext('2d'), {
            type: 'bar',
            data: { labels: labelsList, datasets: [{ data: revenueData, backgroundColor: chartColors, barThickness: 14 }] },
            options: { 
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, 
                plugins: { legend: { display: false } }, 
                scales: { x: { beginAtZero: true, max: isAllZero ? 10 : null, display: false }, y: { grid: { display: false }, border: { display: false } } },
                animation: { duration: 500 }
            }
        });

        // =====================================================================
        // SEAMLESS AJAX REFRESH (no page reload)
        // =====================================================================
        function refreshDashboard() {
            fetch('dashboard.php?ajax=1')
                .then(r => r.json())
                .then(d => {
                    // Update stat cards
                    document.getElementById('val-total-products').textContent = d.total_products;
                    document.getElementById('val-total-sold').textContent = d.total_sold;
                    document.getElementById('val-total-revenue').textContent = '₱' + d.total_revenue;
                    document.getElementById('val-low-stock').textContent = d.low_stock_items;
                    document.getElementById('val-active-products').textContent = d.total_products;

                    // Update health section
                    document.getElementById('val-health-score').textContent = d.overall_health + '%';
                    document.getElementById('val-fresh-pct').textContent = d.fresh_percentage + '%';
                    document.getElementById('val-expiring-pct').textContent = d.expiring_percentage + '%';
                    document.getElementById('val-expired-pct').textContent = d.expired_percentage + '%';
                    document.getElementById('bar-fresh').style.width = d.fresh_percentage + '%';
                    document.getElementById('bar-expiring').style.width = d.expiring_percentage + '%';
                    document.getElementById('bar-expired').style.width = d.expired_percentage + '%';

                    // Update financial
                    document.getElementById('val-money-saved').textContent = '₱' + d.money_saved;
                    document.getElementById('val-estimated-waste').textContent = '₱' + d.estimated_waste;

                    // Update trends
                    document.getElementById('val-most-wasted').textContent = d.most_wasted_product;
                    document.getElementById('val-best-seller').textContent = d.best_seller_product;
                    document.getElementById('val-peak-day').textContent = d.peak_sales_day;

                    // Update health ring chart
                    const newAllZero = d.total_products == 0;
                    healthChart.data.datasets[0].data = newAllZero ? [0, 100] : [d.overall_health, 100 - d.overall_health];
                    healthChart.data.datasets[0].backgroundColor = newAllZero ? ['#e5e7eb', '#e5e7eb'] : ['#1b5e20', '#e5e7eb'];
                    healthChart.update();

                    // Update stock chart
                    stockChart.data.labels = d.product_names;
                    stockChart.data.datasets[0].data = d.stock_counts;
                    stockChart.data.datasets[1].data = d.sold_counts;
                    stockChart.data.datasets[2].data = d.return_counts;
                    stockChart.options.scales.y.max = newAllZero ? 10 : null;
                    stockChart.update();

                    // Update sales share chart
                    const isNoSales = d.share_data.reduce((a, b) => a + b, 0) == 0;
                    salesChart.data.labels = newAllZero ? ['No Data'] : (isNoSales ? ['No Sales Yet'] : d.share_labels);
                    salesChart.data.datasets[0].data = newAllZero || isNoSales ? [1] : d.share_data;
                    salesChart.data.datasets[0].backgroundColor = newAllZero || isNoSales ? ['#e5e7eb'] : chartColors;
                    salesChart.update();

                    // Update revenue chart
                    revenueChart.data.labels = d.product_names;
                    revenueChart.data.datasets[0].data = d.revenue_counts;
                    revenueChart.options.scales.x.max = newAllZero ? 10 : null;
                    revenueChart.update();
                })
                .catch(err => console.log('Dashboard refresh error:', err));
        }

        // Refresh every 5 seconds seamlessly
        setInterval(refreshDashboard, 5000);
    </script>

    </main>
</body>
</html>