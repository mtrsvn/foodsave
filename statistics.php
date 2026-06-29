<?php 
include 'db.php'; 
include 'auth.php';
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

$user_id = $_SESSION['user_id'];

if (isset($_GET['filter'])) {
    $_SESSION['stats_filter'] = $_GET['filter'];
    $_SESSION['stats_start'] = $_GET['start'] ?? '';
    $_SESSION['stats_end'] = $_GET['end'] ?? '';
    $_SESSION['stats_custom_group'] = $_GET['custom_group'] ?? 'day';
    
    // Dagdag na session memory para hindi mawala ang eksaktong linggong pinili ng user
    if ($_GET['custom_group'] === '1week') {
        $_SESSION['saved_start_wk_num'] = $_GET['start_wk_num'] ?? '';
        $_SESSION['saved_end_wk_num'] = $_GET['end_wk_num'] ?? '';
    }
}

$old_filter = $_SESSION['stats_filter'] ?? 'day';

if (isset($_GET['filter'])) {
    $new_filter = $_GET['filter'];
    if ($new_filter !== 'custom' || $old_filter !== $new_filter) {
        $_SESSION['stats_start'] = '';
        $_SESSION['stats_end'] = '';
        $_SESSION['stats_custom_group'] = 'day';
        $_SESSION['saved_start_wk_num'] = '';
        $_SESSION['saved_end_wk_num'] = '';
    }
    $_SESSION['stats_filter'] = $new_filter;
    if ($new_filter === 'custom' && isset($_GET['start'])) {
        $_SESSION['stats_start'] = $_GET['start'];
        $_SESSION['stats_end'] = $_GET['end'];
        $_SESSION['stats_custom_group'] = $_GET['custom_group'] ?? 'day';
    }
}

$filter = $_SESSION['stats_filter'] ?? 'day';
$start_date_input = $_SESSION['stats_start'] ?? '';
$end_date_input = $_SESSION['stats_end'] ?? '';
$custom_group = $_SESSION['stats_custom_group'] ?? 'day';

// Kunin ang sineb na linggo para sa select persistency
$saved_start_wk_num = $_SESSION['saved_start_wk_num'] ?? '';
$saved_end_wk_num = $_SESSION['saved_end_wk_num'] ?? '';

$start_date = date('Y-m-d', strtotime('-6 days'));
$end_date = date('Y-m-d');
$group_by = "DATE(date_action)"; 
$date_format = "%e"; 
$chart_period_title = date('F Y');

if ($filter == 'day') {
    $start_date = date('Y-m-d', strtotime('-6 days'));
    $group_by = "DATE(date_action)";
    $date_format = "%e";
    $chart_period_title = date('F Y');
} elseif ($filter == '1week') {
    $start_date = date('Y-m-d', strtotime('-4 weeks'));
    $group_by = "YEARWEEK(date_action, 1)";
    $date_format = "%x-%v"; 
    $chart_period_title = date('F Y');
} elseif ($filter == '1month') {
    $start_date = date('Y-m-01', strtotime('-11 months'));
    $group_by = "MONTH(date_action), YEAR(date_action)";
    $date_format = "%b-%Y"; 
    $chart_period_title = date('Y');
} elseif ($filter == 'custom' && !empty($start_date_input) && !empty($end_date_input)) {
    $start_date = $start_date_input;
    $end_date = $end_date_input;

    if ($custom_group == 'month') {
        $group_by = "MONTH(date_action), YEAR(date_action)";
        $date_format = "%b-%Y";
        $chart_period_title = date('Y', strtotime($start_date));
    } elseif ($custom_group == '1week') {
        $group_by = "YEARWEEK(date_action, 1)";
        $date_format = "%x-%v";
        $chart_period_title = date('F Y', strtotime($start_date));
    } else {
        $group_by = "DATE(date_action)";
        $date_format = "%e";
        $chart_period_title = date('F Y', strtotime($start_date));
    }
}

