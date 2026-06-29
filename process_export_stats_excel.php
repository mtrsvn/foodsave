<?php
include 'db.php';
include 'auth.php';
date_default_timezone_set('Asia/Manila');

$user_id = $_SESSION['user_id'];
$filter = $_SESSION['stats_filter'] ?? 'day';
$start_date_input = $_SESSION['stats_start'] ?? '';
$end_date_input = $_SESSION['stats_end'] ?? '';
$custom_group = $_SESSION['stats_custom_group'] ?? 'day';

// Kunin ang parehong logic ng time range batay sa session memory
$start_date = date('Y-m-d', strtotime('-6 days'));
$end_date = date('Y-m-d');
$group_by = "DATE(date_action)";
$date_format = "%Y-%m-%d";
$filter_label = "Daily Report";

if ($filter == 'day') {
    $start_date = date('Y-m-d', strtotime('-6 days'));
    $group_by = "DATE(date_action)";
    $date_format = "%M %d, %Y";
    $filter_label = "Daily Activity (Last 7 Days)";
} elseif ($filter == '1week') {
    $start_date = date('Y-m-d', strtotime('-4 weeks'));
    $group_by = "YEARWEEK(date_action, 1)";
    $date_format = "Year %x, Week %v";
    $filter_label = "Weekly Activity (Last 4 Weeks)";
} elseif ($filter == '1month') {
    $start_date = date('Y-m-01', strtotime('-11 months'));
    $group_by = "MONTH(date_action), YEAR(date_action)";
    $date_format = "%b-%Y";
    $filter_label = "Monthly Performance (This Year)";
} elseif ($filter == 'custom' && !empty($start_date_input) && !empty($end_date_input)) {
    $start_date = $start_date_input;
    $end_date = $end_date_input;
    $filter_label = "Custom Range Report ($start_date to $end_date)";

    if ($custom_group == 'month') {
        $group_by = "MONTH(date_action), YEAR(date_action)";
        $date_format = "%b-%Y";
    } elseif ($custom_group == '1week') {
        $group_by = "YEARWEEK(date_action, 1)";
        $date_format = "Year %x, Week %v";
    } else {
        $group_by = "DATE(date_action)";
        $date_format = "%M %d, %Y";
    }
}

// Kunin ang aggregated performance statistics mula sa composite logs
$query = "
    SELECT 
        DATE_FORMAT(date_action, '$date_format') as action_period,
        SUM(CASE WHEN type = 'sold' THEN amount ELSE 0 END) as sold_value,
        SUM(CASE WHEN type = 'sold' THEN qty ELSE 0 END) as sold_count,
        SUM(CASE WHEN type = 'wasted' THEN amount ELSE 0 END) as wasted_value,
        SUM(CASE WHEN type = 'wasted' THEN qty ELSE 0 END) as wasted_count
    FROM (
        SELECT sold_at as date_action, (sold_quantity * p.price) as amount, sold_quantity as qty, 'sold' as type 
        FROM sold s JOIN products p ON s.product_id = p.product_id WHERE p.user_id = ?
        UNION ALL
        SELECT wasted_at as date_action, (wasted_quantity * p.price) as amount, wasted_quantity as qty, 'wasted' as type 
        FROM wasted w JOIN products p ON w.product_id = p.product_id WHERE p.user_id = ?
    ) as combined
    WHERE DATE(date_action) BETWEEN ? AND ?
    GROUP BY $group_by
    ORDER BY MIN(date_action) ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param("iiss", $user_id, $user_id, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// Magsimula ng Excel file generation downloads via HTTP headers
$filename = "FoodSave_Stats_Export_" . date('Ymd_His') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=$filename");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Pragma: public");

// Isama ang CSS styles para maging mukhang dashboard grid panel sa loob ng Excel
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .title-header { font-size: 16pt; font-weight: bold; color: #1e293b; text-align: left; }
    .subtitle-label { font-size: 10pt; color: #64748b; font-style: italic; }
    .summary-box { font-weight: bold; background-color: #f8fafc; text-align: center; border: 1px solid #cbd5e1; }
    .table-header { background-color: #0f172a; color: #ffffff; font-weight: bold; text-align: center; }
    .data-row { text-align: center; }
    .revenue-cell { color: #16a34a; font-weight: 600; }
    .loss-cell { color: #dc2626; font-weight: 600; }
    .total-row { background-color: #f1f5f9; font-weight: bold; }
    .currency { text-align: right; }
    .number { text-align: right; }
</style>
</head>
<body>

<table>
    <tr>
        <td colspan="5" class="title-header">FOODSAVE INVENTORY SYSTEM</td>
    </tr>
    <tr>
        <td colspan="5" class="subtitle-label">Operational Analytics & Performance Statistics Report</td>
    </tr>
    <tr>
        <td colspan="5"><b>Scope:</b> <?= htmlspecialchars($filter_label) ?></td>
    </tr>
    <tr>
        <td colspan="5"><b>Generated Date:</b> <?= date('F d, %Y h:i A') ?></td>
    </tr>
    <tr><td colspan="5"></td></tr>
</table>

<table border="1">
    <thead>
        <tr class="table-header">
            <th width="180" style="padding: 8px;">Time Period / Day</th>
            <th width="140">Sold Items (QTY)</th>
            <th width="160">Total Revenue</th>
            <th width="140">Wasted Items (QTY)</th>
            <th width="160">Total Waste Loss</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $sum_sold_qty = 0;
        $sum_revenue = 0;
        $sum_waste_qty = 0;
        $sum_loss = 0;

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $sum_sold_qty += $row['sold_count'];
                $sum_revenue += $row['sold_value'];
                $sum_waste_qty += $row['wasted_count'];
                $sum_loss += $row['wasted_value'];
                ?>
                <tr class="data-row">
                    <td style="padding: 6px; text-align: left;"><?= htmlspecialchars($row['action_period']) ?></td>
                    <td class="number"><?= number_format($row['sold_count']) ?> PCS</td>
                    <td class="currency revenue-cell">₱<?= number_format($row['sold_value'], 2) ?></td>
                    <td class="number"><?= number_format($row['wasted_count']) ?> PCS</td>
                    <td class="currency loss-cell">₱<?= number_format($row['wasted_value'], 2) ?></td>
                </tr>
                <?php
            }
        } else {
            echo '<tr><td colspan="5" style="text-align:center; color:#94a3b8;">No data records captured inside this scope range.</td></tr>';
        }
        ?>
        <tr class="total-row">
            <td style="padding: 8px; text-align: left;">OVERALL TOTALS</td>
            <td class="number"><?= number_format($sum_sold_qty) ?> PCS</td>
            <td class="currency revenue-cell">₱<?= number_format($sum_revenue, 2) ?></td>
            <td class="number"><?= number_format($sum_waste_qty) ?> PCS</td>
            <td class="currency loss-cell">₱<?= number_format($sum_loss, 2) ?></td>
        </tr>
    </tbody>
</table>

</body>
</html>