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

// Get wallet balance
$balance = $db->fetchOne("SELECT * FROM wallet_balance WHERE user_id = ?", [$user_id]);
if (!$balance) {
    $db->execute("INSERT INTO wallet_balance (user_id) VALUES (?)", [$user_id]);
    $balance = ['current_balance' => 0, 'total_income' => 0, 'total_expenses' => 0];
}

// Get recent transactions
$recentTransactions = $db->fetchAll(
    "SELECT t.*, c.name as category_name, c.icon as category_icon, c.color as category_color 
     FROM transactions t 
     LEFT JOIN categories c ON t.category_id = c.id 
     WHERE t.user_id = ? 
     ORDER BY t.transaction_date DESC 
     LIMIT 10",
    [$user_id]
);

// Get pending bills
$pendingBills = $db->fetchAll(
    "SELECT b.*, c.name as category_name, c.icon as category_icon, c.color as category_color
     FROM bills b
     LEFT JOIN categories c ON b.category_id = c.id
     WHERE b.user_id = ? AND b.status = 'pending'
     ORDER BY b.due_date ASC
     LIMIT 5",
    [$user_id]
);

// Get monthly statistics
$currentMonth = date('Y-m');
$monthlyStats = $db->fetchOne(
    "SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as monthly_income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as monthly_expenses,
        COUNT(CASE WHEN type = 'expense' THEN 1 END) as transaction_count
     FROM transactions 
     WHERE user_id = ? AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
    [$user_id, $currentMonth]
);

// Get notifications
$notifications = $db->fetchAll(
    "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5",
    [$user_id]
);

// Get category spending
$categorySpending = $db->fetchAll(
    "SELECT c.name, c.icon, c.color, SUM(t.amount) as total_spent
     FROM transactions t
     JOIN categories c ON t.category_id = c.id
     WHERE t.user_id = ? AND t.type = 'expense' AND DATE_FORMAT(t.transaction_date, '%Y-%m') = ?
     GROUP BY c.id
     ORDER BY total_spent DESC
     LIMIT 6",
    [$user_id, $currentMonth]
);

// Add this to the statistics query section (around line 45)
// Get savings statistics
$savingsStats = $db->fetchOne(
    "SELECT 
        COUNT(*) as total_accounts,
        SUM(current_amount) as total_saved,
        SUM(target_amount) as total_targets,
        COUNT(CASE WHEN current_amount >= target_amount THEN 1 END) as completed_goals
     FROM savings_accounts WHERE user_id = ?",
    [$user_id]
);

