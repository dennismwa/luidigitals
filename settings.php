<?php
require_once 'config/database.php';
requireLogin();

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

// Get user settings
$settings = [];
$settingsResult = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE user_id = ?", [$user_id]);
foreach ($settingsResult as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

$darkMode = ($settings['dark_mode'] ?? '1') == '1';
$currency = $settings['currency'] ?? 'KES';

// Get user details
$user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$user_id]);

// Handle form submissions
if ($_POST) {
    if (!validateCSRFToken($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'update_profile') {
                $full_name = sanitizeInput($_POST['full_name']);
                $email = sanitizeInput($_POST['email']);
                $salary = floatval($_POST['salary']);
                
                if (empty($full_name)) {
                    throw new Exception('Full name is required.');
                }
                
                if (!empty($email) && !validateEmail($email)) {
                    throw new Exception('Please enter a valid email address.');
                }
                
                $db->execute(
                    "UPDATE users SET full_name = ?, email = ?, salary = ?, updated_at = NOW() WHERE id = ?",
                    [$full_name, $email, $salary, $user_id]
                );
                
                $_SESSION['full_name'] = $full_name;
                $success = 'Profile updated successfully!';
                
            } elseif ($action === 'change_password') {
                $current_password = $_POST['current_password'];
                $new_password = $_POST['new_password'];
                $confirm_password = $_POST['confirm_password'];
                
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    throw new Exception('All password fields are required.');
                }
                
                if (!password_verify($current_password, $user['password'])) {
                    throw new Exception('Current password is incorrect.');
                }
                
                if ($new_password !== $confirm_password) {
                    throw new Exception('New passwords do not match.');
                }
                
                if (strlen($new_password) < 8) {
                    throw new Exception('New password must be at least 8 characters long.');
                }
                
                $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT, ['cost' => BCRYPT_COST]);
                
                $db->execute(
                    "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?",
                    [$hashedPassword, $user_id]
                );
                
                $success = 'Password changed successfully!';
                
            } elseif ($action === 'update_preferences') {
                $preferences = [
                    'currency' => sanitizeInput($_POST['currency']),
                    'date_format' => sanitizeInput($_POST['date_format']),
                    'notifications_enabled' => isset($_POST['notifications_enabled']) ? '1' : '0',
                    'auto_backup' => isset($_POST['auto_backup']) ? '1' : '0',
                    'dashboard_layout' => sanitizeInput($_POST['dashboard_layout']),
                    'salary_day' => intval($_POST['salary_day']),
                    'low_balance_alert' => floatval($_POST['low_balance_alert']),
                    'high_expense_alert' => floatval($_POST['high_expense_alert'])
                ];
                
                foreach ($preferences as $key => $value) {
                    $db->execute(
                        "INSERT INTO settings (user_id, setting_key, setting_value) 
                         VALUES (?, ?, ?) 
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                        [$user_id, $key, $value]
                    );
                }
                
                $success = 'Preferences updated successfully!';
                
            } elseif ($action === 'export_data') {
                // Redirect to export endpoint
                header('Location: ajax/backup_system.php');
                exit;
                
            } elseif ($action === 'delete_account') {
                $confirm_delete = $_POST['confirm_delete'] ?? '';
                if ($confirm_delete !== 'DELETE') {
                    throw new Exception('Please type DELETE to confirm account deletion.');
                }
                
                // This is a dangerous operation - in real app, you'd want additional verification
                $db->beginTransaction();
                
                // Delete all user data
                $db->execute("DELETE FROM notifications WHERE user_id = ?", [$user_id]);
                $db->execute("DELETE FROM budgets WHERE user_id = ?", [$user_id]);
                $db->execute("DELETE FROM transactions WHERE user_id = ?", [$user_id]);
                $db->execute("DELETE FROM bills WHERE user_id = ?", [$user_id]);
                $db->execute("DELETE FROM wallet_balance WHERE user_id = ?", [$user_id]);
                $db->execute("DELETE FROM settings WHERE user_id = ?", [$user_id]);
                $db->execute("DELETE FROM categories WHERE user_id = ? AND is_default = 0", [$user_id]);
                $db->execute("DELETE FROM users WHERE id = ?", [$user_id]);
                
                $db->commit();
                
                // Destroy session and redirect
                session_destroy();
                header('Location: login.php?deleted=1');
                exit;
            }
            
            // Refresh user data and settings
            $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$user_id]);
            $settings = [];
            $settingsResult = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE user_id = ?", [$user_id]);
            foreach ($settingsResult as $setting) {
                $settings[$setting['setting_key']] = $setting['setting_value'];
            }
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            $error = $e->getMessage();
        }
    }
}

