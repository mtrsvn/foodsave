<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
/**
 * FOODSAVE SMART INVENTORY SYSTEM
 * Fully Functional Dashboard - Final Production Version
 */

// =========================================================================
// 1. DATABASE CONNECTION SETUP
// =========================================================================
include 'auth.php';
include 'db.php';

// =========================================================================
// 2. LIVE DATA FETCHING ENGINE (Using inventory view + sold/returned/wasted tables)
// =========================================================================
$user_id = $_SESSION['user_id'];

// Get low_stock_threshold from user settings
$user_settings = mysqli_fetch_assoc(mysqli_query($conn, "SELECT low_stock_threshold FROM users WHERE user_id = $user_id"));
$low_stock_threshold = $user_settings['low_stock_threshold'] ?? 3;

// Fetch product data from the inventory view (which already computes stocks, sold, wasted, etc.)
$inv_query = mysqli_query($conn, "
    SELECT product_name, price, original_qty, 
           total_sold, total_returned, total_wasted, remaining_stocks, status,
           expiry_date, days_remaining
    FROM inventory 
    WHERE user_id = $user_id 
    AND DATEDIFF(expiry_date, CURDATE()) >= -1
");

$total_products = 0;
$total_sold = 0;
$total_revenue = 0;
$money_saved = 0;
$estimated_waste = 0;
$low_stock_items = 0;

$products_data = [];
$wasted_tracker = [];
$best_seller_tracker = [];

$days_sales_distribution = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0, 'Saturday' => 0, 'Sunday' => 0];

if ($inv_query) {
    while ($row = mysqli_fetch_assoc($inv_query)) {
        $name    = $row['product_name'];
        $price   = $row['price'];
        $qty     = $row['original_qty'];
        $sold    = $row['total_sold'];
        $returned = $row['total_returned'];
        $wasted  = $row['total_wasted'];
        $stocks  = $row['remaining_stocks'];

        // Accumulate totals
        $total_products += $qty;
        $total_sold += $sold;
        $total_revenue += ($sold * $price);
        $money_saved += ($sold * $price);
        $estimated_waste += ($wasted * $price);

        // Low stock check
        if ($stocks > 0 && $stocks <= $low_stock_threshold && !in_array($row['status'], ['EXPIRED', 'SOLD OUT'])) {
            $low_stock_items++;
        }

        // Aggregate by product name for charts
        if (!isset($products_data[$name])) {
            $products_data[$name] = ['in_stock' => 0, 'sold' => 0, 'returned' => 0, 'revenue' => 0];
            $wasted_tracker[$name] = 0;
            $best_seller_tracker[$name] = 0;
        }
        $products_data[$name]['in_stock'] += $stocks;
        $products_data[$name]['sold'] += $sold;
        $products_data[$name]['returned'] += $wasted;
        $products_data[$name]['revenue'] += ($sold * $price);
        $wasted_tracker[$name] += $wasted;
        $best_seller_tracker[$name] += $sold;
    }
}

