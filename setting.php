<?php
include 'db.php';
include 'auth.php'; 
date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

$update_msg = "";
$error_msgs = [];
$user_id = $_SESSION['user_id'];

$user_query = $conn->prepare("SELECT 
    branch_name, 
    email, 
    number, 
    low_stock_enabled,     
    expiry_alert_enabled,   
    push_notif_enabled,
    days_before_expiry, 
    expired_notif_delay, 
    low_stock_threshold, 
    notif_interval_hours, 
    camera_permission, 
    terms_agreed 
    FROM users WHERE user_id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_data = $user_query->get_result()->fetch_assoc();

$low_stock_enabled = $user_data['low_stock_enabled'];
$expiry_enabled = $user_data['expiry_alert_enabled'];
$alert_days = $user_data['days_before_expiry'];
$current_db_email = $user_data['email'];
$current_phone = $user_data['number'];

$_SESSION['branch_name'] = $user_data['branch_name'];
$_SESSION['user_email'] = $user_data['email'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    if (isset($_POST['update_account'])) {
        $new_branch = mysqli_real_escape_string($conn, $_POST['branch_name']);
        $new_email = mysqli_real_escape_string($conn, $_POST['email']);
        $new_phone = mysqli_real_escape_string($conn, $_POST['number']);

        $phone_regex = "/^(\+639|09)\d{9}$/";

        if (!preg_match($phone_regex, $new_phone)) {
            $error_msgs[] = "Invalid PH Number format.";
        } 
        
        elseif ($new_email !== $current_db_email && (!isset($_SESSION['email_verified']) || $_SESSION['email_verified'] !== $new_email)) {
    $session_val = $_SESSION['email_verified'] ?? 'NOT SET';
    $error_msgs[] = "Debug: Input Email: [$new_email] | Session Verified: [$session_val]";
}
        
        else {
            $update_sql = $conn->prepare("UPDATE users SET branch_name = ?, email = ?, number = ? WHERE user_id = ?");
            $update_sql->bind_param("sssi", $new_branch, $new_email, $new_phone, $user_id);
            
            if ($update_sql->execute()) {
                $update_msg = "Account updated successfully!";
                
                $user_data['branch_name'] = $new_branch;
                $user_data['email'] = $new_email;
                $current_phone = $new_phone;
                $_SESSION['branch_name'] = $new_branch;
                $_SESSION['user_email'] = $new_email;
                
                unset($_SESSION['email_verified']); 
            }
        }
    }

    if (isset($_POST['change_password'])) {
    $current_pw = $_POST['current_password'];
    $new_pw = $_POST['new_password'];
    $confirm_pw = $_POST['confirm_password'];
    
    $pw_regex = "/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,16}$/";

    $res = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
    $res->bind_param("i", $user_id);
    $res->execute();
    $user_pass = $res->get_result()->fetch_assoc();

    $is_current_pw_correct = password_verify($current_pw, $user_pass['password']);
    
    $is_new_pw_valid = preg_match($pw_regex, $new_pw);

    if (!$is_current_pw_correct) {
        $error_msgs[] = "Current password is incorrect. And Password must be 8-16 chars, 1 Uppercase, 1 Lowercase, 1 Number, 1 Special Char.";
    }

    if (!$is_new_pw_valid) {
        if ($is_current_pw_correct) { 
             $error_msgs[] = "Password must be 8-16 chars, 1 Uppercase, 1 Lowercase, 1 Number, 1 Special Char.";
        }
    }

    if ($new_pw !== $confirm_pw) {
        $error_msgs[] = "New passwords do not match.";
    }

    if (empty($error_msgs)) {
        $hashed_pw = password_hash($new_pw, PASSWORD_DEFAULT);
        $update_pw = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $update_pw->bind_param("si", $hashed_pw, $user_id);
        
        if ($update_pw->execute()) {
            $update_msg = "Password changed successfully!";
        } else {
            $error_msgs[] = "Current password is incorrect";
        }
    }
}
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>FoodSave - Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/settings.css">
    <style>

    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>
    <main>
        <div class="header-card">
            <h2>Settings</h2>
            <p>Manage your account and preferences</p>
        </div>

        <div class="settings-container">
            <div class="settings-tabs">
                <button class="tab-btn active" id="btn-profile" onclick="showTab('profile', this, true)">
                    <i class="fas fa-user"></i> Profile
                </button>

                <button class="tab-btn" id="btn-account" onclick="showTab('account', this, true)">
                    <i class="fas fa-user-shield"></i> Account Center
                </button>

                <button class="tab-btn" onclick="showTab('product-settings', this, true)">
                    <i class="fas fa-boxes"></i> Product Details
                </button>

                <button class="tab-btn" onclick="showTab('notif', this, true)">
                    <i class="fas fa-bell"></i> Notifications
                </button>

                <button class="tab-btn" onclick="showTab('privacy', this, true)">
                    <i class="fas fa-shield-alt"></i> Permissions
                </button>

                <button class="tab-btn" onclick="showTab('about', this, true)">
                    <i class="fas fa-info-circle"></i> About Us
                </button>

                <button class="tab-btn" onclick="showTab('features', this, true)">
                    <i class="fas fa-microchip"></i> System Features
                </button>

                <button class="tab-btn" onclick="confirmLogout()"
                    style="color: #ef4444; margin-top: 20px; width: 100%; text-align: left;">
                    <i class="fas fa-sign-out-alt"></i> Sign Out
                </button>

            </div>

            <div class="settings-content">
                <div id="profile" class="content-pane active">
                    <h3 style="margin-bottom: 25px;">Public Profile</h3>
                    <div class="settings-group"><label>BRANCH NAME</label><input type="text"
                            value="<?= htmlspecialchars($user_data['branch_name']) ?>" disabled></div>
                    <div class="settings-group"><label>EMAIL ADDRESS</label><input type="email"
                            value="<?= htmlspecialchars($user_data['email']) ?>" disabled></div>
                    <div class="settings-group"><label>PHONE NUMBER</label><input type="text"
                            value="<?= htmlspecialchars($current_phone) ?>" disabled></div>
                </div>

                <div id="account" class="content-pane">
                    <h3 style="margin-bottom: 25px;">Account Center</h3>

                    <?php if ($update_msg): ?>
                    <div class="alert alert-success alert-msg">
                        <i class="fas fa-check-circle"></i> <?= $update_msg ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($error_msgs)): ?>
                    <div class="alert alert-error alert-msg">
                        <ul style="margin:0; padding-left:15px;">
                            <?php foreach($error_msgs as $err) echo "<li>$err</li>"; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div class="account-section">
                        <h4 style="margin-bottom: 15px; color: var(--text-dark);">Personal Information</h4>
                        <form id="accountForm" method="POST">
                            <div class="settings-group">
                                <label>BRANCH NAME</label>
                                <input type="text" name="branch_name"
                                    value="<?= htmlspecialchars($user_data['branch_name']) ?>" required>
                            </div>
                            <div class="settings-group">
                                <label>EMAIL ADDRESS</label>
                                <div style="display:flex; gap:10px;">
                                    <input type="email" id="update_email" name="email"
                                        value="<?= htmlspecialchars($user_data['email']) ?>" required>
                                    <button type="button" onclick="openOTPModal(this)"
                                        style="background:#d1dbcd; color:#5a6e54; border:none; padding:0 15px; border-radius:10px; cursor:pointer; font-weight:700; font-size:0.75rem;">SEND
                                        CODE</button>
                                </div>
                            </div>
                            <div class="settings-group">
                                <label>PHONE NUMBER</label>
                                <input type="text" name="number" value="<?= htmlspecialchars($current_phone) ?>"
                                    required>
                            </div>

                            <button type="button" onclick="handleUpdateInfo()"
                                style="background: var(--primary-green); color:white; padding:12px 25px; border:none; border-radius:10px; cursor:pointer; font-weight:600;">
                                Update Info
                            </button>

                            <input type="hidden" name="update_account" value="1">
                        </form>
                    </div>

                    <div class="account-section">
                        <h4 style="margin-bottom: 15px; color: var(--text-dark);">Password & Security</h4>
                        <form method="POST">
                            <div class="settings-group">
                                <label>CURRENT PASSWORD</label>
                                <div class="password-wrapper">
                                    <input type="password" name="current_password" id="curr_pw" required>
                                    <i class="fas fa-eye toggle-password"
                                        onclick="toggleVisibility('curr_pw', this)"></i>
                                </div>
                            </div>

                            <div class="settings-group">
                                <label>NEW PASSWORD</label>
                                <div class="password-wrapper">
                                    <input type="password" name="new_password" id="new_pw" required>
                                    <i class="fas fa-eye toggle-password"
                                        onclick="toggleVisibility('new_pw', this)"></i>
                                </div>
                            </div>

                            <div class="settings-group">
                                <label>CONFIRM NEW PASSWORD</label>
                                <div class="password-wrapper">
                                    <input type="password" name="confirm_password" id="conf_pw" required>
                                    <i class="fas fa-eye toggle-password"
                                        onclick="toggleVisibility('conf_pw', this)"></i>
                                </div>
                            </div>
                            <button type="submit" name="change_password"
                                style="background: #97b287; color:white; padding:12px 25px; border:none; border-radius:10px; cursor:pointer; font-weight:600;">
                                Change Password
                            </button>
                        </form>
                    </div>

                    <div class="danger-zone"
                        style="background: #fff5f5; padding: 25px; border-radius: 15px; border: 1px solid #fee2e2;">
                        <h4 style="color: #ef4444; margin-bottom: 10px;">Account Ownership</h4>
                        <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 20px;">
                            Deleting your account is permanent. All inventory data, statistics, and history will be
                            erased.
                        </p>

                        <form id="realDeleteForm" action="delete-account" method="POST">
                            <button type="button" onclick="finalCheck()"
                                style="background: #ef4444; color: white; border: none; width: 100%; padding: 12px; border-radius: 10px; cursor: pointer; font-weight: 600;">
                                Permanently Delete Account
                            </button>
                            <input type="hidden" name="confirm_delete" value="1">
                        </form>
                    </div>
                </div>

                <div id="product-settings" class="content-pane">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin:0;">Product Masterlist</h3>
                        <button onclick="openMasterModal()"
                            style="background: #8dae84; color:white; border:none; padding:10px 18px; border-radius:10px; cursor:pointer; font-weight:700; font-size:0.85rem;">
                            <i class="fas fa-plus"></i> Add New Product
                        </button>
                    </div>

                    <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 20px;">
                        Products listed here will automatically provide price info during scanning.
                    </p>

                    <div style="display: flex; gap: 15px; margin-bottom: 20px; align-items: center;">
                        <div style="flex: 1; position: relative; display: flex; align-items: center;">
                            <i class="fas fa-search" style="position: absolute; left: 15px; color: #94a3b8;"></i>
                            <input type="text" id="masterSearch" onkeyup="filterMasterlist()"
                                placeholder="Search product name..."
                                style="width: 100%; padding: 10px 10px 10px 40px; border-radius: 10px; border: 1px solid #e2e8f0; font-size: 0.85rem; outline: none; transition: 0.3s;">
                        </div>

                        <div
                            style="display: flex; align-items: center; gap: 8px; background: #f8fafc; padding: 5px 15px; border-radius: 10px; border: 1px solid #e2e8f0;">
                            <label
                                style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Sort
                                By:</label>
                            <select id="masterSort" onchange="filterMasterlist()"
                                style="border: none; background: transparent; font-weight: 600; color: #1e293b; outline: none; cursor: pointer; font-size: 0.85rem; padding: 5px 0;">
                                <option value="name_asc" selected>Product Name (A-Z)</option>
                                <option value="name_desc">Product Name (Z-A)</option>
                                <option value="price_low">Price (Lowest to Highest)</option>
                                <option value="price_high">Price (Highest to Lowest)</option>
                            </select>
                        </div>
                    </div>

                    <div style="overflow-x: auto; background: white; border-radius: 15px; border: 1px solid #f1f5f9;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                            <thead>
                                <tr style="background: #f8fafc; text-align: left;">
                                    <th style="padding: 15px; color: #64748b;">Product Name</th>
                                    <th style="padding: 15px; color: #64748b; text-align: center;">Price</th>
                                    <th style="padding: 15px; color: #64748b; text-align: center;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="masterlist-body">
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-wrapper"
                        style="display: flex; flex-direction: column; align-items: center; gap: 10px; margin-top: 25px; padding-bottom: 20px;">
                        <div id="masterlist-info" class="pagination-info" style="font-size: 0.85rem; color: #64748b;">
                            Showing <b>0</b> to <b>0</b> of <b>0</b> entries
                        </div>

                        <div class="pagination-controls" style="display: flex; align-items: center; gap: 8px;">
                            <button onclick="changePage('first')" id="btn-first" class="page-btn"><i
                                    class="fas fa-angle-double-left"></i></button>
                            <button onclick="changePage('prev')" id="btn-prev" class="page-btn">PREV</button>

                            <div id="master-page-numbers" style="display: flex; gap: 5px;">
                            </div>

                            <button onclick="changePage('next')" id="btn-next" class="page-btn">NEXT</button>
                            <button onclick="changePage('last')" id="btn-last" class="page-btn"><i
                                    class="fas fa-angle-double-right"></i></button>
                        </div>
                    </div>
                </div>

                <div id="masterModal"
                    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
                    <div
                        style="background:white; padding:30px; border-radius:20px; width:400px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
                        <h3 id="modalTitle" style="margin-top:0; color:#1e293b;">Add Product Template</h3>
                        <form id="masterForm">
                            <input type="hidden" id="master_id" name="master_id">

                            <div class="settings-group" style="margin-bottom:15px;">
                                <label style="font-size:0.75rem; font-weight:700; color:#64748b;">PRODUCT NAME</label>
                                <input type="text" id="m_name" name="product_name" required
                                    style="width:100%; padding:10px; border-radius:8px; border:1px solid #e2e8f0;">
                            </div>

                            <div class="settings-group" style="margin-bottom:20px;">
                                <label style="font-size:0.75rem; font-weight:700; color:#64748b;">PRICE (₱)</label>
                                <input type="number" step="0.01" id="m_price" name="price" required
                                    style="width:100%; padding:10px; border-radius:8px; border:1px solid #e2e8f0;">
                            </div>

                            <div style="display:flex; gap:10px;">
                                <button type="button" onclick="closeMasterModal()"
                                    style="flex:1; padding:12px; border-radius:10px; border:1px solid #e2e8f0; background:none; cursor:pointer; font-weight:600;">Cancel</button>
                                <button type="submit"
                                    style="flex:1; padding:12px; border-radius:10px; border:none; background:#8dae84; color:white; font-weight:700; cursor:pointer;">Save
                                    Product</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="notif" class="content-pane">
                    <h3 style="margin-bottom: 25px;">Notification Settings</h3>

                    <div id="notifToast"
                        style="display:none; padding:12px; background:#dcfce7; color:#166534; border-radius:10px; margin-bottom:15px; font-size:0.85rem; font-weight:600; opacity:0;">
                        <i class="fas fa-check-circle"></i> Notification preferences updated successfully!
                    </div>

                    <div class="toggle-row">
                        <div>
                            <strong>Low Stock Alerts</strong>
                            <p style="font-size:0.8rem; color:#64748b;">Receive alerts for low-stock products.</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="emailToggle"
                                <?php echo ($user_data['low_stock_enabled'] == 1) ? 'checked' : ''; ?>> <span
                                class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-row" style="border-bottom:none;">
                        <div>
                            <strong>Expiration Alerts</strong>
                            <p style="font-size:0.8rem; color:#64748b;">Get notified for near expiry and expired
                                products.</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="expiryToggle"
                                <?php echo ($user_data['expiry_alert_enabled'] == 1) ? 'checked' : ''; ?>> <span
                                class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-row">
                        <div>
                            <strong>Push Notifications</strong>
                            <p style="font-size:0.8rem; color:#64748b;">Receive repeated alerts.</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="pushToggle"
                                <?php echo ($user_data['push_notif_enabled'] == 1) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div id="allSettingsContainer"
                        style="margin-top: 10px; background: #f8fafc; padding: 25px; border-radius: 15px; border: 1px solid #f1f5f9; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">

                        <div id="expirationSettingsGroup"
                            style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; grid-column: span 2;">
                            <div>
                                <label
                                    style="font-weight: 600; color: #334155; display: block; margin-bottom: 8px;">Alert
                                    Before Expiry (Soon):</label>
                                <select id="alertDays"
                                    style="padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0; width: 100%; font-weight: 600;">
                                    <option value="1"
                                        <?php echo ($user_data['days_before_expiry'] == 1) ? 'selected' : ''; ?>>1 Day
                                        Before</option>
                                    <option value="2"
                                        <?php echo ($user_data['days_before_expiry'] == 2) ? 'selected' : ''; ?>>2 Days
                                        Before</option>
                                    <option value="3"
                                        <?php echo ($user_data['days_before_expiry'] == 3) ? 'selected' : ''; ?>>3 Days
                                        Before</option>

                                </select>
                            </div>

                            <div>
                                <label
                                    style="font-weight: 600; color: #334155; display: block; margin-bottom: 8px;">Expired
                                    Product Alert:</label>
                                <select id="expiredDelay"
                                    style="padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0; width: 100%; font-weight: 600;">
                                    <option value="0"
                                        <?php echo ($user_data['expired_notif_delay'] == 0) ? 'selected' : ''; ?>>
                                        Immediately (0 hrs)</option>
                                    <option value="12"
                                        <?php echo ($user_data['expired_notif_delay'] == 12) ? 'selected' : ''; ?>>After
                                        12
                                        Hours</option>
                                    <option value="20"
                                        <?php echo ($user_data['expired_notif_delay'] == 20) ? 'selected' : ''; ?>>After
                                        20
                                        Hours</option>
                                    <option value="24"
                                        <?php echo ($user_data['expired_notif_delay'] == 24) ? 'selected' : ''; ?>>After
                                        24
                                        Hours</option>
                                </select>
                            </div>
                        </div>

                        <div id="lowStockSettingsGroup" style="grid-column: span 1;">
                            <label style="font-weight: 600; color: #334155; display: block; margin-bottom: 8px;">Low
                                Stock Limit:</label>
                            <select id="stockThreshold"
                                style="padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0; width: 100%; font-weight: 600;">
                                <option value="2"
                                    <?php echo ($user_data['low_stock_threshold'] == 2) ? 'selected' : ''; ?>>2 Items
                                    left</option>
                                <option value="3"
                                    <?php echo ($user_data['low_stock_threshold'] == 3) ? 'selected' : ''; ?>>3 Items
                                    left</option>
                                <option value="5"
                                    <?php echo ($user_data['low_stock_threshold'] == 5) ? 'selected' : ''; ?>>5 Items
                                    left</option>
                            </select>
                        </div>

                        <div>
                            <label style="font-weight: 600; color: #334155; display: block; margin-bottom: 8px;">Repeat
                                Alert:</label>
                            <select id="notifInterval"
                                style="padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0; width: 100%; font-weight: 600;">
                                <option value="4"
                                    <?php echo ($user_data['notif_interval_hours'] == 4) ? 'selected' : ''; ?>>Every 4
                                    Hours</option>
                                <option value="8"
                                    <?php echo ($user_data['notif_interval_hours'] == 8) ? 'selected' : ''; ?>>Every 8
                                    Hours</option>
                                <option value="12"
                                    <?php echo ($user_data['notif_interval_hours'] == 12) ? 'selected' : ''; ?>>Every 12
                                    Hours</option>
                                <option value="20"
                                    <?php echo ($user_data['notif_interval_hours'] == 20) ? 'selected' : ''; ?>>Every 20
                                    Hours</option>
                                <option value="24"
                                    <?php echo ($user_data['notif_interval_hours'] == 24) ? 'selected' : ''; ?>>Every 24
                                    Hours</option>
                            </select>
                        </div>

                        <button type="button" onclick="updateNotifSettings()"
                            style="grid-column: span 2; background: #8dae84; color: white; border: none; padding: 12px; border-radius: 10px; cursor: pointer; font-weight: 700;">
                            Save All Preferences
                        </button>
                    </div>
                </div>

                <div id="about" class="content-pane">
                    <h2 style="margin-bottom: 20px; font-weight: 700; color: #333;">About FoodSave</h2>
                    <div class="about-banner-card" style="color: black;">
                        <h3 style="font-size: 22px; font-weight: 700;">FoodSave</h3><br>
                        <p style="font-size: 15px; line-height: 1.5; max-width: 100%; text-align: justify;">FoodSave is
                            a system developed by Computer Engineering students from PUP Institute of Technology as part
                            of an academic project aimed at addressing the growing issue of food waste in convenience
                            stores and similar businesses.<br><br>
                            The developers created FoodSave to provide a smarter and more efficient way of managing
                            inventory using technologies such as Computer Vision and Optical Character Recognition
                            (OCR). The system helps store employees monitor product expiration dates, receive
                            notifications, and track the cost of wasted food</p>
                    </div>
                    <div class="about-info-card" style="text-align: justify;">
                        <h4 style="margin: 0 0 10px 0;">Our Mission</h4>
                        <p style="font-size: 15px; margin: 0;">The mission of FoodSave is to reduce food waste in
                            convenience stores by bridging the gap between physical inventory and digital monitoring.
                        </p><br>
                        <p style="font-size: 15px;"><b>The core objectives are to:</b></p><br>

                        <p><b>Minimize Spoilage:</b> Ensure that items nearing their expiration are identified in time
                            to be sold, often through automated discounting.</p><br>

                        <p><b>Increase Efficiency:</b> Reduce the labor hours staff spend manually checking labels in
                            fast-paced environments like 7-Eleven.</p><br>

                        <p><b>Promote Sustainability:</b> Support more responsible inventory management to decrease the
                            environmental and financial impact of unsold perishable goods.</p>
                    </div>
                </div>

                <div id="features" class="content-pane">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <h3 style="margin: 0;">System Core Features</h3>
                        <div id="camera-status-bar"
                            style="background: #f1f5f9; padding: 8px 15px; border-radius: 20px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px;">
                            <span id="status-dot"
                                style="height: 10px; width: 10px; background-color: #94a3b8; border-radius: 50%; display: inline-block;"></span>
                            <span id="status-text"
                                style="font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Checking
                                Camera...</span>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="about-info-card" style="border-top: 4px solid #8dae84;">
                            <h4 style="color: var(--text-dark);"><i class="fas fa-camera"></i> Automated Data Capture
                            </h4>
                            <p style="font-size: 0.85rem; color: #64748b; margin-top: 10px;">
                                Utilizes <strong>Raspberry Pi using WebCam</strong> hardware integrated with <strong>Google
                                    Gemini
                                    API/Tesseract OCR</strong> to automatically extract expiration dates from product
                                packaging, eliminating manual entry errors.
                            </p>
                        </div>

                        <div class="about-info-card" style="border-top: 4px solid #3b82f6;">
                            <h4 style="color: var(--text-dark);"><i class="fas fa-sync-alt"></i> Real-time Tracking</h4>
                            <p style="font-size: 0.85rem; color: #64748b; margin-top: 10px;">
                                Continuous monitoring of <strong>Stock Levels</strong> and <strong>Product
                                    Lifecycles</strong>. The system categorizes items into 'Usable', 'Expiring Soon', 'Expiring Today', 'Sold Out',
                                and 'Expired' automatically.
                            </p>
                        </div>

                        <div class="about-info-card" style="border-top: 4px solid #f59e0b;">
                            <h4 style="color: var(--text-dark);"><i class="fas fa-exclamation-triangle"></i> Intelligent
                                Notifications</h4>
                            <p style="font-size: 0.85rem; color: #64748b; margin-top: 10px;">
                                Advanced warning system that sends <strong>Email and Dashboard Alerts</strong> based on
                                user-defined thresholds (e.g., 3 days before expiry), allowing for timely stock rotation
                                or discounting.
                            </p>
                        </div>

                        <div class="about-info-card" style="border-top: 4px solid #ef4444;">
                            <h4 style="color: var(--text-dark);"><i class="fas fa-file-invoice-dollar"></i> Spoilage
                                Analytics</h4>
                            <p style="font-size: 0.85rem; color: #64748b; margin-top: 10px;">
                                Comprehensive visualization of <strong>Financial Losses</strong> due to wasted products.
                                Helps management identify trends in spoilage to optimize future procurement orders.
                            </p>
                        </div>
                    </div>

                    <div class="about-info-card" style="border-top: 4px solid #64748b; grid-column: span 2;">
                        <h4 style="color: var(--text-dark);"><i class="fas fa-broom"></i> Automated Data Housekeeping
                        </h4>
                        <div style="display: flex; gap: 20px; align-items: flex-start; margin-top: 10px;">
                            <p style="font-size: 0.85rem; color: #64748b; margin: 0; flex: 1;">
                                To ensure a clutter-free user interface and maintain peak system performance, inventory
                                records tagged as <strong>Expired</strong> or <strong>Completely Sold</strong> are
                                retained for a 24-hour grace period. This allows users to review recent changes before
                                the system automatically archives the data and removes it from the active monitoring
                                page.
                            </p>
                            <div
                                style="background: #f1f5f9; padding: 10px; border-radius: 12px; border: 1px solid #e2e8f0; text-align: center; min-width: 120px;">
                                <span
                                    style="display: block; font-size: 0.7rem; font-weight: 700; color: #475569;">RETENTION</span>
                                <span style="font-size: 1.1rem; font-weight: 700; color: #1e293b;">24 Hours</span>
                            </div>
                        </div>
                    </div>

                    <div
                        style="margin-top: 25px; background: #f8fafc; padding: 20px; border-radius: 15px; border: 1px solid #e2e8f0;">
                        <h4 style="color: #475569; font-size: 0.9rem;"><i class="fas fa-network-wired"></i> Deployment
                            Status</h4>
                        <div style="display: flex; gap: 30px; margin-top: 12px;">
                            <div style="font-size: 0.8rem; color: #64748b;">
                                <strong>Database:</strong> <span style="color: #22c55e;">MySQL (Online)</span>
                            </div>
                            <div style="font-size: 0.8rem; color: #64748b;">
                                <strong>OCR Engine:</strong> <span style="color: #22c55e;">Active</span>
                            </div>
                            <div style="font-size: 0.8rem; color: #64748b;">
                                <strong>Environment:</strong> 2-Week Field Test
                            </div>
                        </div>
                    </div>
                </div>
                <div id="privacy" class="content-pane">
                    <h3 style="font-size: 22px; font-weight: 700; color: #333; margin-bottom: 20px;">System
                        Permissions
                    </h3>

                    <div id="permToast"
                        style="display:none; padding:12px; background:#dcfce7; color:#166534; border-radius:10px; margin-bottom:15px; font-size:0.85rem; font-weight:600;">
                        <i class="fas fa-check-circle"></i> Permissions updated successfully!
                    </div>

                    <div class="about-info-card"
                        style="margin-bottom: 20px; border-left: 5px solid var(--primary-green);">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h4 style="margin: 0; color: #334155;"><i class="fas fa-camera"></i> Raspberry Pi 3B with
                                    Camera
                                    Integration</h4>
                                <p style="color: #64748b; font-size: 0.85rem; margin-top: 5px;">Allow the website to
                                    process image data via OCR for automated logging.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="camPerm"
                                    <?= ($user_data['camera_permission'] ?? 0) ? 'checked' : '' ?>
                                    onchange="savePermissions()">
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="policy-scroll-box"
                        style="background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; max-height: 300px; overflow-y: auto; font-size: 0.85rem; color: #4b5563; line-height: 1.6;">
                        <h4
                            style="color: #1f2937; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">
                            📄 PRIVACY POLICY</h4>
                        <p>FoodSave respects your privacy and is committed to protecting your personal data. We
                            collect
                            limited information such as email and branch name for account management.</p>
                        <p><strong>Camera Usage:</strong> The system uses a Raspberry Pi with WebCam to scan product
                            labels
                            (OCR). Images are processed in real-time and are <strong>not stored</strong> on our
                            servers.
                        </p>

                        <h4
                            style="color: #1f2937; margin-top: 20px; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">
                            📜 TERMS AND CONDITIONS</h4>
                        <ul style="padding-left: 15px;">
                            <li>System is for food inventory and expiration tracking only.</li>
                            <li>Users are responsible for data accuracy and hardware handling.</li>
                            <li>Camera integration must be used only within the authorized branch.</li>
                            <li>We are not liable for hardware damage or poor OCR results due to low image quality.
                            </li>
                        </ul>

                        <h4
                            style="color: #1f2937; margin-top: 20px; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">
                            🎥 HARDWARE PERMISSIONS</h4>
                        <p>By enabling "Allow Camera", you permit the system to capture images for OCR extraction.
                            We do
                            not store video recordings or continuous feeds.</p>
                    </div>

                    <div class="agreement-box"
                        style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; background: #f0fdf4; padding: 15px; border-radius: 12px;">
                        <label for="termsPerm" style="font-weight: 600; font-size: 0.9rem; color: #166534;">
                            I have read and agree to the Policy & Terms
                        </label>
                        <label class="switch">
                            <input type="checkbox" id="termsPerm"
                                <?= ($user_data['terms_agreed'] ?? 0) ? 'checked' : '' ?> onchange="savePermissions()">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="otpModal" class="otp-modal">
        <div class="modal-card">
            <i class="fas fa-envelope-open-text"
                style="font-size:3rem; color:var(--primary-green); margin-bottom:15px;"></i>
            <h3>OTP SENT TO YOUR EMAIL!</h3>
            <p>Please enter the 6-digit code to verify your account update.</p>

            <div class="otp-inputs">
                <?php for($i=1; $i<=6; $i++): ?>
                <input type="text" class="otp-box" id="otp-<?= $i ?>" maxlength="1" oninput="moveNext(this, <?= $i ?>)">
                <?php endfor; ?>
            </div>

            <div id="timerContainer" style="margin-bottom: 20px; font-size: 0.85rem; color: #666; text-align:center;">
                <span id="timerText"></span>
                <button type="button" id="resendBtn" onclick="openOTPModal(this)"
                    style="display:none; background:none; border:none; color:var(--primary-green); font-weight:700; cursor:pointer; text-decoration:underline; font-size:0.85rem;">
                    Resend OTP
                </button>
            </div>

            <p id="otpError" style="color:#ef4444; font-size:0.8rem; display:none; margin-bottom:15px;">Incorrect OTP
                code. Try again.</p>

            <button type="button" onclick="verifyOTP()"
                style="width:100%; padding:15px; background:var(--primary-green); color:white; border:none; border-radius:12px; font-weight:700; cursor:pointer;">
                VERIFY & UPDATE
            </button>

            <button type="button" onclick="closeModal()"
                style="margin-top:15px; background:none; border:none; color:#999; cursor:pointer;">Cancel</button>
        </div>
    </div>

    <div id="deleteModal" class="otp-modal" style="display:none;">
        <div id="step-verify" class="modal-card" style="border-top: 5px solid #ef4444;">
            <i class="fas fa-user-shield" style="font-size:3rem; color:#ef4444; margin-bottom:15px;"></i>
            <h3 style="color: #1e293b;">Identity Verification</h3>
            <p>To continue, please enter your password to verify ownership of
                <strong><?= htmlspecialchars($user_data['branch_name']) ?></strong>.
            </p>

            <div class="settings-group" style="margin-top: 20px; text-align: left;">
                <label style="font-size: 0.75rem; font-weight: 700; color: #64748b;">YOUR PASSWORD</label>

                <div class="password-wrapper">
                    <input type="password" id="delete_password_input" placeholder="Enter password"
                        style="width:100%; padding:12px; border-radius:10px; border:1px solid #e2e8f0; margin-top:5px;">
                    <i class="fas fa-eye toggle-password" onclick="toggleVisibility('delete_password_input', this)"></i>
                </div>

                <p id="delete_error" style="color:#ef4444; font-size:0.8rem; display:none; margin-top:8px;">
                    <i class="fas fa-times-circle"></i> Incorrect password. Please try again.
                </p>
            </div>

            <button type="button" onclick="verifyPasswordBeforeDelete()" id="btnConfirmPass"
                style="width:100%; padding:15px; background:#ef4444; color:white; border:none; border-radius:12px; font-weight:700; cursor:pointer; margin-top:10px;">
                VERIFY PASSWORD
            </button>

            <button type="button" onclick="closeDeleteModal()"
                style="margin-top:15px; background:none; border:none; color:#999; cursor:pointer; font-weight:600;">
                Keep my account
            </button>
        </div>

        <div id="step-warning" class="modal-card" style="border-top: 5px solid #ef4444; display:none;">
            <i class="fas fa-exclamation-triangle" style="font-size:3rem; color:#ef4444; margin-bottom:15px;"></i>
            <h3 style="color: #1e293b;">Final Warning!</h3>
            <p style="background: #fef2f2; padding: 15px; border-radius: 10px; color: #991b1b; font-weight: 500;">
                This action is irreversible. All inventory data, statistics, and history for
                <b><?= htmlspecialchars($user_data['branch_name']) ?></b> will be permanently erased.
            </p>
            <p style="margin-top: 10px;">Click <b>"CONFIRM DELETE"</b> to proceed with account removal.</p>

            <button type="button" onclick="executeFinalDelete()"
                style="width:100%; padding:15px; background:#ef4444; color:white; border:none; border-radius:12px; font-weight:700; cursor:pointer; margin-top:10px; letter-spacing: 1px;">
                CONFIRM DELETE
            </button>

            <button type="button" onclick="closeDeleteModal()"
                style="margin-top:15px; background:none; border:none; color:#999; cursor:pointer; font-weight:600;">
                Cancel
            </button>
        </div>
    </div>

    <div id="customAlertModal" class="otp-modal" style="display:none;">
        <div class="modal-card" id="alertCard" style="border-top: 5px solid #8dae84;">
            <i id="alertIcon" class="fas fa-info-circle" style="font-size:3rem; color:#8dae84; margin-bottom:15px;"></i>
            <h3 id="alertTitle" style="color: #1e293b;">Notification</h3>
            <p id="alertMessage" style="color: #64748b; line-height: 1.5; margin-bottom: 20px;"></p>

            <button type="button" onclick="closeCustomAlert()"
                style="width:100%; padding:12px; background: #8dae84; color:white; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
                OK
            </button>
        </div>
    </div>

    <div id="confirmModal" class="otp-modal" style="display:none;">
        <div class="modal-card" style="border-top: 5px solid #f59e0b;">
            <i class="fas fa-trash-alt" style="font-size:3rem; color:#f59e0b; margin-bottom:15px;"></i>
            <h3>Are you sure?</h3>
            <p>Do you really want to remove this product from the masterlist?</p>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button id="confirmYes"
                    style="flex:1; padding:12px; background:#ef4444; color:white; border:none; border-radius:10px; cursor:pointer; font-weight:700;">DELETE</button>
                <button onclick="document.getElementById('confirmModal').style.display='none'"
                    style="flex:1; padding:12px; background:#e2e8f0; color:#475569; border:none; border-radius:10px; cursor:pointer; font-weight:700;">CANCEL</button>
            </div>
        </div>
    </div>

    <div id="logoutModal" class="otp-modal" style="display:none;">
        <div class="modal-card" style="border-top: 5px solid #FFFFFF;">
            <i class="fas fa-sign-out-alt" style="font-size:3rem; color:#97b287; margin-bottom:15px;"></i>
            <h3>Logging Out?</h3>
            <p>Are you sure you want to end your session and log out of
                <strong><?= htmlspecialchars($user_data['branch_name']) ?></strong>?
            </p>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <a href="logout.php" style="flex:1; text-decoration:none;">
                    <button type="button"
                        style="width:100%; padding:12px; background:#97b287; color:white; border:none; border-radius:10px; cursor:pointer; font-weight:700;">LOG
                        OUT</button>
                </a>
                <button onclick="closeLogoutModal()"
                    style="flex:1; padding:12px; background:#e2e8f0; color:#475569; border:none; border-radius:10px; cursor:pointer; font-weight:700;">CANCEL</button>
            </div>
        </div>
    </div>

    <script>
    let countdown;
    let isEmailVerified = false;

    document.getElementById('update_email').addEventListener('input', function() {
        isEmailVerified = false;
    });

    function showTab(tabId, btn, isManual = false) {
        document.querySelectorAll('.content-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));

        const targetPane = document.getElementById(tabId);
        if (targetPane) targetPane.classList.add('active');
        if (btn) btn.classList.add('active');

        if (isManual) {
            document.querySelectorAll('.alert-msg').forEach(msg => msg.remove());
            document.querySelectorAll('.settings-content form').forEach(form => {
                form.reset();
            });
            const otpError = document.getElementById('otpError');
            if (otpError) otpError.style.display = 'none';
        }

        sessionStorage.setItem('settingsActiveTab', tabId);
    }

    function startResendTimer() {
        let seconds = 60;
        const timerText = document.getElementById('timerText');
        const resendBtn = document.getElementById('resendBtn');

        resendBtn.style.display = "none";
        timerText.style.display = "inline";

        clearInterval(countdown);
        countdown = setInterval(() => {
            seconds--;
            timerText.innerText = "Resend code in " + seconds + "s";

            if (seconds <= 0) {
                clearInterval(countdown);
                timerText.style.display = "none";
                resendBtn.style.display = "inline";
            }
        }, 1000);
    }

    function openOTPModal(btn) {
        const emailInput = document.getElementById('update_email');
        const emailValue = emailInput.value;

        if (!btn) return;

        if (!emailValue || !emailInput.checkValidity()) {
            showAlert("Please enter a valid email address first.", "error");
            return;
        }

        const originalText = btn.innerText;
        btn.innerText = (btn.id === 'resendBtn') ? "RESENDING..." : "SENDING...";
        btn.disabled = true;

        fetch('send_otp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    'email': emailValue,
                    'type': 'change_email'
                })
            })

            .then(res => res.text())
            .then(data => {
                const isExisting = data.includes("already linked");
                const isSameEmail = data.includes("same as your current");
                const isError = data.includes("Error") || data.includes("required");

                if (isExisting || isSameEmail) {
                    Showalert(data, "The email is still the same; there are no changes.");
                } else if (isError) {
                    showAlert(data, "error");
                } else {
                    showAlert(data, "success");
                    document.getElementById('otpModal').style.display = 'flex';
                    startResendTimer();
                }

                btn.innerText = originalText;
                btn.disabled = false;
            })
            .catch(err => {
                console.error(err);
                showAlert("The email is still the same; there are no changes.", "error");
                btn.innerText = originalText;
                btn.disabled = false;
            });
    }

    function closeModal() {
        document.getElementById('otpModal').style.display = 'none';
    }

    function moveNext(curr, i) {
        if (curr.value.length === 1 && i < 6) {
            const next = document.getElementById('otp-' + (i + 1));
            if (next) next.focus();
        }
    }

    function verifyOTP() {
        let code = "";
        const boxes = [];
        for (let i = 1; i <= 6; i++) {
            const box = document.getElementById('otp-' + i);
            boxes.push(box);
            code += box.value;
        }

        fetch('verify_otp_ajax', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'otp=' + encodeURIComponent(code) + '&email=' + encodeURIComponent(document.getElementById(
                    'update_email').value)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    isEmailVerified = true;
                    showAlert("Email verified!", "success");
                    closeModal();
                } else {
                    boxes.forEach((box, index) => {
                        setTimeout(() => {
                            box.classList.add('invalid');
                        }, index * 70);
                    });

                    setTimeout(() => {
                        boxes.forEach(box => {
                            box.classList.remove('invalid');
                            box.value = "";
                        });
                        document.getElementById('otpError').style.display = 'block';
                        boxes[0].focus();
                    }, 1000);
                }
            });
    }

    function handleUpdateInfo() {
        const emailInput = document.getElementById('update_email');
        const originalEmail = "<?= $user_data['email'] ?>";

        if (emailInput.value !== originalEmail && !isEmailVerified) {
            showAlert("You changed your email. Please verify it first using 'SEND CODE'.", "warning");
            return;
        }
        document.getElementById('accountForm').submit();
    }

    function startAlertTimer() {
        const alerts = document.querySelectorAll('.alert-msg');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.transition = "opacity 0.5s ease";
                alert.style.opacity = "0";
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });
    }

    window.onload = function() {
        startAlertTimer();

        const hasMessage = <?= ($update_msg || !empty($error_msgs)) ? 'true' : 'false' ?>;
        const activeTab = sessionStorage.getItem('settingsActiveTab');

        const isRefresh = performance.navigation.type === 1;
        const isFromSettings = document.referrer.includes('settings');

        if (hasMessage) {
            showTab('account', document.getElementById('btn-account'));
        } else if (isRefresh && activeTab) {
            showTab(activeTab, document.querySelector(`[onclick*="${activeTab}"]`));
        } else {
            showTab('profile', document.getElementById('btn-profile'));
        }
    };

    function finalCheck() {
        const btn = document.getElementById('btnConfirmPass');
        btn.disabled = false;
        btn.innerText = "VERIFY PASSWORD";

        document.getElementById('step-verify').style.display = 'block';
        document.getElementById('step-warning').style.display = 'none';

        document.getElementById('delete_password_input').value = '';
        document.getElementById('delete_error').style.display = 'none';

        document.getElementById('deleteModal').style.display = 'flex';

        return false;
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';

        const btn = document.getElementById('btnConfirmPass');
        btn.disabled = false;
        btn.innerText = "VERIFY PASSWORD";
    }

    function verifyPasswordBeforeDelete() {
        const password = document.getElementById('delete_password_input').value;
        const btn = document.getElementById('btnConfirmPass');
        const errorMsg = document.getElementById('delete_error');

        if (!password) {
            alert("Please enter your password.");
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> VERIFYING...';

        const formData = new URLSearchParams();
        formData.append('verify_password', '1');
        formData.append('password', password);

        fetch('update_settings_ajax', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('step-verify').style.display = 'none';
                    document.getElementById('step-warning').style.display = 'block';
                } else {
                    errorMsg.style.display = 'block';
                    btn.disabled = false;
                    btn.innerText = "VERIFY PASSWORD";
                }
            })
            .catch(err => {
                console.error(err);
                showAlert("Connection error. Please try again.", "error");

                const btn = document.getElementById('btnConfirmPass');
                btn.disabled = false;
                btn.innerText = "VERIFY PASSWORD";
            });
    }

    function executeFinalDelete() {
        const deleteForm = document.getElementById('realDeleteForm');
        if (deleteForm) {
            const finalBtn = event.target;
            if (finalBtn) finalBtn.disabled = true;

            console.log("Submitting to route: " + deleteForm.action);
            deleteForm.submit();
        } else {
            alert("System Error: Delete form not found.");
        }
    }

    document.getElementById('cameraToggle')?.addEventListener('click', function() {
        const isEnabled = this.classList.contains('on');
        if (isEnabled) {
            if (confirm("Disconnecting the camera will disable automated scanning. Proceed?")) {
                this.classList.replace('fa-toggle-on', 'fa-toggle-off');
                this.classList.remove('on');
                console.log("RPi Camera Disconnected");
            }
        } else {
            this.classList.replace('fa-toggle-off', 'fa-toggle-on');
            this.classList.add('on');
            alert("Raspberry Pi Camera connected successfully!");
            console.log("RPi Camera Connected");
        }
    });

    document.getElementById('agreeCheckbox')?.addEventListener('change', function() {
        if (this.checked) {
            console.log("User agreed to Terms and Privacy.");
            alert("Thank you for agreeing to FoodSave's Privacy Policy and Agreement!");
        }
    });

    document.querySelectorAll('.toggle-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            this.classList.toggle('on');
            if (this.classList.contains('on')) {
                this.classList.replace('fa-toggle-off', 'fa-toggle-on');
            } else {
                this.classList.replace('fa-toggle-on', 'fa-toggle-off');
            }
        });
    });

    function savePermissions() {
        const camStatus = document.getElementById('camPerm').checked ? 1 : 0;
        const termsStatus = document.getElementById('termsPerm').checked ? 1 : 0;
        const toast = document.getElementById('permToast');

        fetch('update_settings_ajax', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `camera_permission=${camStatus}&terms_agreed=${termsStatus}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    toast.style.display = 'block';
                    setTimeout(() => {
                        toast.style.display = 'none';
                    }, 3000);
                }
            })
            .catch(err => console.error('Error saving permissions:', err));
    }

    function updateNotifSettings() {
        const lowStockOn = document.getElementById('emailToggle').checked ? 1 : 0;
        const expiryOn = document.getElementById('expiryToggle').checked ? 1 : 0;
        const pushOn = document.getElementById('pushToggle').checked ? 1 : 0;

        const days = document.getElementById('alertDays').value;
        const expiredDelay = document.getElementById('expiredDelay').value;
        const threshold = document.getElementById('stockThreshold').value;
        const interval = document.getElementById('notifInterval').value;

        const toast = document.getElementById('notifToast');

        const formData = new URLSearchParams();
        formData.append('update_notif_ajax', '1');

        formData.append('low_stock_on', lowStockOn);
        formData.append('expiry_on', expiryOn);
        formData.append('push_on', pushOn);
        formData.append('days', days);
        formData.append('expired_delay', expiredDelay);
        formData.append('threshold', threshold);
        formData.append('interval', interval);

        fetch('update_settings_ajax', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    toast.style.display = 'block';
                    toast.style.opacity = '1';

                    setTimeout(() => {
                        toast.style.opacity = '0';
                        setTimeout(() => {
                            toast.style.display = 'none';
                        }, 500);
                    }, 3000);
                } else {
                    console.error("Update failed:", data.error || "Unknown error");
                    alert("Failed to save settings. Please try again.");
                }
            })
            .catch(err => {
                console.error("Fetch error:", err);
                alert("Connection error. Check your database or network.");
            });
    }

    document.getElementById('pushToggle').addEventListener('change', updateNotifSettings);

    let checkCount = 0;
    const maxAttempts = 2;

    function updateCameraStatus() {
        const dot = document.getElementById('status-dot');
        const text = document.getElementById('status-text');
        const bar = document.getElementById('camera-status-bar');

        fetch('php/check_camera_status')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'connected') {
                    checkCount = 0;
                    dot.style.backgroundColor = '#22c55e';
                    dot.style.boxShadow = '0 0 8px #22c55e';
                    dot.style.animation = 'pulse 2s infinite';

                    text.innerText = 'Camera: Online';

                    text.style.color = '#166534';
                    bar.style.backgroundColor = '#f0fdf4';
                    bar.style.borderColor = '#dcfce7';
                } else {
                    handleOfflineState(dot, text, bar);
                }
            })
            .catch(err => {
                console.error('Status Check Error:', err);
                handleOfflineState(dot, text, bar);
            });
    }

    if (!document.getElementById('pulse-css')) {
        const style = document.createElement('style');
        style.id = 'pulse-css';
        style.innerHTML = `
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
    `;
        document.head.appendChild(style);
    }

    function handleOfflineState(dot, text, bar) {
        checkCount++;

        if (checkCount >= maxAttempts) {
            dot.style.backgroundColor = '#ef4444';
            dot.style.boxShadow = 'none';
            dot.style.animation = 'none';

            text.innerText = 'Camera: Offline';

            text.style.color = '#991b1b';
            bar.style.backgroundColor = '#fef2f2';
            bar.style.borderColor = '#fee2e2';
        } else {
            text.innerText = 'Searching for Hardware...';
        }
    }

    setInterval(updateCameraStatus, 5000);
    updateCameraStatus();

    function loadMasterlist() {
        const tbody = document.getElementById('masterlist-body');
        tbody.innerHTML =
            '<tr><td colspan="3" style="text-align:center; padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';

        fetch('php/api_masterlist.php?action=fetch')
            .then(res => res.json())
            .then(data => {
                tbody.innerHTML = '';
                if (!data || data.length === 0) {
                    tbody.innerHTML =
                        '<tr><td colspan="3" style="text-align:center; padding:40px; color:#64748b;">No products found. <br><small>Add your first product above!</small></td></tr>';
                } else {
                    data.forEach(item => {
                        const row = document.createElement('tr');
                        row.style.borderBottom = '1px solid #f8fafc';
                        row.innerHTML = `
                    <td style="padding:15px;"><strong>${escapeHtml(item.product_name)}</strong></td>
                    <td style="padding:15px; font-weight:700; text-align: center;">₱${parseFloat(item.price || 0).toFixed(2)}</td>
                    <td style="padding:15px; text-align:center;">
                        <button type="button" onclick='editMaster(${JSON.stringify(item).replace(/'/g, "\\'")})' 
                                style="color:#3b82f6; background:none; border:none; cursor:pointer; margin-right:12px; padding:5px;" 
                                title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" onclick="deleteMaster(${item.master_id})" 
                                style="color:#ef4444; background:none; border:none; cursor:pointer; padding:5px;" 
                                title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>`;
                        tbody.appendChild(row);
                    });
                }
                filterMasterlist();
            })
            .catch(err => {
                console.error('Load error:', err);
                tbody.innerHTML =
                    '<tr><td colspan="3" style="text-align:center; padding:40px; color:#ef4444;">Error loading products. Please refresh.</td></tr>';
            });
    }

    // HELPER FUNCTION
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    function openMasterModal() {
        document.getElementById('masterForm').reset();
        document.getElementById('master_id').value = '';
        document.getElementById('modalTitle').innerText = 'Add Product Template';
        document.getElementById('masterModal').style.display = 'flex';
    }

    function closeMasterModal() {
        document.getElementById('masterModal').style.display = 'none';
    }

    function editMaster(item) {
        document.getElementById('master_id').value = item.master_id || '';
        document.getElementById('m_name').value = item.product_name || '';
        document.getElementById('m_price').value = parseFloat(item.price || 0).toFixed(2);
        document.getElementById('modalTitle').innerText = 'Edit Product Details';
        document.getElementById('masterModal').style.display = 'flex';

        document.querySelector('#masterModal .settings-group').scrollIntoView({
            behavior: 'smooth'
        });
    }

    document.getElementById('masterForm').onsubmit = function(e) {
        e.preventDefault();

        const masterId = document.getElementById('master_id').value.trim();
        const nameVal = document.getElementById('m_name').value.trim();
        const priceVal = document.getElementById('m_price').value;

        if (!nameVal) {
            alert("❌ Product name is required!");
            return;
        }

        const submitBtn = this.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerText = 'SAVING...';

        const params = new URLSearchParams();
        params.append('action', 'save');
        params.append('master_id', masterId);
        params.append('product_name', nameVal);
        params.append('price', priceVal);

        fetch('php/api_masterlist', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params.toString()
            })
            .then(res => res.text())
            .then(data => {
                if (data.trim() === 'success') {
                    closeMasterModal();
                    loadMasterlist();
                    showAlert(masterId ? 'Updated successfully!' : 'Added successfully!', 'success');
                } else {
                    showAlert("Server Error: " + data, "error");
                }
            })
            .catch(err => alert("❌ Connection Error: " + err))
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Save Product';
            });
    };

    function saveProduct() {
        console.log("Saving product...");
    }

    function deleteMaster(id) {
        const modal = document.getElementById('confirmModal');
        modal.style.display = 'flex';

        document.getElementById('confirmYes').onclick = function() {
            this.disabled = true;
            this.innerText = "DELETING...";

            fetch('php/api_masterlist.php?action=delete&id=' + id)
                .then(res => res.text())
                .then(data => {
                    modal.style.display = 'none';
                    if (data.trim() === 'success') {
                        loadMasterlist();
                        showAlert("Product removed successfully.", "success");
                    } else {
                        showAlert("Failed to delete product.", "error");
                    }
                })
                .catch(err => {
                    modal.style.display = 'none';
                    showAlert("Connection error.", "error");
                })
                .finally(() => {
                    this.disabled = false;
                    this.innerText = "DELETE";
                });
        };
    }

    const oldShowTab = showTab;
    showTab = function(id, btn, save) {
        oldShowTab(id, btn, save);
        if (id === 'product-settings') {
            if (typeof loadMasterlist === "function") loadMasterlist();
        }
    }


    let currentPage = 1;
    const rowsPerPage = 5;
    let filteredRows = [];

    function filterMasterlist() {
        const searchTerm = document.getElementById('masterSearch').value.toLowerCase();
        const sortValue = document.getElementById('masterSort').value;
        const tbody = document.getElementById('masterlist-body');

        const allRows = Array.from(tbody.querySelectorAll('tr'));

        filteredRows = allRows.filter(row => {
            const productName = row.cells[0].textContent.toLowerCase();
            return productName.includes(searchTerm);
        });

        sortData(sortValue);

        currentPage = 1;
        renderTable();
    }

    function sortData(sortValue) {
        filteredRows.sort((a, b) => {
            let valA, valB;
            switch (sortValue) {
                case 'name_asc':
                    return a.cells[0].textContent.localeCompare(b.cells[0].textContent);
                case 'name_desc':
                    return b.cells[0].textContent.localeCompare(a.cells[0].textContent);
                case 'price_low':
                    valA = parseFloat(a.cells[1].textContent.replace(/[^\d.-]/g, ''));
                    valB = parseFloat(b.cells[1].textContent.replace(/[^\d.-]/g, ''));
                    return valA - valB;
                case 'price_high':
                    valA = parseFloat(a.cells[1].textContent.replace(/[^\d.-]/g, ''));
                    valB = parseFloat(b.cells[1].textContent.replace(/[^\d.-]/g, ''));
                    return valB - valA;
                default:
                    return 0;
            }
        });
    }

    function renderTable() {
        const tbody = document.getElementById('masterlist-body');
        const allRowsInTbody = Array.from(tbody.querySelectorAll('tr'));

        allRowsInTbody.forEach(row => row.style.display = 'none');

        const totalRows = filteredRows.length;
        const totalPages = Math.ceil(totalRows / rowsPerPage);

        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;

        const paginatedRows = filteredRows.slice(start, end);

        paginatedRows.forEach(row => {
            row.style.display = '';
            tbody.appendChild(row);
        });

        const infoText = document.getElementById('masterlist-info');
        if (infoText) {
            infoText.innerHTML =
                `Showing <b>${totalRows > 0 ? start + 1 : 0}</b> to <b>${Math.min(end, totalRows)}</b> of <b>${totalRows}</b> entries`;
        }

        renderPaginationControls(totalPages);
    }

    function renderPaginationControls(totalPages) {
        const container = document.getElementById('master-page-numbers');
        if (!container) return;

        container.innerHTML = '';

        if (totalPages <= 1) {
            if (totalPages === 1) {
                const btn = document.createElement('button');
                btn.innerText = '1';
                btn.className = "num-btn active";
                container.appendChild(btn);
            }
        } else {
            for (let i = 1; i <= totalPages; i++) {
                const btn = document.createElement('button');
                btn.innerText = i;
                btn.className = `num-btn ${i === currentPage ? 'active' : ''}`;
                btn.onclick = () => {
                    currentPage = i;
                    renderTable();
                };
                container.appendChild(btn);
            }
        }

        document.getElementById('btn-first').disabled = (currentPage === 1);
        document.getElementById('btn-prev').disabled = (currentPage === 1);
        document.getElementById('btn-next').disabled = (currentPage === totalPages || totalPages === 0);
        document.getElementById('btn-last').disabled = (currentPage === totalPages || totalPages === 0);

        document.getElementById('btn-first').classList.toggle('disabled', currentPage === 1);
        document.getElementById('btn-prev').classList.toggle('disabled', currentPage === 1);
        document.getElementById('btn-next').classList.toggle('disabled', currentPage === totalPages || totalPages ===
            0);
        document.getElementById('btn-last').classList.toggle('disabled', currentPage === totalPages || totalPages ===
            0);
    }

    function changePage(action) {
        const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
        if (action === 'first') currentPage = 1;
        else if (action === 'prev' && currentPage > 1) currentPage--;
        else if (action === 'next' && currentPage < totalPages) currentPage++;
        else if (action === 'last') currentPage = totalPages;
        renderTable();
    }

    document.addEventListener('DOMContentLoaded', filterMasterlist);

    window.addEventListener('load', () => {
        setTimeout(() => {
            filterMasterlist();
        }, 10);
    });

    function updateUIVisibility() {
        const lowStockOn = document.getElementById('emailToggle').checked;
        const expiryOn = document.getElementById('expiryToggle').checked;
        const pushToggle = document.getElementById('pushToggle');
        const pushOn = pushToggle.checked;

        const lowStockGroup = document.getElementById('lowStockSettingsGroup');
        const expiryGroup = document.getElementById('expirationSettingsGroup');
        const allSettingsContainer = document.getElementById('allSettingsContainer');

        const intervalGroup = document.getElementById('notifInterval').parentElement;

        if (!lowStockOn && !expiryOn) {
            pushToggle.checked = false;
            pushToggle.disabled = true;
            pushToggle.parentElement.style.opacity = "0.5";
            pushToggle.parentElement.style.cursor = "not-allowed";
        } else {
            pushToggle.disabled = false;
            pushToggle.parentElement.style.opacity = "1";
            pushToggle.parentElement.style.cursor = "pointer";
        }


        if (pushToggle.checked) {
            intervalGroup.style.display = 'block';
        } else {
            intervalGroup.style.display = 'none';
        }

        if (lowStockGroup) lowStockGroup.style.display = lowStockOn ? 'block' : 'none';
        if (expiryGroup) expiryGroup.style.display = expiryOn ? 'grid' : 'none';

        if (allSettingsContainer) {
            if (!lowStockOn && !expiryOn && !pushToggle.checked) {
                allSettingsContainer.style.display = 'none';
            } else {
                allSettingsContainer.style.display = 'grid';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const emailToggle = document.getElementById('emailToggle');
        const expiryToggle = document.getElementById('expiryToggle');
        const pushToggle = document.getElementById('pushToggle');

        if (emailToggle && expiryToggle && pushToggle) {
            updateUIVisibility();

            emailToggle.addEventListener('change', function() {
                updateUIVisibility();
                updateNotifSettings();
            });

            expiryToggle.addEventListener('change', function() {
                updateUIVisibility();
                updateNotifSettings();
            });

            pushToggle.addEventListener('change', function() {
                updateUIVisibility();
                updateNotifSettings();
            });
        }
    });

    function showAlert(message, type = 'success') {
        const modal = document.getElementById('customAlertModal');
        const card = document.getElementById('alertCard');
        const icon = document.getElementById('alertIcon');
        const title = document.getElementById('alertTitle');
        const msg = document.getElementById('alertMessage');
        const btn = modal.querySelector('button');

        if (type === 'error') {
            card.style.borderTopColor = '#ef4444';
            icon.className = 'fas fa-times-circle';
            icon.style.color = '#ef4444';
            title.innerText = 'Error!';
            btn.style.background = '#ef4444';
        } else if (type === 'warning') {
            card.style.borderTopColor = '#f59e0b';
            icon.className = 'fas fa-exclamation-triangle';
            icon.style.color = '#f59e0b';
            title.innerText = 'Warning';
            btn.style.background = '#f59e0b';
        } else {
            card.style.borderTopColor = '#8dae84';
            icon.className = 'fas fa-check-circle';
            icon.style.color = '#8dae84';
            title.innerText = 'Success';
            btn.style.background = '#8dae84';
        }

        msg.innerText = message;
        modal.style.display = 'flex';
    }

    function closeCustomAlert() {
        document.getElementById('customAlertModal').style.display = 'none';
    }

    function confirmLogout() {
        document.getElementById('logoutModal').style.display = 'flex';
    }

    function closeLogoutModal() {
        document.getElementById('logoutModal').style.display = 'none';
    }

    function toggleVisibility(inputId, icon) {
        const input = document.getElementById(inputId);

        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
        } else {
            input.type = "password";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const passwordInputs = document.querySelectorAll('.password-wrapper input');

        passwordInputs.forEach(input => {
            const updateIcon = () => {
                const icon = input.parentElement.querySelector('.toggle-password');
                if (input.type === 'text') {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            };

            input.addEventListener('input', updateIcon);
            input.addEventListener('change', updateIcon);

            setTimeout(updateIcon, 500);
        });
    });
    </script>

</body>

</html>