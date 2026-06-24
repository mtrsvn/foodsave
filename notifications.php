<?php
session_start();
include 'db.php'; 

if (!isset($_SESSION['user_id'])) {
    header("Location: index");
    exit();
}

$user_id = $_SESSION['user_id'];

if (isset($_GET['filter'])) {
    $_SESSION['last_filter'] = $_GET['filter'];
}
$filter = $_SESSION['last_filter'] ?? 'All';

if (isset($_GET['time_range'])) {
    $_SESSION['last_time_range'] = $_GET['time_range'];
}
$time_range = $_SESSION['last_time_range'] ?? 'ALL';

if (isset($_GET['start_date'])) {
    $_SESSION['last_start_date'] = $_GET['start_date'];
}
$start_date = $_SESSION['last_start_date'] ?? '';

if (isset($_GET['end_date'])) {
    $_SESSION['last_end_date'] = $_GET['end_date'];
}
$end_date = $_SESSION['last_end_date'] ?? '';

$search = $_GET['search'] ?? '';

$where_parts = ["user_id = ?"];
$params = [$user_id];
$types = "i";

$stats_parts = ["user_id = ?"];
$stats_params = [$user_id];
$stats_types = "i";

if ($filter !== 'All') {
    $where_parts[] = "category = ?";
    $params[] = $filter;
    $types .= "s";
}

if ($time_range === 'CUSTOM' && !empty($start_date) && !empty($end_date)) {
    $date_filter = "DATE(created_at) BETWEEN ? AND ?";
    $where_parts[] = $date_filter;
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";

    $stats_parts[] = $date_filter;
    $stats_params[] = $start_date;
    $stats_params[] = $end_date;
    $stats_types .= "ss";
} elseif ($time_range !== 'ALL') { 
    $time_sql = "";
    if ($time_range === 'RECENT') $time_sql = "created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
    elseif ($time_range === '3DAYS') $time_sql = "created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)";
    elseif ($time_range === '1WEEK') $time_sql = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    elseif ($time_range === '1MONTH') $time_sql = "created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";

    if (!empty($time_sql)) {
        $where_parts[] = $time_sql;
        $stats_parts[] = $time_sql; 
    }
}

if (!empty($search)) {
    $search_clause = "(title LIKE ? OR message LIKE ?)";
    $search_param = "%$search%";
    
    $where_parts[] = $search_clause;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";

    $stats_parts[] = $search_clause;
    $stats_params[] = $search_param;
    $stats_params[] = $search_param;
    $stats_types .= "ss";
}

$where_clause = "WHERE " . implode(" AND ", $where_parts);
$stats_where = "WHERE " . implode(" AND ", $stats_parts);


// --- 2. PAGINATION CALCULATIONS ---
$limit = 7;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications $where_clause");
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

$query = "SELECT *, notif_id AS id FROM notifications $where_clause ORDER BY created_at DESC LIMIT ?, ?";
$stmt = $conn->prepare($query);
$final_params = array_merge($params, [$offset, $limit]);
$stmt->bind_param($types . "ii", ...$final_params);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$count_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN category = 'Near Expiry' THEN 1 ELSE 0 END) as near,
    SUM(CASE WHEN category = 'Expired' THEN 1 ELSE 0 END) as expired,
    SUM(CASE WHEN category = 'Low Stock' THEN 1 ELSE 0 END) as low
    FROM notifications $stats_where";
    
$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param($stats_types, ...$stats_params);
$count_stmt->execute();
$stats_res = $count_stmt->get_result()->fetch_assoc();

$counts = [
    "All" => (int)$stats_res['total'], 
    "Low Stock" => (int)$stats_res['low'], 
    "Near Expiry" => (int)$stats_res['near'], 
    "Expired" => (int)$stats_res['expired']
];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>FoodSave - Notifications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/notifications.css">
    <script src="https://js.pusher.com/8.2/pusher.min.js"></script>
</head>

