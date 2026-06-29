<?php
include 'auth.php';
include 'db.php';
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: history.php");
    exit();
}

$user_id     = $_SESSION['user_id'];
$report_type = $_POST['report_type']; 
$date_preset = $_POST['date_preset'];
$format      = $_POST['format'];    

$start_date = "";
$end_date   = "";

if ($date_preset === 'today') {
    $start_date = date('Y-m-d 00:00:00');
    $end_date   = date('Y-m-d 23:59:59');
} elseif ($date_preset === 'month') {
    $start_date = date('Y-m-01 00:00:00');
    $end_date   = date('Y-m-t 23:59:59');
} elseif ($date_preset === 'year') {
    $start_date = date('Y-01-01 00:00:00');
    $end_date   = date('Y-12-31 23:59:59');
} elseif ($date_preset === 'custom') {
    $start_date = $_POST['m_start_date'] . " 00:00:00";
    $end_date   = $_POST['m_end_date'] . " 23:59:59";
}

$where_clause = "WHERE h.user_id = ?";
$params = [$user_id];
$types = "i";

if ($report_type !== 'ALL') {
    $where_clause .= " AND h.type = ?";
    $params[] = $report_type;
    $types .= "s";
}

if ($start_date && $end_date) {
    $where_clause .= " AND h.created_at BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

$query = "SELECT h.*, p.price, p.name as prod_name 
          FROM history h 
          LEFT JOIN products p ON h.product_id = p.product_id 
          $where_clause ORDER BY h.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$title = ($report_type === 'ALL') ? "GENERAL HISTORY REPORT" : "HISTORY REPORT FOR $report_type";
$display_range = ($date_preset === 'custom') ? "$_POST[m_start_date] to $_POST[m_end_date]" : strtoupper($date_preset);

if ($format === 'excel') {
    $filename = "FoodSave_" . str_replace(" ", "_", $title) . "_" . date('Ymd_His') . ".xls";
    
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <style>
            .text-mode { mso-number-format:"\@"; }
            .price-mode { mso-number-format:"\#\,\#\#0\.00"; }
        </style>
    </head>
    <body>
        <table>
            <tr>
                <th colspan="6" style="font-size: 16px; font-weight: bold; text-align: center;"><?= $title ?></th>
            </tr>
            <tr>
                <th colspan="6" style="font-size: 12px; text-align: center;">FoodSave Inventory Management System</th>
            </tr>
            <tr>
                <td colspan="3"><b>Coverage:</b> <?= $display_range ?></td>
                <td colspan="3" style="text-align: right;"><b>Generated:</b> <?= date('M d, Y - h:i A') ?></td>
            </tr>
            <tr></tr> <thead>
                <tr style="background-color: #f2f2f2;">
                    <th style="border: 0.5pt solid #ccc; font-weight: bold;">Date</th>
                    <th style="border: 0.5pt solid #ccc; font-weight: bold;">Product Name</th>
                    <th style="border: 0.5pt solid #ccc; font-weight: bold;">Type</th>
                    <th style="border: 0.5pt solid #ccc; font-weight: bold;">Qty</th>
                    <th style="border: 0.5pt solid #ccc; font-weight: bold;">Unit Price (PHP)</th>
                    <th style="border: 0.5pt solid #ccc; font-weight: bold;">Total Price (PHP)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grand_total = 0;
                if ($result->num_rows > 0): 
                    while($row = $result->fetch_assoc()): 
                        $total = $row['quantity'] * ($row['price'] ?? 0);
                        $grand_total += $total;
                ?>
                    <tr>
                        <td style="border: 0.5pt solid #ccc;" class="text-mode"><?= date('Y-m-d H:i', strtotime($row['created_at'])) ?></td>
                        <td style="border: 0.5pt solid #ccc;"><?= htmlspecialchars($row['prod_name'] ?: $row['product_name']) ?></td>
                        <td style="border: 0.5pt solid #ccc; text-align: center;"><?= $row['type'] ?></td>
                        <td style="border: 0.5pt solid #ccc; text-align: right;"><?= $row['quantity'] ?></td>
                        <td style="border: 0.5pt solid #ccc; text-align: right;" class="price-mode"><?= number_format($row['price'] ?? 0, 2, '.', '') ?></td>
                        <td style="border: 0.5pt solid #ccc; text-align: right;" class="price-mode"><?= number_format($total, 2, '.', '') ?></td>
                    </tr>
                <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="border: 0.5pt solid #ccc; text-align:center;">No records found for this period.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5" style="border: 0.5pt solid #ccc; text-align: right; font-weight: bold;">Overall Total:</th>
                    <th style="border: 0.5pt solid #ccc; text-align: right; font-weight: bold;" class="price-mode"><?= number_format($grand_total, 2, '.', '') ?></th>
                </tr>
            </footer>
        </table>
    </body>
    </html>
    <?php
    exit();
}

ob_start();
?>
<!DOCTYPE html>
<html>

<head>
    <title><?= $title ?></title>
    <style>
    body {
        font-family: 'Arial', sans-serif;
        margin: 30px;
        color: #333;
    }

    .header {
        text-align: center;
        margin-bottom: 30px;
        border-bottom: 2px solid #444;
        padding-bottom: 10px;
    }

    .header h1 {
        margin: 0;
        font-size: 24px;
    }

    .meta-info {
        display: flex;
        justify-content: space-between;
        margin-bottom: 15px;
        font-size: 13px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    th,
    td {
        border: 1px solid #333;
        padding: 10px;
        text-align: left;
        font-size: 12px;
    }

    th {
        background-color: #f2f2f2;
        text-transform: uppercase;
    }

    .footer {
        margin-top: 30px;
        font-size: 11px;
        text-align: center;
        color: #777;
    }

    @media print {
        .no-print {
            display: none;
        }
    }
    </style>
</head>

<body>

    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer;">Print to PDF / Save as
            Image</button>
    </div>

    <div class="header">
        <h1><?= $title ?></h1>
        <p>FoodSave Inventory Management System</p>
    </div>

    <div class="meta-info">
        <span><strong>Coverage:</strong> <?= $display_range ?></span>
        <span><strong>Generated:</strong> <?= date('M d, Y - h:i A') ?></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Product Name</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Total Price</th>
            </tr>
        </thead>
        <tbody>
            <?php 
        $grand_total = 0;
        if ($result->num_rows > 0): 
            while($row = $result->fetch_assoc()): 
                $total = $row['quantity'] * ($row['price'] ?? 0);
                $grand_total += $total;
        ?>
            <tr>
                <td><?= date('M d, Y | H:i', strtotime($row['created_at'])) ?></td>
                <td><?= htmlspecialchars($row['prod_name'] ?: $row['product_name']) ?></td>
                <td><?= $row['type'] ?></td>
                <td><?= $row['quantity'] ?></td>
                <td>₱<?= number_format($row['price'] ?? 0, 2) ?></td>
                <td>₱<?= number_format($total, 2) ?></td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr>
                <td colspan="6" style="text-align:center;">No records found for this period.</td>
            </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5" style="text-align: right;">Overall Total:</th>
                <th>₱<?= number_format($grand_total, 2) ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <p>This is a system-generated report from FoodSave.</p>
    </div>

</body>

</html>
<?php
$html_content = ob_get_clean();

if ($format === 'pdf') {
    echo $html_content;
    echo "<script>window.onload = function() { window.print(); }</script>";
} else {
    echo $html_content;
}
?>