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

// Date range filters
$dateRange = $_GET['range'] ?? 'this_month';
$customStart = $_GET['start_date'] ?? '';
$customEnd = $_GET['end_date'] ?? '';

// Calculate date range
switch ($dateRange) {
    case 'today':
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        break;
    case 'yesterday':
        $startDate = date('Y-m-d', strtotime('-1 day'));
        $endDate = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'this_week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d');
        break;
    case 'last_week':
        $startDate = date('Y-m-d', strtotime('monday last week'));
        $endDate = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'this_month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        break;
    case 'last_month':
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'this_year':
        $startDate = date('Y-01-01');
        $endDate = date('Y-12-31');
        break;
    case 'last_year':
        $startDate = date('Y-01-01', strtotime('last year'));
        $endDate = date('Y-12-31', strtotime('last year'));
        break;
    case 'custom':
        $startDate = $customStart ?: date('Y-m-01');
        $endDate = $customEnd ?: date('Y-m-d');
        break;
    default:
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
}

// Financial summary
$summary = $db->fetchOne(
    "SELECT 
        COUNT(*) as total_transactions,
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expenses,
        AVG(CASE WHEN type = 'income' THEN amount END) as avg_income,
        AVG(CASE WHEN type = 'expense' THEN amount END) as avg_expense
     FROM transactions 
     WHERE user_id = ? AND DATE(transaction_date) BETWEEN ? AND ?",
    [$user_id, $startDate, $endDate]
);

// Category breakdown
$categoryBreakdown = $db->fetchAll(
    "SELECT c.name, c.color, c.icon,
        SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END) as income,
        SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) as expenses,
        COUNT(t.id) as transaction_count
     FROM categories c
     LEFT JOIN transactions t ON c.id = t.category_id AND t.user_id = ? AND DATE(t.transaction_date) BETWEEN ? AND ?
     WHERE c.user_id = ? OR c.is_default = 1
     GROUP BY c.id
     HAVING income > 0 OR expenses > 0
     ORDER BY expenses DESC",
    [$user_id, $startDate, $endDate, $user_id]
);

// Monthly trends (last 12 months)
$monthlyTrends = $db->fetchAll(
    "SELECT 
        DATE_FORMAT(transaction_date, '%Y-%m') as month,
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expenses
     FROM transactions 
     WHERE user_id = ? AND transaction_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
     GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
     ORDER BY month ASC",
    [$user_id]
);