$filter_label = "Daily"; 
if ($filter == '1week') $filter_label = "Weekly";
if ($filter == '1month') $filter_label = "Monthly";
if ($filter == 'custom') $filter_label = "Custom Range";

$query = "
    SELECT 
        DATE_FORMAT(date_action, '$date_format') as action_day,
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

$db_data = [];
while($row = $result->fetch_assoc()){
    $db_data[$row['action_day']] = $row;
}

$total_revenue = 0; $total_loss = 0; $total_sold_qty = 0; $total_waste_qty = 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>FoodSave - Statistics</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/statistics.css">
    <style>
        /* Pantayin ang kapal ng custom dropdown panels para bumagay sa modern editorial design */
        .filter-bar select, .filter-bar input[type="date"] {
            height: 42px;
            padding: 8px 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            box-sizing: border-box;
            background-color: #fff;
        }
        .filter-bar select:focus, .filter-bar input[type="date"]:focus {
            outline: none;
            border-color: #10b981;
        }
    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>
    <main>
        <div class="header-section">
            <div class="header-text">
                <h2>Statistics</h2>
                <p>Track and analyze your <?php echo $filter_label; ?> activity</p>
            </div>
            <a href="javascript:void(0)" onclick="openStatsModal()" class="export-main">
                <i class="fas fa-file-pdf"></i> Export Report
            </a>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <div>
                    <span class="stat-label">Revenue (<?= $filter_label ?>)</span>
                    <span class="stat-value" id="cardRevenue" style="color: #10b981;">₱0.00</span>
                </div>
                <div class="icon-box" style="background: #ecfdf5; color: #10b981;"><i class="fas fa-coins"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <span class="stat-label">Waste Loss</span>
                    <span class="stat-value" id="cardLoss" style="color: #ef4444;">₱0.00</span>
                </div>
                <div class="icon-box" style="background: #fef2f2; color: #ef4444;"><i class="fas fa-trash-alt"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <span class="stat-label">Sold Items</span>
                    <span class="stat-value" id="cardSoldQty">0 PCS</span>
                </div>
                <div class="icon-box" style="background: #eff6ff; color: #3b82f6;"><i class="fas fa-shopping-basket"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <span class="stat-label">Waste Volume</span>
                    <span class="stat-value" id="cardWasteQty" style="color: #f59e0b;">0 PCS</span>
                </div>
                <div class="icon-box" style="background: #fffbeb; color: #f59e0b;"><i class="fas fa-dumpster"></i></div>
            </div>
        </div>

        <div class="filter-bar">
            <form method="GET" style="display:flex; gap:15px; align-items:flex-end; width: 100%; flex-wrap: wrap;">
                <div style="flex:1; min-width: 150px;">
                    <label style="font-size: 0.75rem; font-weight: 700;">VIEW BY</label><br>
                    <select name="filter" id="filterSelect" onchange="toggleCustomDates()" style="width:100%">
                        <option value="day" <?php echo ($filter == 'day') ? 'selected' : ''; ?>>Day (Last 7 Days)</option>
                        <option value="1week" <?php echo ($filter == '1week') ? 'selected' : ''; ?>>Week (Last 4 Weeks)</option>
                        <option value="1month" <?php echo ($filter == '1month') ? 'selected' : ''; ?>>Month (This Year)</option>
                        <option value="custom" <?php echo ($filter == 'custom') ? 'selected' : ''; ?>>Custom Range</option>
                    </select>
                </div>

                <div id="customDateInputs" style="display: <?php echo ($filter == 'custom') ? 'flex' : 'none'; ?>; gap: 10px; flex:4; min-width: 300px; flex-wrap: wrap; width:100%;">
                    <div style="flex:1; min-width: 120px;">
                        <label style="font-size: 0.75rem; font-weight: 700;">SHOW AS</label>
                        <select name="custom_group" id="customGroupSelect" onchange="adjustCustomInputFields()" style="width:100%">
                            <option value="day" <?php echo ($custom_group == 'day') ? 'selected' : ''; ?>>By Day</option>
                            <option value="1week" <?php echo ($custom_group == '1week') ? 'selected' : ''; ?>>By Week</option>
                            <option value="month" <?php echo ($custom_group == 'month') ? 'selected' : ''; ?>>By Month</option>
                        </select>
                    </div>

                    <div id="fieldsForDay" style="display:none; gap:10px; flex:2;">
                        <div style="flex:1">
                            <label style="font-size: 0.75rem; font-weight: 700;">START DATE</label>
                            <input type="date" id="inputStartDay" value="<?php echo htmlspecialchars($start_date_input); ?>" style="width:100%">
                        </div>
                        <div style="flex:1">
                            <label style="font-size: 0.75rem; font-weight: 700;">END DATE</label>
                            <input type="date" id="inputEndDay" value="<?php echo htmlspecialchars($end_date_input); ?>" style="width:100%">
                        </div>
                    </div>

                    <div id="fieldsForWeek" style="display:none; gap:10px; flex:2;">
                        <div style="flex:1">
                            <label style="font-size: 0.75rem; font-weight: 700;">WEEK START</label>
                            <div style="display: flex; gap: 5px;">
                                <select id="startWeekMonth" onchange="updateWeekValues()" style="flex: 2;"></select>
                                <select id="startWeekNum" name="start_wk_num" onchange="updateWeekValues()" style="flex: 1.2;">
                                    <option value="1" <?= $saved_start_wk_num == '1' ? 'selected' : '' ?>>1st Wk</option>
                                    <option value="2" <?= $saved_start_wk_num == '2' ? 'selected' : '' ?>>2nd Wk</option>
                                    <option value="3" <?= $saved_start_wk_num == '3' ? 'selected' : '' ?>>3rd Wk</option>
                                    <option value="4" <?= $saved_start_wk_num == '4' ? 'selected' : '' ?>>4th Wk</option>
                                    <option value="5" <?= $saved_start_wk_num == '5' ? 'selected' : '' ?>>5th Wk</option>
                                </select>
                            </div>
                        </div>
                        <div style="flex:1">
                            <label style="font-size: 0.75rem; font-weight: 700;">WEEK END</label>
                            <div style="display: flex; gap: 5px;">
                                <select id="endWeekMonth" onchange="updateWeekValues()" style="flex: 2;"></select>
                                <select id="endWeekNum" name="end_wk_num" onchange="updateWeekValues()" style="flex: 1.2;">
                                    <option value="1" <?= $saved_end_wk_num == '1' ? 'selected' : '' ?>>1st Wk</option>
                                    <option value="2" <?= $saved_end_wk_num == '2' ? 'selected' : '' ?>>2nd Wk</option>
                                    <option value="3" <?= $saved_end_wk_num == '3' ? 'selected' : '' ?>>3rd Wk</option>
                                    <option value="4" <?= $saved_end_wk_num == '4' ? 'selected' : '' ?>>4th Wk</option>
                                    <option value="5" <?= $saved_end_wk_num == '5' ? 'selected' : '' ?>>5th Wk</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="fieldsForMonth" style="display:none; gap:10px; flex:2;">
                        <div style="flex:1">
                            <label style="font-size: 0.75rem; font-weight: 700;">START MONTH</label>
                            <select id="inputStartMonth" style="width:100%"></select>
                        </div>
                        <div style="flex:1">
                            <label style="font-size: 0.75rem; font-weight: 700;">END MONTH</label>
                            <select id="inputEndMonth" style="width:100%"></select>
                        </div>
                    </div>

                    <input type="hidden" name="start" id="finalStart">
                    <input type="hidden" name="end" id="finalEnd">
                </div>

                <button type="submit" class="btn-update" onclick="prepareSubmitValues()">Update</button>
            </form>
        </div>

        <div class="charts-wrapper">
            <div class="chart-container">
                <div class="chart-header" style="text-align: center;">
                    <h3 style="margin:0; font-size: 1.1rem;">Financial Performance</h3>
                    <p style="margin:0; font-size: 0.85rem; color: #64748b; font-weight: 600;"><?= strtoupper($chart_period_title) ?></p>
                </div>
                <canvas id="moneyChart"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-header" style="text-align: center;">
                    <h3 style="margin:0; font-size: 1.1rem;">Quantity Analysis</h3>
                    <p style="margin:0; font-size: 0.85rem; color: #64748b; font-weight: 600;"><?= strtoupper($chart_period_title) ?></p>
                </div>
                <canvas id="countChart"></canvas>
            </div>
        </div>
    </main>

    <!-- MODAL LOGIC -->
    <div id="statsExportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-pdf"></i> Statistics Report Export</h3>
                <span style="cursor:pointer; font-size:1.5rem;" onclick="closeStatsModal()">&times;</span>
            </div>
            <form action="process_export_stats" method="POST" target="_blank" id="statsExportForm">
                <input type="hidden" name="money_chart_image" id="moneyChartInput">
                <input type="hidden" name="count_chart_image" id="countChartInput">
                <input type="hidden" name="revenue" id="hidRevenue">
                <input type="hidden" name="loss" id="hidLoss">
                <input type="hidden" name="sold_count" id="hidSoldCount">
                <input type="hidden" name="waste_count" id="hidWasteCount">
                <input type="hidden" name="filter_label" id="hidFilterLabel">

                <div class="export-option">
                    <label>REPORT SCOPE</label>
                    <select name="report_scope" id="reportScope">
                        <option value="ALL">Full Report (All Charts & Summary)</option>
                        <option value="FINANCIAL">Financial Performance Only</option>
                        <option value="QUANTITY">Quantity Analysis Only</option>
                    </select>
                </div>

                <div class="export-option">
                    <label>SELECT DATE RANGE</label>
                    <select name="date_preset" id="statsDatePreset">
                        <option value="today">Today's Activity</option>
                        <option value="month">This Full Month</option>
                        <option value="year">This Full Year</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeStatsModal()">Cancel</button>
                    <button type="submit" class="btn-confirm-export" onclick="captureCharts(event)">Generate Report</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const monthsArray = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    const shortMonths = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

    function toggleCustomDates() {
        const select = document.getElementById('filterSelect');
        const custom = document.getElementById('customDateInputs');
        custom.style.display = select.value === 'custom' ? 'flex' : 'none';
        if (select.value === 'custom') {
            adjustCustomInputFields();
        }
    }

    function populateWeekDropdowns() {
        const startMDropdown = document.getElementById('startWeekMonth');
        const endMDropdown = document.getElementById('endWeekMonth');
        if (!startMDropdown || !endMDropdown) return;

        startMDropdown.innerHTML = '';
        endMDropdown.innerHTML = '';
        const years = [2025, 2026, 2027];

        years.forEach(yr => {
            monthsArray.forEach((mName, mIdx) => {
                let opt = document.createElement('option');
                opt.value = `${yr}-${String(mIdx + 1).padStart(2, '0')}`;
                opt.textContent = `${mName} ${yr}`;
                startMDropdown.appendChild(opt.cloneNode(true));
                endMDropdown.appendChild(opt.cloneNode(true));
            });
        });

        let savedStart = "<?= ($filter === 'custom' && $custom_group === '1week') ? $start_date_input : '' ?>";
        let savedEnd = "<?= ($filter === 'custom' && $custom_group === '1week') ? $end_date_input : '' ?>";

        if (savedStart) {
            let parts = savedStart.split('-'); 
            document.getElementById('startWeekMonth').value = `${parts[0]}-${parts[1]}`;
        }
        if (savedEnd) {
            let parts = savedEnd.split('-');
            document.getElementById('endWeekMonth').value = `${parts[0]}-${parts[1]}`;
        }
    }

    function updateWeekValues() {
        let sMVal = document.getElementById('startWeekMonth').value; 
        let sWNum = parseInt(document.getElementById('startWeekNum').value);
        if (sMVal) {
            let startDay = ((sWNum - 1) * 7) + 1;
            document.getElementById('finalStart').value = `${sMVal}-${String(startDay).padStart(2, '0')}`;
        }

        let eMVal = document.getElementById('endWeekMonth').value; 
        let eWNum = parseInt(document.getElementById('endWeekNum').value);
        if (eMVal) {
            let parts = eMVal.split('-');
            let yr = parseInt(parts[0]);
            let mIdx = parseInt(parts[1]) - 1;
            let endDay = (eWNum === 4 || eWNum === 5) ? new Date(yr, mIdx + 1, 0).getDate() : eWNum * 7;
            document.getElementById('finalEnd').value = `${eMVal}-${String(endDay).padStart(2, '0')}`;
        }
    }

    function populateMonthDropdowns() {
        const startDropdown = document.getElementById('inputStartMonth');
        const endDropdown = document.getElementById('inputEndMonth');
        if (!startDropdown || !endDropdown) return;

        startDropdown.innerHTML = '';
        endDropdown.innerHTML = '';
        const years = [2025, 2026, 2027];

        let savedStart = "<?= ($filter === 'custom' && $custom_group === 'month') ? $start_date_input : '' ?>";
        let savedEnd = "<?= ($filter === 'custom' && $custom_group === 'month') ? $end_date_input : '' ?>";

        years.forEach(yr => {
            monthsArray.forEach((mName, mIdx) => {
                let displayTxt = `${mName} ${yr}`;
                let padMonth = String(mIdx + 1).padStart(2, '0');

                let valStart = `${yr}-${padMonth}-01`;
                let lastDay = new Date(yr, mIdx + 1, 0).getDate();
                let valEnd = `${yr}-${padMonth}-${lastDay}`;

                let optS = document.createElement('option');
                optS.value = valStart;
                optS.textContent = displayTxt;
                if (savedStart === valStart) optS.selected = true;
                startDropdown.appendChild(optS);

                let optE = document.createElement('option');
                optE.value = valEnd;
                optE.textContent = displayTxt;
                if (savedEnd === valEnd) optE.selected = true;
                endDropdown.appendChild(optE);
            });
        });
    }

    function adjustCustomInputFields() {
        const group = document.getElementById('customGroupSelect').value;
        const divDay = document.getElementById('fieldsForDay');
        const divWeek = document.getElementById('fieldsForWeek');
        const divMonth = document.getElementById('fieldsForMonth');

        divDay.style.display = 'none';
        divWeek.style.display = 'none';
        divMonth.style.display = 'none';

        if (group === 'day') {
            divDay.style.display = 'flex';
        } else if (group === '1week') {
            divWeek.style.display = 'flex';
            updateWeekValues();
        } else if (group === 'month') {
            divMonth.style.display = 'flex';
        }
    }

    function prepareSubmitValues() {
        const filter = document.getElementById('filterSelect').value;
        if (filter !== 'custom') return;

        const group = document.getElementById('customGroupSelect').value;
        let startVal = '';
        let endVal = '';

        if (group === 'day') {
            startVal = document.getElementById('inputStartDay').value;
            endVal = document.getElementById('inputEndDay').value;
            document.getElementById('finalStart').value = startVal;
            document.getElementById('finalEnd').value = endVal;
        } else if (group === '1week') {
            updateWeekValues(); 
        } else if (group === 'month') {
            startVal = document.getElementById('inputStartMonth').value;
            endVal = document.getElementById('inputEndMonth').value;
            document.getElementById('finalStart').value = startVal;
            document.getElementById('finalEnd').value = endVal;
        }
    }

    // --- DYNAMIC DATA EXPANSION WITH EMPTY GRID LINES ---
    const dbData = <?php echo json_encode($db_data); ?>;
    const filterType = "<?= $filter ?>";
    const customType = "<?= $custom_group ?>";

    let finalLabels = [];
    let finalSoldVals = [];
    let finalWasteVals = [];
    let finalSoldCounts = [];
    let finalWasteCounts = [];

    let totalRevenue = 0, totalLoss = 0, totalSoldQty = 0, totalWasteQty = 0;

    if (filterType === 'day' || (filterType === 'custom' && customType === 'day')) {
        let loopDate = new Date(filterType === 'custom' ? "<?= $start_date ?>" : new Date().setDate(new Date().getDate() - 6));
        let endDateObj = new Date(filterType === 'custom' ? "<?= $end_date ?>" : new Date());
        loopDate.setHours(0,0,0,0); endDateObj.setHours(0,0,0,0);

        while (loopDate <= endDateObj) {
            let dayNum = loopDate.getDate().toString();
            finalLabels.push("Day " + dayNum);
            
            let row = dbData[dayNum] || {sold_value:0, wasted_value:0, sold_count:0, wasted_count:0};
            finalSoldVals.push(parseFloat(row.sold_value));
            finalWasteVals.push(parseFloat(row.wasted_value));
            finalSoldCounts.push(parseInt(row.sold_count));
            finalWasteCounts.push(parseInt(row.wasted_count));

            totalRevenue += parseFloat(row.sold_value);
            totalLoss += parseFloat(row.wasted_value);
            totalSoldQty += parseInt(row.sold_count);
            totalWasteQty += parseInt(row.wasted_count);

            loopDate.setDate(loopDate.getDate() + 1);
        }
    }   else if (filterType === '1week' || (filterType === 'custom' && customType === '1week')) {
        let loopDate = new Date("<?= $start_date ?>");
        let endDateObj = new Date("<?= $end_date ?>");
        
        loopDate.setHours(0,0,0,0);
        endDateObj.setHours(0,0,0,0);
        
        function getYearWeekKey(date) {
            let d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
            let dayNum = d.getUTCDay() || 7;
            d.setUTCDate(d.getUTCDate() + 4 - dayNum);
            let year = d.getUTCFullYear();
            let startOfYear = new Date(Date.UTC(year,0,1));
            let week = Math.ceil((((d - startOfYear) / 86400000) + 1)/7);
            return year + "-" + String(week).padStart(2, '0');
        }

        let maxAllowedWeek = null;
        if (filterType === 'custom' && customType === '1week') {
            maxAllowedWeek = parseInt(document.getElementById('endWeekNum').value);
        }

        while (loopDate <= endDateObj) {
            let weekKey = getYearWeekKey(loopDate);
            let monthName = shortMonths[loopDate.getMonth()];
            let weekOfMonth = Math.min(5, Math.ceil(loopDate.getDate() / 7));
            
            if (maxAllowedWeek !== null && weekOfMonth > maxAllowedWeek) {
                break;
            }

            let displayLabel = `W${weekOfMonth} ${monthName}`;

            if (!finalLabels.includes(displayLabel)) {
                finalLabels.push(displayLabel);
                let row = dbData[weekKey] || {sold_value:0, wasted_value:0, sold_count:0, wasted_count:0};
                
                finalSoldVals.push(parseFloat(row.sold_value));
                finalWasteVals.push(parseFloat(row.wasted_value));
                finalSoldCounts.push(parseInt(row.sold_count));
                finalWasteCounts.push(parseInt(row.wasted_count));

                totalRevenue += parseFloat(row.sold_value);
                totalLoss += parseFloat(row.wasted_value);
                totalSoldQty += parseInt(row.sold_count);
                totalWasteQty += parseInt(row.wasted_count);
            }
            loopDate.setDate(loopDate.getDate() + 7);
        }
    } else if (filterType === '1month' || (filterType === 'custom' && customType === 'month')) {
        let loopDate = new Date(filterType === 'custom' ? "<?= $start_date ?>" : new Date(new Date().getFullYear(), 0, 1));
        let endDateObj = new Date(filterType === 'custom' ? "<?= $end_date ?>" : new Date(new Date().getFullYear(), 11, 31));

        while (loopDate <= endDateObj) {
            let shortMName = shortMonths[loopDate.getMonth()];
            let monthKey = shortMName + "-" + loopDate.getFullYear();
            finalLabels.push(shortMName + " " + loopDate.getFullYear());

            let row = dbData[monthKey] || {sold_value:0, wasted_value:0, sold_count:0, wasted_count:0};
            finalSoldVals.push(parseFloat(row.sold_value));
            finalWasteVals.push(parseFloat(row.wasted_value));
            finalSoldCounts.push(parseInt(row.sold_count));
            finalWasteCounts.push(parseInt(row.wasted_count));

            totalRevenue += parseFloat(row.sold_value);
            totalLoss += parseFloat(row.wasted_value);
            totalSoldQty += parseInt(row.sold_count);
            totalWasteQty += parseInt(row.wasted_count);

            loopDate.setMonth(loopDate.getMonth() + 1);
        }
    }

    // Dynamic Updates para sa Dashboard Stat Cards counter
    document.getElementById('cardRevenue').textContent = '₱' + totalRevenue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('cardLoss').textContent = '₱' + totalLoss.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('cardSoldQty').textContent = totalSoldQty.toLocaleString('en-US') + ' PCS';
    document.getElementById('cardWasteQty').textContent = totalWasteQty.toLocaleString('en-US') + ' PCS';

    const commonOptions = {
        responsive: true,
        maintainAspectRatio: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, font: { family: 'Poppins' } } }
        },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true } }
    };

    const moneyCtx = document.getElementById('moneyChart').getContext('2d');
    new Chart(moneyCtx, {
        type: 'line',
        data: {
            labels: finalLabels,
            datasets: [
                { label: 'Revenue', data: finalSoldVals, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true },
                { label: 'Waste Loss', data: finalWasteVals, borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.1)', fill: true }
            ]
        },
        options: commonOptions
    });

    const countCtx = document.getElementById('countChart').getContext('2d');
    new Chart(countCtx, {
        type: 'line',
        data: {
            labels: finalLabels,
            datasets: [
                { label: 'Sold Units', data: finalSoldCounts, borderColor: '#3b82f6', fill: false },
                { label: 'Wasted Units', data: finalWasteCounts, borderColor: '#f59e0b', fill: false }
            ]
        },
        options: commonOptions
    });

    window.addEventListener('DOMContentLoaded', () => {
        populateWeekDropdowns();
        populateMonthDropdowns();
        if (document.getElementById('filterSelect').value === 'custom') {
            adjustCustomInputFields();
        }
    });

    function openStatsModal() { document.getElementById('statsExportModal').style.display = 'block'; }
    function closeStatsModal() { document.getElementById('statsExportModal').style.display = 'none'; }

    function captureCharts(e) {
        document.getElementById('moneyChartInput').value = document.getElementById('moneyChart').toDataURL("image/png");
        document.getElementById('countChartInput').value = document.getElementById('countChart').toDataURL("image/png");
        document.getElementById('hidRevenue').value = totalRevenue;
        document.getElementById('hidLoss').value = totalLoss;
        document.getElementById('hidSoldCount').value = totalSoldQty;
        document.getElementById('hidWasteCount').value = totalWasteQty;
        const select = document.getElementById('filterSelect');
        document.getElementById('hidFilterLabel').value = select.options[select.selectedIndex].text;
    }
    </script>
</body>
</html>