// PEAK SALES DAY ANALYTICS ENGINE (based on sold_at timestamps)
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
foreach ($days as $day) {
    $q_day = mysqli_query($conn, "
        SELECT COALESCE(SUM(s.sold_quantity), 0) as total 
        FROM sold s 
        JOIN products p ON s.product_id = p.product_id 
        WHERE p.user_id = $user_id AND DAYNAME(s.sold_at) = '$day'
    ");
    $r_day = $q_day ? mysqli_fetch_assoc($q_day) : ['total' => 0];
    $days_sales_distribution[$day] = $r_day['total'];
}

// Dynamic Sorting Engine
arsort($wasted_tracker);
arsort($best_seller_tracker);
arsort($days_sales_distribution);

$most_wasted_product = ($total_products > 0 && array_sum($wasted_tracker) > 0) ? array_key_first($wasted_tracker) : '--';
$best_seller_product = ($total_products > 0 && array_sum($best_seller_tracker) > 0) ? array_key_first($best_seller_tracker) : '--';
$peak_sales_day      = ($total_products > 0 && array_sum($days_sales_distribution) > 0) ? array_key_first($days_sales_distribution) : '--';

// INVENTORY HEALTH CONDITION MATRIX
$total_losses = array_sum($wasted_tracker);
$overall_health = ($total_products > 0) ? round((($total_products - $total_losses) / $total_products) * 100) : 0;
$fresh_percentage = ($overall_health > 0) ? round($overall_health * 0.8) : 0; 
$expiring_percentage = ($overall_health > 0) ? round($overall_health * 0.2) : 0;
$expired_percentage = ($overall_health > 0) ? (100 - ($fresh_percentage + $expiring_percentage)) : 0;

// Arrays for JavaScript charts
$product_names  = array_keys($products_data);
$stock_counts   = array_column($products_data, 'in_stock');
$sold_counts    = array_column($products_data, 'sold');
$return_counts  = array_column($products_data, 'returned');
$revenue_counts = array_column($products_data, 'revenue');
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
        <div onclick="goToPage('products.php')" class="clickable-card bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
            <div class="p-3 bg-blue-50 text-blue-600 rounded-lg"><i class="fa-solid fa-box text-xl"></i></div>
            <div>
                <div class="text-3xl font-bold text-[#2d4059]"><?= $total_products ?></div>
                <div class="text-xs text-gray-400 font-medium">Total Products</div>
            </div>
        </div>
        <div onclick="goToPage('sales.php')" class="clickable-card bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
            <div class="p-3 bg-orange-50 text-orange-500 rounded-lg"><i class="fa-solid fa-cart-shopping text-xl"></i></div>
            <div>
                <div class="text-3xl font-bold text-orange-500"><?= $total_sold ?></div>
                <div class="text-xs text-gray-400 font-medium">Total Sold</div>
            </div>
        </div>
        <div onclick="goToPage('revenue.php')" class="clickable-card bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
            <div class="p-3 bg-green-50 text-green-600 rounded-lg"><i class="fa-solid fa-arrow-trend-up text-xl"></i></div>
            <div>
                <div class="text-3xl font-bold text-green-600">₱<?= number_format($total_revenue, 2) ?></div>
                <div class="text-xs text-gray-400 font-medium">Total Revenue</div>
            </div>
        </div>
        <div onclick="goToPage('alerts.php')" class="clickable-card bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
            <div class="p-3 bg-red-50 text-red-500 rounded-lg"><i class="fa-solid fa-triangle-exclamation text-xl"></i></div>
            <div>
                <div class="text-3xl font-bold text-red-500"><?= $low_stock_items ?></div>
                <div class="text-xs text-gray-400 font-medium">Low Stock Items</div>
            </div>
        </div>
    </div>

    <div class="bg-[#2e7d32] text-white p-3 rounded-lg flex justify-between items-center text-sm font-semibold mb-4 shadow-sm">
        <div class="flex items-center space-x-2">
            <span>WELCOME to FoodSave Inventory Dashboard</span>
        </div>
        <div class="bg-[#1b5e20] px-4 py-1 rounded-md text-xs font-bold"><?= $total_products ?> Active Products</div>
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
                        <div class="text-2xl font-black text-gray-800"><?= $overall_health ?>%</div>
                        <div class="text-[9px] text-green-600 font-bold uppercase tracking-wider">Health</div>
                    </div>
                </div>
                <div class="flex-1 ml-6 space-y-2.5 text-xs">
                    <div class="bg-green-50 text-green-700 font-bold text-[11px] px-2 py-0.5 rounded-md w-max flex items-center">Live Scanner Feed</div>
                    <div>
                        <div class="flex justify-between text-gray-500 text-[11px] font-medium mb-1">
                            <span><span class="w-2 h-2 rounded-full bg-green-600 mr-2 inline-block"></span>Fresh</span>
                            <span class="font-bold text-gray-700"><?= $fresh_percentage ?>%</span>
                        </div>
                        <div class="w-full bg-gray-100 h-1.5 rounded-full"><div class="bg-green-600 h-1.5 rounded-full" style="width: <?= $fresh_percentage ?>%"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-gray-500 text-[11px] font-medium mb-1">
                            <span><span class="w-2 h-2 rounded-full bg-orange-400 mr-2 inline-block"></span>Expiring Soon</span>
                            <span class="font-bold text-gray-700"><?= $expiring_percentage ?>%</span>
                        </div>
                        <div class="w-full bg-gray-100 h-1.5 rounded-full"><div class="bg-orange-400 h-1.5 rounded-full" style="width: <?= $expiring_percentage ?>%"></div></div>
                    </div>
                    <div>
                        <div class="flex justify-between text-gray-500 text-[11px] font-medium mb-1">
                            <span><span class="w-2 h-2 rounded-full bg-red-500 mr-2 inline-block"></span>Expired</span>
                            <span class="font-bold text-gray-700"><?= $expired_percentage ?>%</span>
                        </div>
                        <div class="w-full bg-gray-100 h-1.5 rounded-full"><div class="bg-red-500 h-1.5 rounded-full" style="width: <?= $expired_percentage ?>%"></div></div>
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
                        <div class="text-xl font-bold text-gray-800">₱<?= number_format($money_saved, 2) ?></div>
                        <div class="text-[10px] text-gray-400 font-medium">Sold before expiry</div>
                    </div>
                </div>
                <div class="bg-[#fdf2f2] p-3 rounded-xl border border-red-100 flex items-center space-x-3.5">
                    <div class="p-2 bg-white text-red-500 rounded-full shadow-sm w-9 h-9 flex items-center justify-center"><i class="fa-solid fa-triangle-exclamation text-sm"></i></div>
                    <div>
                        <div class="text-[10px] text-red-600 font-bold flex items-center"><span class="w-1.5 h-1.5 rounded-full bg-red-600 mr-1"></span> Estimated Waste</div>
                        <div class="text-xl font-bold text-gray-800">₱<?= number_format($estimated_waste, 2) ?></div>
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
                <div class="py-2 flex justify-between items-center"><div class="flex items-center space-x-3"><div class="p-1.5 bg-red-50 text-red-500 rounded-md"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="text-gray-400 text-[10px] font-medium">Most Wasted</div><div class="font-bold text-gray-800"><?= $most_wasted_product ?></div></div></div></div>
                <div class="py-2 flex justify-between items-center"><div class="flex items-center space-x-3"><div class="p-1.5 bg-orange-50 text-orange-400 rounded-md"><i class="fa-solid fa-award text-sm"></i></div><div><div class="text-gray-400 text-[10px] font-medium">Best Seller</div><div class="font-bold text-gray-800"><?= $best_seller_product ?></div></div></div></div>
                <div class="py-2 flex justify-between items-center"><div class="flex items-center space-x-3"><div class="p-1.5 bg-blue-50 text-blue-400 rounded-md"><i class="fa-solid fa-calendar-days"></i></div><div><div class="text-gray-400 text-[10px] font-medium">Peak Sales Day</div><div class="font-bold text-gray-800"><?= $peak_sales_day ?></div></div></div></div>
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
            console.log("Navigating to: " + url);
            window.location.href = url;
        }

        const labelsList   = <?= json_encode($product_names) ?>;
        const stockData    = <?= json_encode($stock_counts) ?>;
        const soldData     = <?= json_encode($sold_counts) ?>;
        const returnData   = <?= json_encode($return_counts) ?>;
        const revenueData  = <?= json_encode($revenue_counts) ?>;

        const shareLabels  = <?= json_encode(array_keys($best_seller_tracker)) ?>;
        const shareData    = <?= json_encode(array_values($best_seller_tracker)) ?>;

        const healthScore  = <?= $overall_health ?>;
        const healthRemain = 100 - healthScore;
        const isAllZero    = <?= json_encode($total_products == 0) ?>;

        // 1. Inventory Health Progress Ring
        new Chart(document.getElementById('healthRingChart').getContext('2d'), {
            type: 'doughnut',
            data: { datasets: [{ data: isAllZero ? [0, 100] : [healthScore, healthRemain], backgroundColor: isAllZero ? ['#e5e7eb', '#e5e7eb'] : ['#1b5e20', '#e5e7eb'], borderWidth: 0 }] },
            options: { cutout: '82%', responsive: true, maintainAspectRatio: false, plugins: { tooltip: { enabled: false }, legend: { display: false } } }
        });

        // 2. Stock vs Sold vs Returned (Triple Bar Layout)
        new Chart(document.getElementById('stockVsSoldChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: labelsList,
                datasets: [
                    { label: 'In Stock', data: stockData, backgroundColor: '#2e7d32', borderRadius: 2, barPercentage: 0.25 },
                    { label: 'Sold', data: soldData, backgroundColor: '#ea580c', borderRadius: 2, barPercentage: 0.25 },
                    { label: 'Returned', data: returnData, backgroundColor: '#dc2626', borderRadius: 2, barPercentage: 0.25 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 10, weight: '600' } } } },
                scales: {
                    y: { beginAtZero: true, max: isAllZero ? 10 : null, ticks: { font: { size: 10 } } },
                    x: { ticks: { font: { size: 10 } }, grid: { display: false } }
                }
            }
        });

        // 3. Sales Share Sorted Pie Layout
        new Chart(document.getElementById('salesShareChart').getContext('2d'), {
            type: 'pie',
            data: { 
                labels: isAllZero ? ['No Scanned Data Available'] : shareLabels,
                datasets: [{ data: isAllZero ? [1] : shareData, backgroundColor: isAllZero ? ['#e5e7eb'] : ['#1e6091', '#e65c00', '#2a9d8f', '#6a1b9a', '#d62828', '#1b4332'] }] 
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });

        // 4. Revenue per Product Horizontal Layout
        new Chart(document.getElementById('revenueBarChart').getContext('2d'), {
            type: 'bar',
            data: { labels: labelsList, datasets: [{ data: revenueData, backgroundColor: ['#6a1b9a', '#e65c00', '#1e6091', '#d62828', '#2e7d32', '#00796b'], barThickness: 14 }] },
            options: { 
                indexAxis: 'y', 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { legend: { display: false } }, 
                scales: { x: { beginAtZero: true, max: isAllZero ? 10 : null, display: false }, y: { grid: { display: false }, border: { display: false } } } 
            }
        });

        // POLLING WORKER: Awtomatikong mag-re-refresh bawat 3 segundo kapag aktibo ang connection
        if (<?= json_encode($conn !== false) ?>) {
            setInterval(function(){ window.location.reload(); }, 3000);
        }
    </script>

    </main>
</body>
</html>