<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        <div class="main-content">
            <div class="header-card">
                <h1 style="margin-bottom: 5px;">Notifications</h1>
                <p style="color:#64748b;">Managing alerts for your branch.</p>
            </div>

            <div class="stats-row">
                <div class="stat-card">
                    <div>
                        <p>Total Alerts</p>
                        <h2><?= $counts['All'] ?></h2>
                    </div>
                    <i class="fas fa-bell" style="color:#8dae84;"></i>
                </div>
                <div class="stat-card">
                    <div>
                        <p>Near Expiry</p>
                        <h2 style="color:#f59e0b;"><?= $counts['Near Expiry'] ?></h2>
                    </div>
                    <i class="fas fa-clock" style="color:#f59e0b;"></i>
                </div>
                <div class="stat-card">
                    <div>
                        <p>Critical</p>
                        <h2 style="color:#ef4444;"><?= $counts['Expired'] + $counts['Low Stock'] ?></h2>
                    </div>
                    <i class="fas fa-triangle-exclamation" style="color:#ef4444;"></i>
                </div>
            </div>

            <div class="content-pane" style="margin-top: 6px;">
                <div class="filter-header"
                    style="display: flex; align-items: center; justify-content: space-between; padding: 20px 30px; border-bottom: 1px solid #eee;">
                    <div style="display: flex; gap: 10px;">
                        <button class="filter-btn <?= $filter == 'All' ? 'active' : '' ?>" data-filter="All">All Items
                            (<?= $counts['All'] ?>)</button>
                        <button class="filter-btn <?= $filter == 'Low Stock' ? 'active' : '' ?>"
                            data-filter="Low Stock">Low Stock (<?= $counts['Low Stock'] ?>)</button>
                        <button class="filter-btn <?= $filter == 'Near Expiry' ? 'active' : '' ?>"
                            data-filter="Near Expiry">Near Expiry (<?= $counts['Near Expiry'] ?>)</button>
                        <button class="filter-btn <?= $filter == 'Expired' ? 'active' : '' ?>"
                            data-filter="Expired">Expired (<?= $counts['Expired'] ?>)</button>
                    </div>

<div class="notif-search-wrapper" style="position: relative; display: flex; align-items: center; width: 300px;">
    <i class="fas fa-search" style="position: absolute; left: 15px; color: #94a3b8;"></i>
    
    <input type="text" id="notifSearchInput" 
           onkeyup="if(event.key === 'Enter') triggerSearch()" 
           value="<?= htmlspecialchars($search) ?>" 
           placeholder="Search here..."
           style="width: 100%; padding: 10px 50px 10px 40px; border-radius: 25px; border: 1px solid #e2e8f0; outline: none; transition: all 0.3s ease;">

    <button type="button" class="search-circle-btn" onclick="triggerSearch()" 
        style="position: absolute; right: 5px; width: 35px; height: 35px; border-radius: 50%; background: #8dae84; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 10;">
    <i class="fas fa-arrow-right" style="color: #ffffff; font-size: 1rem; font-weight: 900;"></i>
</button>
</div>

                   <form method="GET" id="filterForm" style="display: flex; align-items: center; gap: 12px;">
    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
    
    <select name="time_range" id="filterSelect" onchange="handleFilterChange(this.value)"
        style="padding: 8px; border-radius: 8px; border: 1px solid #e2e8f0;">
        <option value="ALL" <?= ($time_range == 'ALL') ? 'selected' : '' ?>>All Time</option>
        <option value="RECENT" <?= ($time_range == 'RECENT') ? 'selected' : '' ?>>Recent (24h)</option>
        <option value="3DAYS" <?= ($time_range == '3DAYS') ? 'selected' : '' ?>>3 Days Ago</option>
        <option value="1WEEK" <?= ($time_range == '1WEEK') ? 'selected' : '' ?>>1 Week Ago</option>
        <option value="1MONTH" <?= ($time_range == '1MONTH') ? 'selected' : '' ?>>1 Month Ago</option>
        <option value="CUSTOM" <?= ($time_range == 'CUSTOM') ? 'selected' : '' ?>>Custom Range</option>
    </select>

    <div id="customDateInputs" style="display: <?= ($time_range == 'CUSTOM') ? 'flex' : 'none' ?>; gap: 8px; align-items: center;">
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" 
               style="padding: 7px; border-radius: 8px; border: 1px solid #e2e8f0;">
        <span style="color: #64748b;">to</span>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" 
               style="padding: 7px; border-radius: 8px; border: 1px solid #e2e8f0;">
    </div>
