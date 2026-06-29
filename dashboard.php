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

// Total available products (current stock)
$r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(remaining_stocks), 0) as total FROM inventory WHERE user_id = $user_id AND status NOT IN ('EXPIRED', 'SOLD OUT')"));
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/style.css">

    <style>
        body {
            background-color: #9cb594;
            color: #4a5568;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
            margin: 0;
            padding: 0;
        }
        
        main {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .dashboard-stats-4-col {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        .dashboard-grid-3-col {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        @media (max-width: 1024px) {
            .dashboard-stats-4-col { grid-template-columns: repeat(2, 1fr); }
            .dashboard-grid-3-col { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .dashboard-stats-4-col { grid-template-columns: 1fr; }
        }

        .dash-card {
            background: white;
            padding: 16px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .dash-card-flex-col {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #f3f4f6;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .icon-box {
            padding: 12px;
            border-radius: 8px;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-center: center;
        }
        .stat-num {
            font-size: 1.875rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-lbl {
            font-size: 0.75rem;
            color: #9ca3af;
            font-weight: 500;
            margin-top: 4px;
        }
        
        .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 5px;
        width: 100%;
    }
    .filter-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
    }

    .search-wrapper {
        width: 60%;
    }

    .charts-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        width: 100%;
    }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main>
        
        <div class="header-card" style="margin-bottom: 20px;">
            <h2>WELCOME to FoodSave Inventory Dashboard</h2>
            <p>Summary of all your food items</p>
        </div>

        <div class="dashboard-stats-4-col">
            <div class="dash-card">
                <div class="icon-box" style="background-color: #eff6ff; color: #2563eb;"><i class="fa-solid fa-box style='font-size: 20px;'"></i></div>
                <div>
                    <div id="val-total-products" class="stat-num" style="color: #2d4059;"><?= $total_products ?></div>
                    <div class="stat-lbl">Total Products</div>
                </div>
            </div>
            <div class="dash-card">
                <div class="icon-box" style="background-color: #fff7ed; color: #f97316;"><i class="fa-solid fa-cart-shopping" style="font-size: 20px;"></i></div>
                <div>
                    <div id="val-total-sold" class="stat-num" style="color: #f97316;"><?= $total_sold ?></div>
                    <div class="stat-lbl">Total Sold</div>
                </div>
            </div>
            <div class="dash-card">
                <div class="icon-box" style="background-color: #f0fdf4; color: #16a34a;"><i class="fa-solid fa-arrow-trend-up" style="font-size: 20px;"></i></div>
                <div>
                    <div id="val-total-revenue" class="stat-num" style="color: #16a34a;">₱<?= number_format($total_revenue, 2) ?></div>
                    <div class="stat-lbl">Total Revenue</div>
                </div>
            </div>
            <div class="dash-card">
                <div class="icon-box" style="background-color: #fef2f2; color: #dc2626;"><i class="fa-solid fa-triangle-exclamation" style="font-size: 20px;"></i></div>
                <div>
                    <div id="val-low-stock" class="stat-num" style="color: #dc2626;"><?= $low_stock_items ?></div>
                    <div class="stat-lbl">Low Stock Items Preference</div>
                </div>
            </div>
        </div>

<div style="background: transparent; border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.2); box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05); padding: 30px; display: flex; flex-direction: column; gap: 16px;">

    <div class="dashboard-grid-3-col" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px;">
        
        <div class="dash-card-flex-col">
            <div>
                <h3 style="font-weight: 700; color: #2d4059; font-size: 0.875rem; margin: 0;"><span style="color: #f472b6; margin-right: 8px;">🧠</span> Inventory Health Score</h3>
                <p style="font-size: 11px; color: #9ca3af; margin: 2px 0 0 0;">Overall condition of your sandwich stock</p>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 16px; margin-bottom:20px">
                <div style="position: relative; width: 96px; height: 96px; display: flex; align-items: center; justify-content: center;">
                    <canvas id="healthRingChart" style="position: absolute; inset: 0;"></canvas>
                    <div style="text-align: center; z-index: 10;">
                        <div id="val-health-score" style="font-size: 1.5rem; font-weight: 900; color: #1f2937;"><?= $overall_health ?>%</div>
                        <div style="font-size: 9px; color: #16a34a; font-weight: 700; text-transform: uppercase; tracking-wider: 0.05em;">Health</div>
                    </div>
                </div>
                <div style="flex: 1; margin-left: 24px; display: flex; flex-direction: column; gap: 10px; font-size: 12px;">
                    <div style="background-color: #f0fdf4; color: #15803d; font-weight: 700; font-size: 11px; padding: 2px 8px; border-radius: 6px; width: max-content;">Status Feed</div>
                    <div>
                        <div style="display: flex; justify-content: space-between; color: #6b7280; font-size: 11px; font-weight: 500; margin-bottom: 4px;">
                            <span><span style="width: 8px; height: 8px; id='usable-dot'; border-radius: 50%; background-color: #16a34a; margin-right: 8px; display: inline-block;"></span>Usable</span>
                            <span id="val-fresh-pct" style="font-weight: 700; color: #374151;"><?= $fresh_percentage ?>%</span>
                        </div>
                        <div style="width: 100%; background-color: #f3f4f6; height: 6px; border-radius: 9999px;"><div id="bar-fresh" style="background-color: #16a34a; height: 6px; border-radius: 9999px; transition: all 0.5s; width: <?= $fresh_percentage ?>%"></div></div>
                    </div>
                    <div>
                        <div style="display: flex; justify-content: space-between; color: #6b7280; font-size: 11px; font-weight: 500; margin-bottom: 4px;">
                            <span><span style="width: 8px; height: 8px; border-radius: 50%; background-color: #fb923c; margin-right: 8px; display: inline-block;"></span>Expiring Soon</span>
                            <span id="val-expiring-pct" style="font-weight: 700; color: #374151;"><?= $expiring_percentage ?>%</span>
                        </div>
                        <div style="width: 100%; background-color: #f3f4f6; height: 6px; border-radius: 9999px;"><div id="bar-expiring" style="background-color: #fb923c; height: 6px; border-radius: 9999px; transition: all 0.5s; width: <?= $expiring_percentage ?>%"></div></div>
                    </div>
                    <div>
                        <div style="display: flex; justify-content: space-between; color: #6b7280; font-size: 11px; font-weight: 500; margin-bottom: 4px;">
                            <span><span style="width: 8px; height: 8px; border-radius: 50%; background-color: #ef4444; margin-right: 8px; display: inline-block;"></span>Expired</span>
                            <span id="val-expired-pct" style="font-weight: 700; color: #374151;"><?= $expired_percentage ?>%</span>
                        </div>
                        <div style="width: 100%; background-color: #f3f4f6; height: 6px; border-radius: 9999px;"><div id="bar-expired" style="background-color: #ef4444; height: 6px; border-radius: 9999px; transition: all 0.5s; width: <?= $expired_percentage ?>%"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dash-card-flex-col">
            <div>
                <h3 style="font-weight: 700; color: #2d4059; font-size: 0.875rem; margin: 0;"><span style="color: #fb923c; margin-right: 8px;">💰</span> Profit vs Loss</h3>
                <p style="font-size: 11px; color: #9ca3af; margin: 2px 0 0 0;">Financial impact of your inventory decisions</p>
            </div>
            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px;">
                <div style="background-color: #f0fdf4; padding: 12px; border-radius: 12px; border: 1px solid #dcfce7; display: flex; align-items: center; gap: 14px;">
                    <div style="padding: 8px; background-color: white; color: #16a34a; border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,0.05); width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-piggy-bank" style="font-size: 14px;"></i></div>
                    <div>
                        <div style="font-size: 10px; color: #166534; font-weight: 700; display: flex; align-items: center;"><i class="fa-solid fa-heart" style="margin-right: 4px; font-size: 8px;"></i> Money Profited</div>
                        <div id="val-money-saved" style="font-size: 1.25rem; font-weight: 700; color: #1f2937;">₱<?= number_format($money_saved, 2) ?></div>
                        <div style="font-size: 10px; color: #9ca3af; font-weight: 500;">Sold before expiry</div>
                    </div>
                </div>
                <div style="background-color: #fef2f2; padding: 12px; border-radius: 12px; border: 1px solid #fee2e2; display: flex; align-items: center; gap: 14px;">
                    <div style="padding: 8px; background-color: white; color: #ef4444; border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,0.05); width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-triangle-exclamation" style="font-size: 14px;"></i></div>
                    <div>
                        <div style="font-size: 10px; color: #991b1b; font-weight: 700; display: flex; align-items: center;"><span style="width: 6px; height: 6px; border-radius: 50%; background-color: #dc2626; margin-right: 4px; display: inline-block;"></span> Estimated Waste</div>
                        <div id="val-estimated-waste" style="font-size: 1.25rem; font-weight: 700; color: #1f2937;">₱<?= number_format($estimated_waste, 2) ?></div>
                        <div style="font-size: 10px; color: #9ca3af; font-weight: 500;">Expired unsold stock</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dash-card-flex-col">
            <div>
                <h3 style="font-weight: 700; color: #2d4059; font-size: 0.875rem; margin: 0;"><span style="color: #c084fc; margin-right: 8px;">📊</span> Trend Insights</h3>
                <p style="font-size: 11px; color: #9ca3af; margin: 2px 0 0 0;">Quick facts about your inventory</p>
            </div>
            <div style="display: flex; flex-direction: column; margin-top: 12px; font-size: 12px; margin-bottom: 15px">
                <div style="padding: 8px 0; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="padding: 6px; background-color: #fef2f2; color: #ef4444; border-radius: 6px;"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div>
                            <div style="color: #9ca3af; font-size: 10px; font-weight: 500;">Most Wasted</div>
                            <div id="val-most-wasted" style="font-weight: 700; color: #1f2937;"><?= $most_wasted_product ?></div>
                        </div>
                    </div>
                </div>
                <div style="padding: 8px 0; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="padding: 6px; background-color: #fff7ed; color: #fb923c; border-radius: 6px;"><i class="fa-solid fa-award" style="font-size: 14px;"></i></div>
                        <div>
                            <div style="color: #9ca3af; font-size: 10px; font-weight: 500;">Best Seller</div>
                            <div id="val-best-seller" style="font-weight: 700; color: #1f2937;"><?= $best_seller_product ?></div>
                        </div>
                    </div>
                </div>
                <div style="padding: 8px 0; display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="padding: 6px; background-color: #eff6ff; color: #60a5fa; border-radius: 6px;"><i class="fa-solid fa-calendar-days"></i></div>
                        <div>
                            <div style="color: #9ca3af; font-size: 10px; font-weight: 500;">Peak Sales Day</div>
                            <div id="val-peak-day" style="font-weight: 700; color: #1f2937;"><?= $peak_sales_day ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-grid-3-col" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px;">
        
        <div class="dash-card-flex-col">
            <div>
                <h3 style="font-weight: 700; color: #2d4059; font-size: 0.875rem; margin: 0;">Stock, Sold and Returned</h3>
                <p style="font-size: 11px; color: #9ca3af; margin: 2px 0 0 0;">Remaining stock, units sold, and returns per sandwich type</p>
            </div>
            <div style="height: 208px; margin-top: 16px; position: relative;">
                <canvas id="stockVsSoldChart"></canvas>
            </div>
        </div>

        <div class="dash-card-flex-col" style="align-items: center;">
            <div style="width: 100%; text-align: left;">
                <h3 style="font-weight: 700; color: #2d4059; font-size: 0.875rem; margin: 0;">Sales Share</h3>
                <p style="font-size: 11px; color: #9ca3af; margin: 2px 0 0 0;">Which sandwich sells the most (Popularity Sorted)</p>
            </div>
            <div style="width: 176px; height: 176px; margin: 8px 0; position: relative;">
                <canvas id="salesShareChart"></canvas>
            </div>
        </div>

        <div class="dash-card-flex-col">
            <div>
                <h3 style="font-weight: 700; color: #2d4059; font-size: 0.875rem; margin: 0;">Revenue per Product</h3>
                <p style="font-size: 11px; color: #9ca3af; margin: 2px 0 0 0;">Total ₱ earned from each sandwich</p>
            </div>
            <div style="height: 224px; margin-top: 8px; position: relative;">
                <canvas id="revenueBarChart"></canvas>
            </div>
        </div>
    </div>

</div>

    <script>
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
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₱' + Number(context.raw).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                }, 
                scales: { 
                    x: { 
                        beginAtZero: true, 
                        max: isAllZero ? 10 : null, 
                        display: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value;
                            }
                        }
                    }, 
                    y: { grid: { display: false }, border: { display: false } } 
                },
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