// Daily spending pattern
$dailyPattern = $db->fetchAll(
    "SELECT 
        DAYNAME(transaction_date) as day_name,
        DAYOFWEEK(transaction_date) as day_number,
        AVG(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as avg_expense
     FROM transactions 
     WHERE user_id = ? AND type = 'expense' AND transaction_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
     GROUP BY DAYOFWEEK(transaction_date), DAYNAME(transaction_date)
     ORDER BY day_number",
    [$user_id]
);

// Payment method analysis
$paymentMethods = $db->fetchAll(
    "SELECT 
        payment_method,
        COUNT(*) as transaction_count,
        SUM(amount) as total_amount,
        AVG(amount) as avg_amount
     FROM transactions 
     WHERE user_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
     GROUP BY payment_method
     ORDER BY total_amount DESC",
    [$user_id, $startDate, $endDate]
);

// Top expenses
$topExpenses = $db->fetchAll(
    "SELECT t.*, c.name as category_name, c.color
     FROM transactions t
     LEFT JOIN categories c ON t.category_id = c.id
     WHERE t.user_id = ? AND t.type = 'expense' AND DATE(t.transaction_date) BETWEEN ? AND ?
     ORDER BY t.amount DESC
     LIMIT 10",
    [$user_id, $startDate, $endDate]
);
// Savings breakdown
$savingsBreakdown = $db->fetchAll(
    "SELECT sa.name, sa.current_amount, sa.target_amount, sa.color, sa.icon,
     CASE 
         WHEN sa.target_amount > 0 THEN ROUND((sa.current_amount / sa.target_amount) * 100, 2)
         ELSE 0 
     END as progress_percentage
     FROM savings_accounts sa 
     WHERE sa.user_id = ? AND sa.status = 'active'
     ORDER BY sa.current_amount DESC",
    [$user_id]
);

$themeClass = $darkMode ? 'dark' : '';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - <?= APP_NAME ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    
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
        
        .report-card { transition: all 0.3s ease; }
        .report-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
        
        .chart-container { position: relative; height: 400px; }
        
        .gradient-primary { background: linear-gradient(135deg, <?= PRIMARY_COLOR ?> 0%, #1e40af 100%); }
        .gradient-success { background: linear-gradient(135deg, <?= SUCCESS_COLOR ?> 0%, #10b981 100%); }
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
                <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg gradient-primary text-white">
                    <i class="fas fa-chart-line"></i>
                    <span>Reports</span>
                </a>
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $darkMode ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100' ?> transition-colors">
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
                       <h1 class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Financial Reports</h1>
                       <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Analyze your financial data and trends</p>
                   </div>
               </div>
               
               <div class="flex items-center space-x-4">
                   <button onclick="exportReport()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-colors">
                       <i class="fas fa-download mr-2"></i>Export PDF
                   </button>
               </div>
           </div>
       </header>
       
       <!-- Date Range Filter -->
       <div class="p-6">
           <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg mb-6">
               <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Report Period</h3>
               <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                   <div>
                       <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Date Range</label>
                       <select name="range" id="date-range" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                           <option value="today" <?= $dateRange === 'today' ? 'selected' : '' ?>>Today</option>
                           <option value="yesterday" <?= $dateRange === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                           <option value="this_week" <?= $dateRange === 'this_week' ? 'selected' : '' ?>>This Week</option>
                           <option value="last_week" <?= $dateRange === 'last_week' ? 'selected' : '' ?>>Last Week</option>
                           <option value="this_month" <?= $dateRange === 'this_month' ? 'selected' : '' ?>>This Month</option>
                           <option value="last_month" <?= $dateRange === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                           <option value="this_year" <?= $dateRange === 'this_year' ? 'selected' : '' ?>>This Year</option>
                           <option value="last_year" <?= $dateRange === 'last_year' ? 'selected' : '' ?>>Last Year</option>
                           <option value="custom" <?= $dateRange === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                       </select>
                   </div>
                   
                   <div id="custom-start" class="<?= $dateRange !== 'custom' ? 'hidden' : '' ?>">
                       <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Start Date</label>
                       <input type="date" name="start_date" value="<?= $customStart ?>" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                   </div>
                   
                   <div id="custom-end" class="<?= $dateRange !== 'custom' ? 'hidden' : '' ?>">
                       <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">End Date</label>
                       <input type="date" name="end_date" value="<?= $customEnd ?>" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                   </div>
                   
                   <div class="flex items-end">
                       <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                           <i class="fas fa-search mr-2"></i>Generate Report
                       </button>
                   </div>
               </form>
           </div>
           
           <!-- Summary Cards -->
           <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
               <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg report-card">
                   <div class="flex items-center justify-between">
                       <div>
                           <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Income</p>
                           <p class="text-2xl font-bold text-green-600"><?= formatMoney($summary['total_income'] ?? 0, $currency) ?></p>
                           <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Avg: <?= formatMoney($summary['avg_income'] ?? 0, $currency) ?></p>
                       </div>
                       <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                           <i class="fas fa-arrow-down text-green-600 text-xl"></i>
                       </div>
                   </div>
               </div>
               
               <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg report-card">
                   <div class="flex items-center justify-between">
                       <div>
                           <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Expenses</p>
                           <p class="text-2xl font-bold text-red-600"><?= formatMoney($summary['total_expenses'] ?? 0, $currency) ?></p>
                           <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Avg: <?= formatMoney($summary['avg_expense'] ?? 0, $currency) ?></p>
                       </div>
                       <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                           <i class="fas fa-arrow-up text-red-600 text-xl"></i>
                       </div>
                   </div>
               </div>
               
               <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg report-card">
                   <div class="flex items-center justify-between">
                       <div>
                           <?php $netAmount = ($summary['total_income'] ?? 0) - ($summary['total_expenses'] ?? 0); ?>
                           <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Net Amount</p>
                           <p class="text-2xl font-bold <?= $netAmount >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= formatMoney($netAmount, $currency) ?></p>
                           <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= $netAmount >= 0 ? 'Profit' : 'Loss' ?></p>
                       </div>
                       <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                           <i class="fas fa-balance-scale text-blue-600 text-xl"></i>
                       </div>
                   </div>
               </div>
               
               
               <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg report-card">
                   <div class="flex items-center justify-between">
                       <div>
                           <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Transactions</p>
                           <p class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= number_format($summary['total_transactions'] ?? 0) ?></p>
                           <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Total count</p>
                       </div>
                       <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                           <i class="fas fa-exchange-alt text-purple-600 text-xl"></i>
                       </div>
                   </div>
               </div>
           </div>
               <!-- Savings Breakdown -->
                <?php if (!empty($savingsBreakdown)): ?>
                    <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg mb-6">
                        <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Savings Goals Progress</h3>
                        <div class="space-y-4">
                            <?php foreach ($savingsBreakdown as $savings): ?>
                                <div class="flex items-center justify-between p-3 border <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?> rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: <?= $savings['color'] ?>;">
                                            <i class="<?= $savings['icon'] ?> text-white"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= htmlspecialchars($savings['name']) ?></p>
                                            <div class="w-32 bg-gray-200 rounded-full h-2 mt-1">
                                                <div class="bg-green-500 h-2 rounded-full" style="width: <?= min(100, $savings['progress_percentage']) ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold text-green-600"><?= formatMoney($savings['current_amount'], $currency) ?></p>
                                        <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">of <?= formatMoney($savings['target_amount'], $currency) ?></p>
                                        <p class="text-xs text-blue-600"><?= number_format($savings['progress_percentage'], 1) ?>%</p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
               
               
           
           <!-- Charts Row -->
           <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
               <!-- Monthly Trends -->
               <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                   <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Monthly Trends (Last 12 Months)</h3>
                   <div class="chart-container">
                       <canvas id="trendsChart"></canvas>
                   </div>
               </div>
               
               <!-- Category Breakdown -->
               <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                   <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Expense by Category</h3>
                   <div class="chart-container">
                       <canvas id="categoryChart"></canvas>
                   </div>
               </div>
           </div>
           
           <!-- Analysis Tables -->
           <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
               <!-- Category Analysis -->
               <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                   <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Category Analysis</h3>
                   <div class="space-y-4">
                       <?php foreach ($categoryBreakdown as $category): ?>
                           <?php if ($category['expenses'] > 0): ?>
                               <div class="flex items-center justify-between p-3 border <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?> rounded-lg">
                                   <div class="flex items-center space-x-3">
                                       <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: <?= $category['color'] ?>;">
                                           <i class="<?= $category['icon'] ?> text-white"></i>
                                       </div>
                                       <div>
                                           <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= htmlspecialchars($category['name']) ?></p>
                                           <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= $category['transaction_count'] ?> transactions</p>
                                       </div>
                                   </div>
                                   <div class="text-right">
                                       <p class="font-semibold text-red-600"><?= formatMoney($category['expenses'], $currency) ?></p>
                                       <?php if ($category['income'] > 0): ?>
                                           <p class="text-sm text-green-600">+<?= formatMoney($category['income'], $currency) ?></p>
                                       <?php endif; ?>
                                   </div>
                               </div>
                           <?php endif; ?>
                       <?php endforeach; ?>
                   </div>
               </div>
               
               <!-- Payment Methods -->
               <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                   <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Payment Methods</h3>
                   <div class="space-y-4">
                       <?php foreach ($paymentMethods as $method): ?>
                           <div class="flex items-center justify-between p-3 border <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?> rounded-lg">
                               <div class="flex items-center space-x-3">
                                   <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                       <i class="fas fa-<?= 
                                           $method['payment_method'] === 'cash' ? 'money-bill' :
                                           ($method['payment_method'] === 'bank' ? 'university' :
                                           ($method['payment_method'] === 'mobile_money' ? 'mobile-alt' : 'credit-card'))
                                       ?> text-blue-600"></i>
                                   </div>
                                   <div>
                                       <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= ucfirst(str_replace('_', ' ', $method['payment_method'])) ?></p>
                                       <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= $method['transaction_count'] ?> transactions</p>
                                   </div>
                               </div>
                               <div class="text-right">
                                   <p class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatMoney($method['total_amount'], $currency) ?></p>
                                   <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Avg: <?= formatMoney($method['avg_amount'], $currency) ?></p>
                               </div>
                           </div>
                       <?php endforeach; ?>
                   </div>
               </div>
           </div>
           
           <!-- Top Expenses -->
           <?php if (!empty($topExpenses)): ?>
               <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                   <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Top Expenses</h3>
                   <div class="overflow-x-auto">
                       <table class="w-full">
                           <thead>
                               <tr class="border-b <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                   <th class="text-left py-3 text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?>">Date</th>
                                   <th class="text-left py-3 text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?>">Description</th>
                                   <th class="text-left py-3 text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?>">Category</th>
                                   <th class="text-right py-3 text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?>">Amount</th>
                               </tr>
                           </thead>
                           <tbody class="divide-y <?= $darkMode ? 'divide-gray-700' : 'divide-gray-200' ?>">
                               <?php foreach ($topExpenses as $expense): ?>
                                   <tr>
                                       <td class="py-3 text-sm <?= $darkMode ? 'text-white' : 'text-gray-900' ?>"><?= formatDate($expense['transaction_date'], 'M j, Y') ?></td>
                                       <td class="py-3 text-sm <?= $darkMode ? 'text-white' : 'text-gray-900' ?>"><?= htmlspecialchars($expense['description']) ?></td>
                                       <td class="py-3">
                                           <span class="inline-flex items-center px-2 py-1 rounded-full text-xs" style="background-color: <?= $expense['color'] ?? '#6b7280' ?>20; color: <?= $expense['color'] ?? '#6b7280' ?>;">
                                               <?= htmlspecialchars($expense['category_name'] ?? 'Uncategorized') ?>
                                           </span>
                                       </td>
                                       <td class="py-3 text-sm font-semibold text-red-600 text-right"><?= formatMoney($expense['amount'], $currency) ?></td>
                                   </tr>
                               <?php endforeach; ?>
                           </tbody>
                       </table>
                   </div>
               </div>
           <?php endif; ?>
           
           
           
       </div>
   </main>
   
   <script>
       // Mobile menu functionality
       const menuToggle = document.getElementById('menu-toggle');
       const sidebar = document.getElementById('sidebar');
       const mobileOverlay = document.getElementById('mobile-overlay');
       const closeSidebar = document.getElementById('close-sidebar');
       
       menuToggle?.addEventListener('click', () => {
           sidebar.classList.remove('-translate-x-full');
           mobileOverlay.classList.remove('hidden');
       });
       
       closeSidebar?.addEventListener('click', () => {
           sidebar.classList.add('-translate-x-full');
           mobileOverlay.classList.add('hidden');
       });
       
       mobileOverlay?.addEventListener('click', () => {
           sidebar.classList.add('-translate-x-full');
           mobileOverlay.classList.add('hidden');
       });
       
       // Date range toggle
       document.getElementById('date-range').addEventListener('change', function() {
           const customStart = document.getElementById('custom-start');
           const customEnd = document.getElementById('custom-end');
           
           if (this.value === 'custom') {
               customStart.classList.remove('hidden');
               customEnd.classList.remove('hidden');
           } else {
               customStart.classList.add('hidden');
               customEnd.classList.add('hidden');
           }
       });
       
       // Charts
       const isDarkMode = <?= $darkMode ? 'true' : 'false' ?>;
       const textColor = isDarkMode ? '#f1f5f9' : '#374151';
       const gridColor = isDarkMode ? '#374151' : '#e5e7eb';
       
       // Monthly Trends Chart
       const trendsData = <?= json_encode($monthlyTrends) ?>;
       if (trendsData.length > 0) {
           const trendsCtx = document.getElementById('trendsChart').getContext('2d');
           new Chart(trendsCtx, {
               type: 'line',
               data: {
                   labels: trendsData.map(item => {
                       const date = new Date(item.month + '-01');
                       return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                   }),
                   datasets: [{
                       label: 'Income',
                       data: trendsData.map(item => item.income),
                       borderColor: '#10b981',
                       backgroundColor: 'rgba(16, 185, 129, 0.1)',
                       tension: 0.4
                   }, {
                       label: 'Expenses',
                       data: trendsData.map(item => item.expenses),
                       borderColor: '#ef4444',
                       backgroundColor: 'rgba(239, 68, 68, 0.1)',
                       tension: 0.4
                   }]
               },
               options: {
                   responsive: true,
                   maintainAspectRatio: false,
                   plugins: {
                       legend: {
                           labels: { color: textColor }
                       }
                   },
                   scales: {
                       x: {
                           ticks: { color: textColor },
                           grid: { color: gridColor }
                       },
                       y: {
                           ticks: { color: textColor },
                           grid: { color: gridColor }
                       }
                   }
               }
           });
       }
       
       // Category Chart
       const categoryData = <?= json_encode($categoryBreakdown) ?>;
       const expenseCategories = categoryData.filter(cat => cat.expenses > 0);
       
       if (expenseCategories.length > 0) {
           const categoryCtx = document.getElementById('categoryChart').getContext('2d');
           new Chart(categoryCtx, {
               type: 'doughnut',
               data: {
                   labels: expenseCategories.map(cat => cat.name),
                   datasets: [{
                       data: expenseCategories.map(cat => cat.expenses),
                       backgroundColor: expenseCategories.map(cat => cat.color),
                       borderWidth: 0
                   }]
               },
               options: {
                   responsive: true,
                   maintainAspectRatio: false,
                   plugins: {
                       legend: {
                           position: 'bottom',
                           labels: {
                               color: textColor,
                               usePointStyle: true,
                               padding: 20
                           }
                       }
                   }
               }
           });
       }
       
       // Export function
       function exportReport() {
           // This would typically generate and download a PDF report
           alert('Wait MF!!');
       }
       
       // Auto-submit form when date range changes (except custom)
       document.getElementById('date-range').addEventListener('change', function() {
           if (this.value !== 'custom') {
               this.closest('form').submit();
           }
       });
   </script>
</body>
</html>