</form>
                </div>

                <button onclick="markAllAsRead()"
                    style="background: white; border: 1px solid #ef4444; color: #ef4444; padding: 10px 18px; border-radius: 12px; font-weight: 700; cursor: pointer; margin: 10px;">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>

                <div style="display: flex; flex: 1; min-height: 0;">
                    <div id="notif-list"
                        style="width: 35%; border-right: 1px solid #eee; display: flex; flex-direction: column;">
                        <div style="flex: 1; overflow-y: auto;">
                            <?php if (empty($notifications)): ?>
                            <p style="text-align:center; margin-top:20px; color:#94a3b8;">No notifications found.</p>
                            <?php else: ?>
                            <?php foreach($notifications as $n): 
                                    $border_color = ($n['category'] == 'Expired') ? '#ef4444' : (($n['category'] == 'Low Stock') ? '#3b82f6' : '#f59e0b');
                                ?>
                            <div class="notif-item <?= $n['category'] ?> <?= $n['is_read'] == 0 ? 'unread' : '' ?>"
                                onclick="viewDetails(<?= htmlspecialchars(json_encode($n)) ?>, this)"
                                style="<?= $n['is_read'] == 0 ? 'background:#f0fdf4; border-left:4px solid '.$border_color : 'border-left:4px solid transparent' ?>">
                                <strong><?= $n['title'] ?></strong>
                                <p
                                    style="font-size:0.8rem; color:#64748b; margin:5px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?= $n['message'] ?></p>
                                <small
                                    style="color:#94a3b8;"><?= date('M d, H:i', strtotime($n['created_at'])) ?></small>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="pagination-wrapper"
                            style="padding: 15px; border-top: 1px solid #eee; background: #f8fafc;">
                            <div class="pagination-info"
                                style="font-size: 0.75rem; color: #64748b; margin-bottom: 5px;">
                                Showing <b><?= ($total_rows > 0) ? $offset + 1 : 0 ?></b> to
                                <b><?= min($offset + $limit, $total_rows) ?></b> of <b><?= $total_rows ?></b> entries
                            </div>
                            <div class="pagination-controls" style="display: flex; align-items: center; gap: 5px;">
                                <?php 
                                    $base_url = "notifications.php?filter=" . urlencode($filter) . (!empty($search) ? "&search=" . urlencode($search) : "") . "&time_range=" . urlencode($time_range);
                                ?>
                                <a href="<?= ($page > 1) ? $base_url . "&page=1" : '#' ?>"
                                    class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>"><i
                                        class="fas fa-angle-double-left"></i></a>
                                <a href="<?= ($page > 1) ? $base_url . "&page=" . ($page - 1) : '#' ?>"
                                    class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>">PREV</a>
                                <div class="page-numbers" style="display: flex; gap: 4px;">
                                    <?php for($i = 1; $i <= $total_pages; $i++): 
                                        if($i == 1 || $i == $total_pages || ($i >= $page - 1 && $i <= $page + 1)): ?>
                                    <a href="<?= $base_url ?>&page=<?= $i ?>"
                                        class="num-btn <?= ($page == $i) ? 'active' : '' ?>"><?= $i ?></a>
                                    <?php elseif($i == $page - 2 || $i == $page + 2): ?>
                                    <span style="color: #cbd5e1;">...</span>
                                    <?php endif; endfor; ?>
                                </div>

                                <a href="<?= ($page < $total_pages) ? $base_url . "&page=" . ($page + 1) : '#' ?>"
                                    class="page-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>">NEXT</a>

                                <a href="<?= ($page < $total_pages) ? $base_url . "&page=" . $total_pages : '#' ?>"
                                    class="page-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div id="details-panel" style="flex: 1; background: white; overflow-y: auto;">
                        <div class="content-wrapper" id="panel-content" style="text-align:center; padding: 100px 50px;">
                            <i class="fas fa-envelope-open"
                                style="font-size: 4rem; opacity: 0.1; margin-bottom: 20px;"></i>
                            <p style="font-size: 1.1rem; font-weight: 500;">Select a notification to read</p>
                            <small>Full details will appear here</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
function triggerSearch() {
    const searchValue = document.getElementById('notifSearchInput').value;
    searchNotifications(searchValue);
}