$themeClass = $darkMode ? 'dark' : '';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?= APP_NAME ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="<?= PRIMARY_COLOR ?>">
    
    <!-- PWA Meta Tags -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Luidigitals">
<meta name="mobile-web-app-capable" content="yes">
<meta name="msapplication-TileColor" content="#204cb0">
<meta name="msapplication-tap-highlight" content="no">

<!-- Favicon and Icons -->
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/icon-16.png">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<link rel="mask-icon" href="assets/icon-192.png" color="#204cb0">
    <style>
        .dark { background-color: #0f172a; }
        .dark .bg-white { background-color: #1e293b !important; }
        .dark .text-gray-800 { color: #f1f5f9 !important; }
        .dark .text-gray-600 { color: #cbd5e1 !important; }
        .dark .text-gray-500 { color: #94a3b8 !important; }
        .dark .border-gray-200 { border-color: #334155 !important; }
        .dark .bg-gray-50 { background-color: #0f172a !important; }
        .dark .bg-gray-100 { background-color: #1e293b !important; }
        
        .settings-card { transition: all 0.3s ease; }
        .settings-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
        
        .gradient-primary { background: linear-gradient(135deg, <?= PRIMARY_COLOR ?> 0%, #1e40af 100%); }
        .gradient-success { background: linear-gradient(135deg, <?= SUCCESS_COLOR ?> 0%, #10b981 100%); }
        
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        
        @media (max-width: 768px) {
            .sidebar-mobile {
                transform: translateX(-100%);
            }
            .sidebar-mobile.open {
                transform: translateX(0);
            }
        }
        /* Enhanced dark mode transitions */
.dark-mode-transition {
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
}

/* Fix notification dropdown hover in dark mode */
.dark .hover\:bg-gray-50:hover {
    background-color: #374151 !important;
}

/* Ensure proper contrast for notification text */
.dark .notification-text {
    color: #f1f5f9 !important;
}
    </style>
</head>
<body class="<?= $darkMode ? 'dark bg-gray-900' : 'bg-gray-50' ?> font-sans">
    
    <!-- Mobile Menu Overlay -->
    <div id="mobile-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden md:hidden"></div>
    
    <!-- Sidebar -->
    <aside id="sidebar" class="fixed left-0 top-0 w-64 h-full <?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> shadow-xl z-50 sidebar-transition transform -translate-x-full md:translate-x-0">
        <div class="flex flex-col h-full">
            <div class="flex items-center justify-between p-6 border-b <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 gradient-primary rounded-lg flex items-center justify-center">
                        <i class="fas fa-wallet text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Luidigitals</h1>
                        <p class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">Wallet System</p>
                    </div>
                </div>
                <button id="close-sidebar" class="md:hidden <?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <nav class="flex-1 p-4 space-y-2">
                <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $darkMode ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100' ?> transition-colors">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="transactions.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $darkMode ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100' ?> transition-colors">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Transactions</span>
                </a>
                <a href="bills.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $darkMode ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100' ?> transition-colors">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Bills</span>
                </a>
                <a href="budgets.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $darkMode ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100' ?> transition-colors">
                    <i class="fas fa-chart-pie"></i>
                    <span>Budgets</span>
                </a>
                <a href="savings.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $darkMode ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100' ?> transition-colors">
    <i class="fas fa-piggy-bank"></i>
    <span>Savings</span>
</a>
                <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $darkMode ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100' ?> transition-colors">
                    <i class="fas fa-chart-line"></i>
                    <span>Reports</span>
                </a>
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg gradient-primary text-white">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </nav>
            
            <div class="p-4 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 gradient-success rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-white"></i>
                    </div>
                    <div class="flex-1">
                        <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= $_SESSION['full_name'] ?? $_SESSION['username'] ?></p>
                        <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">Administrator</p>
                    </div>
                    <a href="logout.php" class="<?= $darkMode ? 'text-gray-400 hover:text-red-400' : 'text-gray-500 hover:text-red-500' ?> transition-colors">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </aside>
    
    <!-- Main Content -->
    <main class="md:ml-64 min-h-screen">
        <!-- Header -->
        <header class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> shadow-sm border-b <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
            <div class="flex items-center justify-between px-6 py-4">
                <div class="flex items-center space-x-4">
                    <button id="menu-toggle" class="md:hidden <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> hover:text-blue-600 transition-colors">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Settings</h1>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Manage your account and application preferences</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="toggleDarkMode()" class="<?= $darkMode ? 'text-gray-300 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?> transition-colors" title="Toggle Dark Mode">
                        <i class="fas fa-<?= $darkMode ? 'sun' : 'moon' ?> text-xl"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Alert Messages -->
        <div class="p-6">
            <?php if (isset($error)): ?>
                <div class="bg-red-500 bg-opacity-20 border border-red-400 text-red-100 px-4 py-3 rounded-lg mb-6">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="bg-green-500 bg-opacity-20 border border-green-400 text-green-100 px-4 py-3 rounded-lg mb-6">
                    <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <!-- Settings Tabs -->
            <div class="mb-6">
                <nav class="flex space-x-8">
                    <button onclick="switchTab('profile')" class="tab-btn active px-4 py-2 text-sm font-medium border-b-2 border-blue-600 text-blue-600">
                        <i class="fas fa-user mr-2"></i>Profile
                    </button>
                    <button onclick="switchTab('security')" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent <?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?> hover:border-gray-300">
                        <i class="fas fa-shield-alt mr-2"></i>Security
                    </button>
                    <button onclick="switchTab('preferences')" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent <?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?> hover:border-gray-300">
                        <i class="fas fa-cog mr-2"></i>Preferences
                    </button>
                    <button onclick="switchTab('data')" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent <?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?> hover:border-gray-300">
                        <i class="fas fa-database mr-2"></i>Data & Privacy
                    </button>
                </nav>
            </div>
            
            <!-- Profile Tab -->
            <div id="profile-tab" class="tab-content">
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg settings-card">
                    <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-6">Profile Information</h3>
                    
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Username</label>
                                <input type="text" value="<?= htmlspecialchars($user['username']) ?>" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-gray-400' : 'bg-gray-100 border-gray-300 text-gray-500' ?> border rounded-lg px-3 py-2" disabled>
                                <p class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?> mt-1">Username cannot be changed</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Full Name</label>
                                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Email Address</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Monthly Salary</label>
                                <input type="number" name="salary" value="<?= $user['salary'] ?>" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            </div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition-colors">
                                <i class="fas fa-save mr-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Security Tab -->
            <div id="security-tab" class="tab-content hidden">
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg settings-card">
                    <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-6">Change Password</h3>
                    
                    <form method="POST" class="space-y-6" id="password-form">
                        <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div>
                            <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Current Password</label>
                            <input type="password" name="current_password" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">New Password</label>
                                <input type="password" name="new_password" id="new_password" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                                <p class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?> mt-1">Minimum 8 characters</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confirm_password" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                            </div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg transition-colors">
                                <i class="fas fa-key mr-2"></i>Change Password
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Security Information -->
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg settings-card mt-6">
                    <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Security Information</h3>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-3 border <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?> rounded-lg">
                            <div>
                                <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Last Login</p>
                                <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= formatDate($user['updated_at'], 'M j, Y g:i A') ?></p>
                            </div>
                            <i class="fas fa-check-circle text-green-500"></i>
                        </div>
                        
                        <div class="flex items-center justify-between p-3 border <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?> rounded-lg">
                            <div>
                                <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Account Created</p>
                                <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= formatDate($user['created_at'], 'M j, Y') ?></p>
                            </div>
                            <i class="fas fa-calendar text-blue-500"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Preferences Tab -->
            <div id="preferences-tab" class="tab-content hidden">
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg settings-card">
                    <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-6">Application Preferences</h3>
                    
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="update_preferences">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Currency</label>
                                <select name="currency" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                                    <option value="KES" <?= $currency === 'KES' ? 'selected' : '' ?>>KES - Kenyan Shilling</option>
                                    <option value="USD" <?= $currency === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                                    <option value="EUR" <?= $currency === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                                    <option value="GBP" <?= $currency === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Date Format</label>
                                <select name="date_format" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                                    <option value="Y-m-d" <?= ($settings['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                                    <option value="m/d/Y" <?= ($settings['date_format'] ?? 'Y-m-d') === 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                                    <option value="d/m/Y" <?= ($settings['date_format'] ?? 'Y-m-d') === 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Dashboard Layout</label>
                                <select name="dashboard_layout" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                                    <option value="grid" <?= ($settings['dashboard_layout'] ?? 'grid') === 'grid' ? 'selected' : '' ?>>Grid Layout</option>
                                    <option value="list" <?= ($settings['dashboard_layout'] ?? 'grid') === 'list' ? 'selected' : '' ?>>List Layout</option>
                                    <option value="compact" <?= ($settings['dashboard_layout'] ?? 'grid') === 'compact' ? 'selected' : '' ?>>Compact Layout</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Salary Day (of month)</label>
                                <input type="number" name="salary_day" min="1" max="31" value="<?= $settings['salary_day'] ?? 1 ?>" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Low Balance Alert</label>
                                <input type="number" name="low_balance_alert" step="0.01" value="<?= $settings['low_balance_alert'] ?? 5000 ?>" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">High Expense Alert</label>
                                <input type="number" name="high_expense_alert" step="0.01" value="<?= $settings['high_expense_alert'] ?? 10000 ?>" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <label class="flex items-center">
                                <input type="checkbox" name="notifications_enabled" <?= ($settings['notifications_enabled'] ?? '1') == '1' ? 'checked' : '' ?> class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                <span class="ml-2 text-sm <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Enable Notifications</span>
                            </label>
                            
                            <label class="flex items-center">
                                <input type="checkbox" name="auto_backup" <?= ($settings['auto_backup'] ?? '1') == '1' ? 'checked' : '' ?> class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                <span class="ml-2 text-sm <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Enable Auto Backup</span>
                            </label>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition-colors">
                                <i class="fas fa-save mr-2"></i>Save Preferences
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Data & Privacy Tab -->
            <div id="data-tab" class="tab-content hidden">
                <div class="space-y-6">
                    <!-- Export Data -->
                    <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg settings-card">
                        <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Export Your Data</h3>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> mb-4">Download a copy of all your data including transactions, bills, and settings.</p>
                        
                        <form method="POST" class="inline">
                            <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="export_data">
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition-colors">
                                <i class="fas fa-download mr-2"></i>Export Data
                            </button>
                        </form>
                    </div>
                    
                    <!-- Delete Account -->
                    <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg settings-card border-2 border-red-500">
                        <h3 class="text-lg font-semibold text-red-600 mb-4">Danger Zone</h3>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> mb-4">
                            <strong>Warning:</strong> Deleting your account will permanently remove all your data including transactions, bills, budgets, and settings. This action cannot be undone.
                        </p>
                        
                        <button onclick="confirmDeleteAccount()" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg transition-colors">
                            <i class="fas fa-trash mr-2"></i>Delete Account
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Delete Account Confirmation Modal -->
    <div id="delete-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-md">
            <div class="flex items-center justify-center mb-6">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
            </div>
            
            <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> text-center mb-4">Delete Account</h3>
            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-center mb-6">
                This action cannot be undone. All your data will be permanently deleted.
            </p>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="delete_account">
                
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">
                        Type <strong>DELETE</strong> to confirm:
                    </label>
                    <input type="text" name="confirm_delete" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="DELETE" required>
                </div>
                
                <div class="flex space-x-3">
                    <button type="button" onclick="closeDeleteModal()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 px-4 rounded-lg transition-colors">
                        Delete Account
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let isDarkMode = <?= $darkMode ? 'true' : 'false' ?>;
        
        // Mobile sidebar toggle
        const menuToggle = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('mobile-overlay');
        const closeSidebar = document.getElementById('close-sidebar');
        
        menuToggle?.addEventListener('click', () => {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
        });
        
        closeSidebar?.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        });
        
        overlay?.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        });
        
        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active', 'border-blue-600', 'text-blue-600');
                btn.classList.add('border-transparent');
                if (isDarkMode) {
                    btn.classList.add('text-gray-400');
                } else {
                    btn.classList.add('text-gray-500');
                }
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.remove('hidden');
            
            // Activate selected button
            const activeBtn = event.target.closest('.tab-btn');
            activeBtn.classList.add('active', 'border-blue-600', 'text-blue-600');
            activeBtn.classList.remove('border-transparent', 'text-gray-400', 'text-gray-500');
        }
        
        // Dark mode toggle
        function toggleDarkMode() {
            isDarkMode = !isDarkMode;
            document.documentElement.classList.toggle('dark');
            
            // Save preference
            fetch('ajax/toggle_dark_mode.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ dark_mode: isDarkMode })
            });
            
            // Update icon
            const icon = document.querySelector('[onclick="toggleDarkMode()"] i');
            icon.className = isDarkMode ? 'fas fa-sun text-xl' : 'fas fa-moon text-xl';
            
            // Reload page to apply theme changes properly
            setTimeout(() => location.reload(), 500);
        }
        
        // Delete account confirmation
        function confirmDeleteAccount() {
            document.getElementById('delete-modal').classList.remove('hidden');
        }
        
        function closeDeleteModal() {
            document.getElementById('delete-modal').classList.add('hidden');
            document.querySelector('input[name="confirm_delete"]').value = '';
        }
        
        // Password validation
        const passwordForm = document.getElementById('password-form');
        if (passwordForm) {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            if (newPassword && confirmPassword) {
                confirmPassword.addEventListener('input', function() {
                    if (this.value !== newPassword.value) {
                        this.setCustomValidity('Passwords do not match');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }
        }
        
        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = this.querySelector('input[name="action"]')?.value;
                
                if (action === 'change_password') {
                    const newPassword = this.querySelector('input[name="new_password"]').value;
                    const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
                    
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('New passwords do not match.');
                        return false;
                    }
                    
                    if (newPassword.length < 8) {
                        e.preventDefault();
                        alert('Password must be at least 8 characters long.');
                        return false;
                    }
                }
                
                if (action === 'delete_account') {
                    const confirmText = this.querySelector('input[name="confirm_delete"]').value;
                    if (confirmText !== 'DELETE') {
                        e.preventDefault();
                        alert('Please type DELETE to confirm account deletion.');
                        return false;
                    }
                }
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case '1':
                        e.preventDefault();
                        document.querySelector('[onclick="switchTab(\'profile\')"]').click();
                        break;
                    case '2':
                        e.preventDefault();
                        document.querySelector('[onclick="switchTab(\'security\')"]').click();
                        break;
                    case '3':
                        e.preventDefault();
                        document.querySelector('[onclick="switchTab(\'preferences\')"]').click();
                        break;
                    case '4':
                        e.preventDefault();
                        document.querySelector('[onclick="switchTab(\'data\')"]').click();
                        break;
                    case 'd':
                        e.preventDefault();
                        toggleDarkMode();
                        break;
                }
            }
            
            if (e.key === 'Escape') {
                closeDeleteModal();
            }
        });
        
        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.bg-red-500, .bg-green-500').forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s ease-out';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 500);
            }, 5000);
        });
    </script>
</body>
</html>