<?php
include 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit("Direct access not allowed.");
}

$scope = $_POST['report_scope'] ?? 'ALL';
$preset = $_POST['date_preset'] ?? 'today';
$revenue = (float)($_POST['revenue'] ?? 0);
$loss = (float)($_POST['loss'] ?? 0);
$sold_count = (int)($_POST['sold_count'] ?? 0);
$waste_count = (int)($_POST['waste_count'] ?? 0);
$filter_label = $_POST['filter_label'] ?? 'Current View';

$money_chart = $_POST['money_chart_image'] ?? '';
$count_chart = $_POST['count_chart_image'] ?? '';

$display_date = "";
if ($preset === 'custom') {
    $start = !empty($_POST['m_start_date']) ? date('M d, Y', strtotime($_POST['m_start_date'])) : '';
    $end = !empty($_POST['m_end_date']) ? date('M d, Y', strtotime($_POST['m_end_date'])) : '';
    $display_date = ($start && $end) ? "$start TO $end" : "CUSTOM RANGE";
} elseif ($preset === 'today') {
    $display_date = date('F d, Y');
} elseif ($preset === 'month') {
    $display_date = date('F Y');
} elseif ($preset === 'year') {
    $display_date = date('Y');
} else {
    $display_date = strtoupper($filter_label);
}

$title = "STATISTICS REPORT: " . $display_date;
$generated_at = date('M d, Y | h:i A');
$net_performance = $revenue - $loss;
?>
<!DOCTYPE html>
<html>

<head>
    <title><?= $title ?></title>
    <style>
    body {
        font-family: 'Poppins', Arial, sans-serif;
        margin: 40px;
        color: #333;
    }

    .header {
        text-align: center;
        border-bottom: 2px solid #6d8a66;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }

    .header h1 {
        margin: 0;
        font-size: 22px;
        color: #1e293b;
    }

    .meta-info {
        margin-bottom: 20px;
        font-size: 12px;
        display: flex;
        justify-content: space-between;
        padding: 10px;
        background: #f8fafc;
        border-radius: 8px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 30px;
    }

    th,
    td {
        border: 1px solid #e2e8f0;
        padding: 12px;
        text-align: left;
        font-size: 13px;
    }

    th {
        background-color: #f1f5f9;
        color: #475569;
        text-transform: uppercase;
    }

    .stat-value {
        font-weight: bold;
        text-align: right;
    }

    .chart-box {
        text-align: center;
        margin-top: 20px;
        page-break-inside: avoid;
        border: 1px solid #eee;
        padding: 15px;
        border-radius: 12px;
    }

    .chart-box img {
        width: 100%;
        max-height: 350px;
        object-fit: contain;
    }

    .footer {
        margin-top: 40px;
        text-align: center;
        font-size: 10px;
        color: #94a3b8;
        border-top: 1px solid #e2e8f0;
        padding-top: 10px;
    }

    @media print {
        .no-print {
            display: none;
        }
    }
    </style>
</head>

<body>

    <div class="no-print" style="text-align: right; margin-bottom: 20px;">
        <button onclick="window.print()"
            style="padding: 10px 20px; background: #6d8a66; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold;">
            <i class="fas fa-print"></i> Print / Save as PDF
        </button>
    </div>

    <div class="header">
        <h1>FOODSAVE INVENTORY ANALYTICS</h1>
        <p style="margin: 5px 0; font-size: 14px; color: #64748b;"><?= $title ?></p>
    </div>

    <div class="meta-info">
        <span><strong>REPORT TYPE:</strong> <?= $scope ?> SUMMARY</span>
        <span><strong>GENERATED ON:</strong> <?= $generated_at ?></span>
    </div>

    <h3>I. Executive Summary</h3>
    <table>
        <thead>
            <tr>
                <th>Metric Description</th>
                <th style="text-align: right;">Total Value / Volume</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Total Financial Revenue (Sold Items)</td>
                <td class="stat-value" style="color: #10b981;">₱<?= number_format($revenue, 2) ?></td>
            </tr>
            <tr>
                <td>Total Waste Loss (Expired/Damaged)</td>
                <td class="stat-value" style="color: #ef4444;">₱<?= number_format($loss, 2) ?></td>
            </tr>
            <tr>
                <td>Total Units Sold</td>
                <td class="stat-value"><?= number_format($sold_count) ?> PCS</td>
            </tr>
            <tr>
                <td>Total Units Wasted</td>
                <td class="stat-value"><?= number_format($waste_count) ?> PCS</td>
            </tr>
            <tr style="background: #f8fafc; font-size: 15px;">
                <td><strong>NET PERFORMANCE (Revenue - Loss)</strong></td>
                <td class="stat-value">
                    <span style="color: <?= $net_performance >= 0 ? '#10b981' : '#ef4444' ?>;">
                        ₱<?= number_format($net_performance, 2) ?>
                    </span>
                </td>
            </tr>
        </tbody>
    </table>

    <h3>II. Performance Visualization</h3>

    <?php if (($scope === 'ALL' || $scope === 'FINANCIAL') && !empty($money_chart)): ?>
    <div class="chart-box">
        <p style="font-size: 13px; font-weight: bold; color: #475569;">Financial Trend (Revenue vs. Waste)</p>
        <img src="<?= $money_chart ?>" alt="Financial Chart">
    </div>
    <?php endif; ?>

    <?php if (($scope === 'ALL' || $scope === 'QUANTITY') && !empty($count_chart)): ?>
    <div class="chart-box">
        <p style="font-size: 13px; font-weight: bold; color: #475569;">Quantity Analysis (Sold vs. Wasted Units)</p>
        <img src="<?= $count_chart ?>" alt="Quantity Chart">
    </div>
    <?php endif; ?>

    <div class="footer">
        <p>This report is system-generated by FoodSave Inventory Management System.</p>
        <p>&copy; <?= date('Y') ?> FoodSave. All Rights Reserved.</p>
    </div>

    <script>
    window.onload = function() {
        setTimeout(() => {
        }, 1000);
    };
    </script>

</body>

</html>