$themeClass = $darkMode ? 'dark' : '';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= APP_NAME ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="<?= PRIMARY_COLOR ?>">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
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
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        .card-hover { transition: all 0.3s ease; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
        .gradient-primary { background: linear-gradient(135deg, <?= PRIMARY_COLOR ?> 0%, #1e40af 100%); }
        .gradient-success { background: linear-gradient(135deg, <?= SUCCESS_COLOR ?> 0%, #10b981 100%); }
        .gradient-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .gradient-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        
        .dark { background-color: #0f172a; }
        .dark .bg-white { background-color: #1e293b !important; }
        .dark .text-gray-800 { color: #f1f5f9 !important; }
        .dark .text-gray-600 { color: #cbd5e1 !important; }
        .dark .text-gray-500 { color: #94a3b8 !important; }
        .dark .border-gray-200 { border-color: #334155 !important; }
        .dark .bg-gray-50 { background-color: #0f172a !important; }
        .dark .bg-gray-100 { background-color: #1e293b !important; }
        
        .pulse-dot {
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .notification-badge {
            animation: bounce 1s infinite;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
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
    <aside id="sidebar" class="fixed left-0 top-0 w-64 h-full <?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> shadow-xl z-50 sidebar-transition sidebar-mobile">
        <div class="flex flex-col h-full">
            <!-- Logo -->
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
            
            <!-- Navigation -->
            <nav class="flex-1 p-4 space-y-2">
                <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg gradient-primary text-white">
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
                    <?php if (count($pendingBills) > 0): ?>
                        <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full notification-badge"><?= count($pendingBills) ?></span>
                    <?php endif; ?>
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
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $darkMode ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100' ?> transition-colors">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </nav>
            
            <!-- User Info -->
            <div class="p-4 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 gradient-success rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-white"></i>
                    </div>
                    <div class="flex-1">
                        <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= $_SESSION['full_name'] ?? $_SESSION['username'] ?></p>
                        <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">Administrator</p>
                    </div>
                    <a href="logout.php" class="<?= $darkMode ? 'text-gray-400 hover:text-red-400' : 'text-gray-500 hover:text-red-500' ?> transition-colors" title="Logout">
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
                        <h1 class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Dashboard</h1>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Welcome back, <?= $_SESSION['full_name'] ?? $_SESSION['username'] ?>!</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <!-- Notifications -->
                    <div class="relative">
                        <button id="notifications-btn" class="<?= $darkMode ? 'text-gray-300 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?> transition-colors">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if (count($notifications) > 0): ?>
                                <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center notification-badge"><?= count($notifications) ?></span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Notifications Dropdown -->
                        <div id="notifications-dropdown" class="absolute right-0 mt-2 w-80 <?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-lg shadow-xl border <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?> z-50 hidden">
                            <div class="p-4 border-b <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                <h3 class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Notifications</h3>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <?php if (empty($notifications)): ?>
                                    <div class="p-4 text-center <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                                        <i class="fas fa-bell-slash text-2xl mb-2"></i>
                                        <p>No new notifications</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <div class="p-4 border-b <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?> <?= $darkMode ? 'hover:bg-gray-700' : 'hover:bg-gray-50' ?> transition-colors">
                                            <div class="flex items-start space-x-3">
                                                <div class="flex-shrink-0">
                                                    <?php
                                                    $iconClass = 'fas fa-info-circle text-blue-500';
                                                    if ($notification['type'] === 'warning') $iconClass = 'fas fa-exclamation-triangle text-yellow-500';
                                                    if ($notification['type'] === 'error') $iconClass = 'fas fa-times-circle text-red-500';
                                                    if ($notification['type'] === 'success') $iconClass = 'fas fa-check-circle text-green-500';
                                                    ?>
                                                    <i class="<?= $iconClass ?>"></i>
                                                </div>
                                                <div class="flex-1">
                                                    <h4 class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= htmlspecialchars($notification['title']) ?></h4>
                                                    <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> mt-1"><?= htmlspecialchars($notification['message']) ?></p>
                                                    <p class="text-xs <?= $darkMode ? 'text-gray-500' : 'text-gray-400' ?> mt-2"><?= formatDate($notification['created_at'], 'M j, Y g:i A') ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($notifications)): ?>
                                <div class="p-4 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                    <button onclick="markAllAsRead()" class="text-blue-600 hover:text-blue-700 text-sm font-medium">Mark all as read</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Dark Mode Toggle -->
                    <button onclick="toggleDarkMode()" class="<?= $darkMode ? 'text-gray-300 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?> transition-colors" title="Toggle Dark Mode">
                        <i class="fas fa-<?= $darkMode ? 'sun' : 'moon' ?> text-xl"></i>
                    </button>
                    
                    <!-- Quick Add -->
                    <button onclick="openQuickAdd()" class="gradient-primary text-white px-4 py-2 rounded-lg hover:opacity-90 transition-opacity">
                        <i class="fas fa-plus mr-2"></i>Quick Add
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Dashboard Content -->
        <div class="p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Current Balance -->
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg card-hover">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Current Balance</p>
                            <p class="text-3xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatMoney($balance['current_balance'] ?? 0, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 gradient-primary rounded-lg flex items-center justify-center">
                            <i class="fas fa-wallet text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center">
                        <span class="text-green-500 text-sm font-medium">
                            <i class="fas fa-arrow-up mr-1"></i>+2.5%
                        </span>
                        <span class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm ml-2">from last month</span>
                    </div>
                </div>
                
                <!-- Monthly Income -->
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg card-hover">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Monthly Income</p>
                            <p class="text-3xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatMoney($monthlyStats['monthly_income'] ?? 0, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 gradient-success rounded-lg flex items-center justify-center">
                            <i class="fas fa-arrow-down text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center">
                        <span class="text-green-500 text-sm font-medium">
                            <i class="fas fa-arrow-up mr-1"></i>+5.2%
                        </span>
                        <span class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm ml-2">from last month</span>
                    </div>
                </div>
                
                <!-- Monthly Expenses -->
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg card-hover">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Monthly Expenses</p>
                            <p class="text-3xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatMoney($monthlyStats['monthly_expenses'] ?? 0, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 gradient-danger rounded-lg flex items-center justify-center">
                            <i class="fas fa-arrow-up text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center">
                        <span class="text-red-500 text-sm font-medium">
                            <i class="fas fa-arrow-up mr-1"></i>+1.8%
                        </span>
                        <span class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm ml-2">from last month</span>
                    </div>
                </div>
                
                <!-- Pending Bills -->
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg card-hover">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Pending Bills</p>
                            <p class="text-3xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= count($pendingBills) ?></p>
                        </div>
                        <div class="w-12 h-12 gradient-warning rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-invoice text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center">
                        <?php
                        $totalPendingAmount = array_sum(array_column($pendingBills, 'amount'));
                        ?>
                        <span class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm">
                            Total: <?= formatMoney($totalPendingAmount, $currency) ?>
                        </span>
                    </div>
                </div>
            </div>
            
            


<div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg card-hover">
    <div class="flex items-center justify-between">
        <div>
            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Saved</p>
            <p class="text-3xl font-bold text-green-600"><?= formatMoney($savingsStats['total_saved'] ?? 0, $currency) ?></p>
        </div>
        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-piggy-bank text-green-600 text-xl"></i>
        </div>
    </div>
    <div class="mt-4 flex items-center">
        <span class="text-blue-500 text-sm font-medium">
            <i class="fas fa-target mr-1"></i><?= $savingsStats['completed_goals'] ?? 0 ?> goals completed
        </span>
    </div>
</div><br>
            
            <!-- Charts and Quick Actions Row -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Spending by Category Chart -->
                <div class="lg:col-span-2 <?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Spending by Category</h3>
                        <select class="<?= $darkMode ? 'bg-gray-700 text-white border-gray-600' : 'bg-gray-50 text-gray-800 border-gray-300' ?> border rounded-lg px-3 py-2 text-sm">
                            <option>This Month</option>
                            <option>Last Month</option>
                            <option>Last 3 Months</option>
                        </select>
                    </div>
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                    <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-6">Quick Actions</h3>
                    <div class="space-y-4">
                        <button onclick="addIncome()" class="w-full bg-green-500 hover:bg-green-600 text-white py-3 px-4 rounded-lg transition-colors">
                            <i class="fas fa-plus-circle mr-2"></i>Add Income
                        </button>
                        <button onclick="addExpense()" class="w-full bg-red-500 hover:bg-red-600 text-white py-3 px-4 rounded-lg transition-colors">
                            <i class="fas fa-minus-circle mr-2"></i>Add Expense
                        </button>
                        <button onclick="payBill()" class="w-full bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-lg transition-colors">
                            <i class="fas fa-file-invoice-dollar mr-2"></i>Pay Bill
                        </button>
                        
                        <button onclick="window.location.href='savings.php'" class="w-full bg-green-500 hover:bg-green-600 text-white py-3 px-4 rounded-lg transition-colors">
                            <i class="fas fa-piggy-bank mr-2"></i>Manage Savings
                        </button>
                        <button onclick="addBill()" class="w-full bg-purple-500 hover:bg-purple-600 text-white py-3 px-4 rounded-lg transition-colors">
                            <i class="fas fa-plus mr-2"></i>Add Bill
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Recent Transactions and Pending Bills -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Transactions -->
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Recent Transactions</h3>
                        <a href="transactions.php" class="text-blue-600 hover:text-blue-700 text-sm font-medium">View All</a>
                    </div>
                    <div class="space-y-4">
                        <?php if (empty($recentTransactions)): ?>
                            <div class="text-center py-8 <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                                <i class="fas fa-exchange-alt text-3xl mb-4"></i>
                                <p>No transactions yet</p>
                                <button onclick="addTransaction()" class="mt-4 text-blue-600 hover:text-blue-700 font-medium">Add your first transaction</button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentTransactions as $transaction): ?>
                                <div class="flex items-center space-x-4 p-4 rounded-lg border <?= $darkMode ? 'border-gray-700 hover:bg-gray-700' : 'border-gray-200 hover:bg-gray-50' ?> transition-colors">
                                    <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: <?= $transaction['category_color'] ?? '#6b7280' ?>;">
                                        <i class="<?= $transaction['category_icon'] ?? 'fas fa-money-bill' ?> text-white"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= htmlspecialchars($transaction['description']) ?></p>
                                        <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= $transaction['category_name'] ?? 'Uncategorized' ?></p>
                                        <p class="text-xs <?= $darkMode ? 'text-gray-500' : 'text-gray-400' ?>"><?= formatDate($transaction['transaction_date'], 'M j, Y') ?></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold <?= $transaction['type'] === 'income' ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= $transaction['type'] === 'income' ? '+' : '-' ?><?= formatMoney($transaction['amount'], $currency) ?>
                                        </p>
                                        <p class="text-xs <?= $darkMode ? 'text-gray-500' : 'text-gray-400' ?>"><?= ucfirst($transaction['payment_method']) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Pending Bills -->
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Pending Bills</h3>
                        <a href="bills.php" class="text-blue-600 hover:text-blue-700 text-sm font-medium">View All</a>
                    </div>
                    <div class="space-y-4">
                        <?php if (empty($pendingBills)): ?>
                            <div class="text-center py-8 <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                                <i class="fas fa-file-invoice text-3xl mb-4"></i>
                                <p>No pending bills</p>
                                <button onclick="addBill()" class="mt-4 text-blue-600 hover:text-blue-700 font-medium">Add a bill</button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pendingBills as $bill): ?>
                                <?php
                                $daysUntilDue = (strtotime($bill['due_date']) - time()) / (60 * 60 * 24);
                                $urgencyClass = $daysUntilDue <= 3 ? 'border-red-500' : ($daysUntilDue <= 7 ? 'border-yellow-500' : 'border-gray-200');
                                ?>
                                <div class="flex items-center space-x-4 p-4 rounded-lg border-2 <?= $urgencyClass ?> <?= $darkMode ? 'hover:bg-gray-700' : 'hover:bg-gray-50' ?> transition-colors">
                                    <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: <?= $bill['category_color'] ?? '#6b7280' ?>;">
                                        <i class="<?= $bill['category_icon'] ?? 'fas fa-file-invoice' ?> text-white"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= htmlspecialchars($bill['name']) ?></p>
                                        <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= $bill['category_name'] ?></p>
                                        <p class="text-xs <?= $daysUntilDue <= 3 ? 'text-red-600' : ($daysUntilDue <= 7 ? 'text-yellow-600' : ($darkMode ? 'text-gray-500' : 'text-gray-400')) ?>">
                                            Due: <?= formatDate($bill['due_date'], 'M j, Y') ?>
                                            <?php if ($daysUntilDue <= 3): ?>
                                                <span class="font-medium">(<?= round($daysUntilDue) ?> days)</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatMoney($bill['amount'], $currency) ?></p>
                                        <button onclick="payBillNow(<?= $bill['id'] ?>)" class="text-xs bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded mt-1 transition-colors">
                                            Pay Now
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Quick Add Modal -->
    <div id="quick-add-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-md">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Quick Add Transaction</h3>
                <button onclick="closeQuickAdd()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="quick-add-form" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Type</label>
                    <select name="type" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                        <option value="">Select Type</option>
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Amount</label>
                    <input type="number" name="amount" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00" required>
                </div>
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Description</label>
                    <input type="text" name="description" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="Transaction description" required>
                </div>
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Category</label>
                    <select name="category_id" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                        <option value="">Select Category</option>
                        <?php
                        $categories = $db->fetchAll("SELECT * FROM categories WHERE user_id = ? OR is_default = 1 ORDER BY name", [$user_id]);
                        foreach ($categories as $category):
                        ?>
                            <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Payment Method</label>
                    <select name="payment_method" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="card">Card</option>
                    </select>
                </div>
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="closeQuickAdd()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 gradient-primary text-white py-2 px-4 rounded-lg hover:opacity-90 transition-opacity">
                        Add Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    
    <script>
        // Theme management
        let isDarkMode = <?= $darkMode ? 'true' : 'false' ?>;
        
        // Mobile menu toggle
        const menuToggle = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar');
        const mobileOverlay = document.getElementById('mobile-overlay');
        const closeSidebar = document.getElementById('close-sidebar');
        
        menuToggle.addEventListener('click', () => {
            sidebar.classList.remove('sidebar-mobile');
            mobileOverlay.classList.remove('hidden');
        });
        
        closeSidebar.addEventListener('click', () => {
            sidebar.classList.add('sidebar-mobile');
            mobileOverlay.classList.add('hidden');
        });
        
        mobileOverlay.addEventListener('click', () => {
            sidebar.classList.add('sidebar-mobile');
            mobileOverlay.classList.add('hidden');
        });
        
        // Notifications dropdown
        const notificationsBtn = document.getElementById('notifications-btn');
        const notificationsDropdown = document.getElementById('notifications-dropdown');
        
        notificationsBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notificationsDropdown.classList.toggle('hidden');
        });
        
        document.addEventListener('click', () => {
            notificationsDropdown.classList.add('hidden');
        });
        
        // Dark mode toggle
        // Dark mode toggle (Fixed version)
function toggleDarkMode() {
    isDarkMode = !isDarkMode;
    document.documentElement.classList.toggle('dark');
    
    // Update the icon immediately
    const toggleButton = document.querySelector('[onclick="toggleDarkMode()"]');
    if (toggleButton) {
        const icon = toggleButton.querySelector('i');
        if (icon) {
            icon.className = isDarkMode ? 'fas fa-sun text-xl' : 'fas fa-moon text-xl';
        }
    }
    
    // Save preference to server
    fetch('ajax/toggle_dark_mode.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ dark_mode: isDarkMode })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            console.log('Dark mode preference saved');
        }
    })
    .catch(error => {
        console.error('Failed to save dark mode preference:', error);
    });
    
    // Update chart colors if they exist
    updateChartColors();
}
        
        // Quick Add Modal
        const quickAddModal = document.getElementById('quick-add-modal');
        const quickAddForm = document.getElementById('quick-add-form');
        
        function openQuickAdd() {
            quickAddModal.classList.remove('hidden');
        }
        
        function closeQuickAdd() {
            quickAddModal.classList.add('hidden');
            quickAddForm.reset();
        }
        
        // Quick Add Form Submission
        quickAddForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(quickAddForm);
            
            try {
                const response = await fetch('ajax/add_transaction.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    closeQuickAdd();
                    showNotification('Transaction added successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(result.message || 'Error adding transaction', 'error');
                }
            } catch (error) {
                showNotification('Network error. Please try again.', 'error');
            }
        });
        
        // Quick Actions
        function addIncome() {
            openQuickAdd();
            document.querySelector('select[name="type"]').value = 'income';
        }
        
        function addExpense() {
            openQuickAdd();
            document.querySelector('select[name="type"]').value = 'expense';
        }
        
        function payBill() {
            window.location.href = 'bills.php?action=pay';
        }
        
        function addBill() {
            window.location.href = 'bills.php?action=add';
        }
        
        function addTransaction() {
            openQuickAdd();
        }
        
        function payBillNow(billId) {
            if (confirm('Are you sure you want to pay this bill?')) {
                fetch('ajax/pay_bill.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ bill_id: billId })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showNotification('Bill paid successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification(result.message || 'Error paying bill', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Network error. Please try again.', 'error');
                });
            }
        }
        
        // Notifications
        function markAllAsRead() {
            fetch('ajax/mark_notifications_read.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    location.reload();
                }
            });
        }
        
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg text-white transform translate-x-full transition-transform duration-300 ${
                type === 'success' ? 'bg-green-500' :
                type === 'error' ? 'bg-red-500' :
                type === 'warning' ? 'bg-yellow-500' :
                'bg-blue-500'
            }`;
            notification.innerHTML = `
                <div class="flex items-center space-x-2">
                    <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'times' : type === 'warning' ? 'exclamation' : 'info'}-circle"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }
        
        // Category Chart
        const categoryData = <?= json_encode($categorySpending) ?>;
        
        function createCategoryChart() {
            const ctx = document.getElementById('categoryChart').getContext('2d');
            
            const chartData = {
                labels: categoryData.map(item => item.name),
                datasets: [{
                    data: categoryData.map(item => item.total_spent),
                    backgroundColor: categoryData.map(item => item.color),
                    borderWidth: 0
                }]
            };
            
            return new Chart(ctx, {
                type: 'doughnut',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: isDarkMode ? '#f1f5f9' : '#374151',
                                usePointStyle: true,
                                padding: 20
                            }
                        }
                    }
                }
            });
        }
        
        let categoryChart;
        
        function updateChartColors() {
            if (categoryChart) {
                categoryChart.options.plugins.legend.labels.color = isDarkMode ? '#f1f5f9' : '#374151';
                categoryChart.update();
            }
        }
        
        // Initialize chart
        document.addEventListener('DOMContentLoaded', () => {
            if (categoryData.length > 0) {
                categoryChart = createCategoryChart();
            } else {
                const chartContainer = document.querySelector('.chart-container');
                chartContainer.innerHTML = `
                    <div class="flex items-center justify-center h-full text-gray-500">
                        <div class="text-center">
                            <i class="fas fa-chart-pie text-4xl mb-4"></i>
                            <p>No spending data available</p>
                            <p class="text-sm">Add some expenses to see the chart</p>
                        </div>
                    </div>
                `;
            }
        });
        
        // Auto-refresh notifications every 5 minutes
        setInterval(() => {
            fetch('ajax/get_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.count > 0) {
                        const badge = document.querySelector('.notification-badge');
                        if (badge) {
                            badge.textContent = data.count;
                        }
                    }
                });
        }, 300000);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case 'k':
                        e.preventDefault();
                        openQuickAdd();
                        break;
                    case 'd':
                        e.preventDefault();
                        toggleDarkMode();
                        break;
                }
            }
            
            if (e.key === 'Escape') {
                closeQuickAdd();
                notificationsDropdown.classList.add('hidden');
                sidebar.classList.add('sidebar-mobile');
                mobileOverlay.classList.add('hidden');
            }
        });
        
        // Touch gestures for mobile
        let touchStartX = 0;
        
        document.addEventListener('touchstart', (e) => {
            touchStartX = e.touches[0].clientX;
        });
        
        document.addEventListener('touchend', (e) => {
            const touchEndX = e.changedTouches[0].clientX;
            const diffX = touchStartX - touchEndX;
            
            if (Math.abs(diffX) > 100) { // Minimum swipe distance
                if (diffX > 0 && touchStartX < 50) { // Swipe left from left edge
                    sidebar.classList.remove('sidebar-mobile');
                    mobileOverlay.classList.remove('hidden');
                } else if (diffX < 0 && !sidebar.classList.contains('sidebar-mobile')) { // Swipe right when sidebar is open
                    sidebar.classList.add('sidebar-mobile');
                    mobileOverlay.classList.add('hidden');
                }
            }
        });
        
        // Service Worker for PWA
        // Service Worker for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(registration => {
                console.log('SW registered:', registration);
                
                // Check for updates
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            // Show update notification
                            if (confirm('New version available! Reload to update?')) {
                                window.location.reload();
                            }
                        }
                    });
                });
            })
            .catch(error => {
                console.log('SW registration failed:', error);
            });
    });
}
        
        // Install prompt for PWA