// Siguraduhin na ang existing searchNotifications function mo ay ganito:
function searchNotifications(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('search', val);
    url.searchParams.set('page', '1'); // Balik sa page 1 pag nag-search
    window.location.href = url.href;
}

    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.onclick = function() {
            const filter = this.getAttribute('data-filter');
            const url = new URL(window.location.href);
            url.searchParams.set('filter', filter);
            url.searchParams.set('page', '1');
            window.location.href = url.href;
        };
    });

    function viewDetails(n, element) {
        const panel = document.getElementById('details-panel');
        const notifId = n.id || n.notif_id;
        const recommendation = n.recommendation || "Monitor this item and take necessary action.";

        // Fix: Format date correctly INSIDE the function
        const dateRaw = new Date(n.expiry_date);
        const formattedDate = (dateRaw.getMonth() + 1).toString().padStart(2, '0') + '/' +
            dateRaw.getDate().toString().padStart(2, '0') + '/' +
            dateRaw.getFullYear();

        let cleanProductName = n.product_name || n.title.replace(/LOW STOCK:|EXPIRING:|EXPIRED:/gi, '').trim();

        panel.innerHTML = `
            <div style="padding: 30px; margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 20px;">
                    <div>
                        <h2 style="margin: 0 0 10px 0; color: #1e293b;">${n.title}</h2>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <span style="background: #f1f5f9; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">${n.category}</span>
                            <small style="color: #94a3b8;"><i class="fas fa-calendar-alt"></i> Received: ${n.created_at}</small>
                        </div>
                    </div>
                    <button onclick="location.reload()" style="background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 1.5rem;">&times;</button>
                </div>
                <div style="background: #f8fafc; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; line-height: 1.6;">
                    <div style="margin-bottom: 15px; font-weight: 500; font-size: 1.1rem;">${n.message}</div>
                    <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div><span style="color: #94a3b8;">Product:</span><br><strong>${cleanProductName}</strong></div>
                        <div><span style="color: #94a3b8;">Expiry:</span><br><strong>${formattedDate}</strong></div>
                        <div><span style="color: #94a3b8;">Stock Left:</span><br><strong>${n.remaining_stocks} units</strong></div>
                    </div>
                    <div style="margin-top: 25px; padding: 15px; background: #fffbeb; border-left: 4px solid #f59e0b;">
                        <h4 style="margin: 0; color: #92400e;"><i class="fas fa-lightbulb"></i> System Recommendation:</h4>
                        <p style="margin: 5px 0 0; color: #b45309; font-style: italic;">"${recommendation}"</p>
                    </div>
                </div>
                <div style="margin-top: 20px;">
                    <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: white; border: 1px solid #cbd5e1; border-radius: 8px;">
                        <i class="fas fa-print"></i> Print Details
                    </button>
                </div>
            </div>
        `;

        if (element.classList.contains('unread')) {
            fetch('update_notif_status.php?id=' + notifId)
                .then(res => res.text())
                .then(data => {
                    if (data.trim() === "success" || data.trim() === "no_change") {
                        element.classList.remove('unread');
                        element.style.background = "white";
                        element.style.borderLeft = "4px solid transparent";
                        updateBadgeCount();
                    }
                });
        }
        document.querySelectorAll('.notif-item').forEach(i => i.classList.remove('active'));
        element.classList.add('active');
    }

    function markAllAsRead() {
        if (confirm("Mark all as read?")) {
            fetch('php/mark_all_read').then(() => window.location.reload());
        }
    }

    function updateBadgeCount() {
        fetch('badge_counter')
            .then(res => res.json())
            .then(data => {
                window.postMessage({
                    type: 'NOTIF_COUNT_UPDATE',
                    count: data.total_unread
                }, "*");

                const localBadge = document.querySelector('.badge');
                if (localBadge) {
                    if (data.total_unread > 0) {
                        localBadge.innerText = data.total_unread;
                    } else {
                        localBadge.remove();
                    }
                }
            });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const pusher = new Pusher('d97196e21b43e27b46ba', {
            cluster: 'ap1',
            encrypted: true
        });
        const channel = pusher.subscribe('user.' + <?= $user_id ?>);
        channel.bind('new-notification', (data) => {
            console.log("New alert received via Pusher");
            location.reload();
        });
    });
    
    function handleFilterChange(val) {
    const customDiv = document.getElementById('customDateInputs');
    
    if (val === 'CUSTOM') {
        // Ipakita ang date boxes at HUWAG mag-submit agad para makapag-input ang user
        customDiv.style.display = 'flex';
    } else {
        // Itago ang date boxes at MAG-SUBMIT agad para mabilis ang loading
        customDiv.style.display = 'none';
        document.getElementById('filterForm').submit();
    }
}

    </script>
</body>

</html>