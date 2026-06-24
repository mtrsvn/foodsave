<?php
$notif_user_id = $_SESSION['user_id'];
$count_query = "SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_count = $conn->prepare($count_query);
$stmt_count->bind_param("i", $notif_user_id);
$stmt_count->execute();
$notif_count = $stmt_count->get_result()->fetch_assoc()['total'] ?? 0;
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');
?>

<aside>
    <div class="brand">
        <div class="brand-icon"><i class="fas fa-shopping-cart"></i></div>
        <h1>FoodSave</h1>
    </div>

    <div class="user-profile">
        <div class="avatar">
            <?php echo strtoupper(substr($_SESSION['branch_name'] ?? 'U', 0, 1)); ?>
        </div>
        <div class="user-name">
            <?php echo $_SESSION['branch_name'] ?? 'Guest'; ?>
        </div>
        <div class="user-email">
            <?php echo $_SESSION['user_email'] ?? ''; ?>
        </div>
    </div>

    <nav>
        <?php
        $current_page = basename($_SERVER['PHP_SELF']);
        ?>
        <ul>
            <li>
                <a href="dashboard.php" class="<?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="inventory.php" class="<?= ($current_page == 'inventory.php') ? 'active' : '' ?>">
                    <i class="fas fa-box"></i> Inventory
                </a>
            </li>
            <li>
               <a href="product-management.php" class="<?= ($current_page == 'product-management.php') ? 'active' : '' ?>"> 
                    <i class="fa-solid fa-expand"></i> Scan Products
                </a>
            </li>
            <li>
                <a href="notifications.php" class="<?= ($current_page == 'notifications.php') ? 'active' : '' ?>">
                    <i class="fa-solid fa-bell"></i> Notifications
                    <?php if ($notif_count > 0): ?>
                        <span class="badge"><?php echo $notif_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li>
                <a href="statistics.php" class="<?= ($current_page == 'statistics.php') ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar"></i> Statistics
                </a>
            </li>
            <li>
                <a href="history.php" class="<?= ($current_page == 'history.php') ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i> History
                </a>
            </li>
            <li> <a href="setting.php" class="<?= ($current_page == 'setting.php') ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>
        </ul>
    </nav>
</aside>

<style>
.nav-item, nav ul li a {
    position: relative;
    display: flex;
    align-items: center;
}

.badge {
    background-color: #ef4444; 
    color: white;
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 50%;
    position: absolute;
    right: 15px;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
</style>

<script>
let pusher = null;
let channel = null;

function initPusher() {
    pusher = new Pusher('d97196e21b43e27b46ba', {
        cluster: 'ap1',
        encrypted: true
    });
    
    channel = pusher.subscribe('user.<?= $_SESSION['user_id'] ?>');
    
    channel.bind('new-notification', function(data) {
        console.log('🔔 LIVE ALERT:', data.title);
        
        checkAndGenerateAlerts(); 
        
        shakeBellIcon();
        playNotificationSound();
        
        if (Notification.permission === 'granted') {
            new Notification('FoodSave: ' + data.title, {
                body: data.message,
                icon: 'favicon.ico'
            });
        }
    });
}

function playNotificationSound() {
    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = audioContext.createOscillator();
    const gainNode = audioContext.createGain();
    oscillator.connect(gainNode);
    gainNode.connect(audioContext.destination);
    oscillator.frequency.value = 800;
    oscillator.type = 'sine';
    gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
    gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
    oscillator.start(audioContext.currentTime);
    oscillator.stop(audioContext.currentTime + 0.3);
}

function checkAndGenerateAlerts() {
    fetch('badge_counter') 
        .then(res => {
            if (!res.ok) throw new Error('Network response was not ok');
            return res.json();
        })
        .then(data => {
            updateBadgeUI(data.total_unread);
            
            window.postMessage({
                type: 'NOTIF_COUNT_UPDATE',
                count: data.total_unread
            }, "*");
        })
        .catch(err => console.log('Badge update failed:', err));
}
function shakeBellIcon() {
    const bell = document.querySelector('a[href^="notifications"] i');
    if (bell) {
        bell.style.animation = 'shake 0.5s ease-in-out';
        setTimeout(() => bell.style.animation = '', 500);
    }
}

function showNewAlertNotification(count) {
    if (Notification.permission === 'granted') {
        new Notification('🔔 FoodSave LIVE Alert', {
            body: `New inventory alert received!`,
            icon: 'favicon.ico'
        });
    }
}

function updateBadgeUI(count) {
    let notifLink = document.querySelector('a[href="notifications"], a[href="notifications.php"]');
    if (!notifLink) return;

    let badge = notifLink.querySelector('.badge');
    
    if (count > 0) {
        if (badge) {
            badge.innerText = count;
            badge.style.display = 'inline-block';
        } else {
            badge = document.createElement('span');
            badge.className = 'badge';
            badge.innerText = count;
            badge.style.cssText = `
                background: #ef4444; color: white; 
                font-size: 0.7rem; padding: 2px 6px; 
                border-radius: 50%; position: absolute; 
                right: 15px; font-weight: bold; 
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            `;
            notifLink.appendChild(badge);
        }
    } else if (badge) {
        badge.remove();
    }
}


document.addEventListener('DOMContentLoaded', function() {
    initPusher();
    
    window.addEventListener('message', function(e) {
    if (e.data.type === 'NOTIF_COUNT_UPDATE') {
        updateBadgeUI(e.data.count);
    }
});

    setInterval(checkAndGenerateAlerts, 600000);
    checkAndGenerateAlerts();
    
    if (Notification.permission === 'default') {
        Notification.requestPermission();
    }
});

console.log('Sidebar: PUSHER LIVE + AJAX Backup READY!');

</script>

<style>
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}
</style>