let deferredPrompt;
let installButton;

window.addEventListener('beforeinstallprompt', (e) => {
    console.log('Install prompt triggered');
    e.preventDefault();
    deferredPrompt = e;
    
    // Show install button
    showInstallButton();
});

function showInstallButton() {
    // Remove existing install button if any
    if (installButton) {
        installButton.remove();
    }
    
    // Create install button
    installButton = document.createElement('button');
    installButton.className = 'fixed bottom-4 right-4 bg-blue-600 text-white px-4 py-3 rounded-lg shadow-lg z-50 flex items-center space-x-2 hover:bg-blue-700 transition-colors';
    installButton.innerHTML = '<i class="fas fa-download"></i><span>Install App</span>';
    
    installButton.onclick = async () => {
        if (!deferredPrompt) return;
        
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        
        console.log('User choice:', outcome);
        
        if (outcome === 'accepted') {
            console.log('User accepted the install prompt');
        }
        
        deferredPrompt = null;
        installButton.remove();
    };
    
    document.body.appendChild(installButton);
    
    // Auto-hide after 15 seconds
    setTimeout(() => {
        if (installButton && document.body.contains(installButton)) {
            installButton.remove();
        }
    }, 15000);
}

// Handle app installed
window.addEventListener('appinstalled', (e) => {
    console.log('App was installed');
    if (installButton) {
        installButton.remove();
    }
});
          
    </script>
    <script src="assets/js/app.js"></script>
</body>
</html>
