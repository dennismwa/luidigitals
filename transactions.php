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

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Filters
$typeFilter = $_GET['type'] ?? '';
$categoryFilter = intval($_GET['category'] ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = sanitizeInput($_GET['search'] ?? '');

// Build WHERE clause
$whereConditions = ["t.user_id = ?"];
$params = [$user_id];

if ($typeFilter && in_array($typeFilter, ['income', 'expense'])) {
    $whereConditions[] = "t.type = ?";
    $params[] = $typeFilter;
}

if ($categoryFilter > 0) {
    $whereConditions[] = "t.category_id = ?";
    $params[] = $categoryFilter;
}

if ($dateFrom) {
    $whereConditions[] = "DATE(t.transaction_date) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = "DATE(t.transaction_date) <= ?";
    $params[] = $dateTo;
}

if ($search) {
    $whereConditions[] = "(t.description LIKE ? OR t.reference_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
$totalQuery = "SELECT COUNT(*) as total FROM transactions t WHERE $whereClause";
$totalResult = $db->fetchOne($totalQuery, $params);
$totalRecords = $totalResult['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get transactions
$transactionsQuery = "
    SELECT t.*, c.name as category_name, c.icon as category_icon, c.color as category_color,
           b.name as bill_name
    FROM transactions t
    LEFT JOIN categories c ON t.category_id = c.id
    LEFT JOIN bills b ON t.bill_id = b.id
    WHERE $whereClause
    ORDER BY t.transaction_date DESC
    LIMIT $perPage OFFSET $offset
";
$transactions = $db->fetchAll($transactionsQuery, $params);

// Get categories for filter
$categories = $db->fetchAll(
    "SELECT id, name, icon, color FROM categories 
     WHERE (user_id = ? OR is_default = 1) 
     GROUP BY LOWER(name) 
     ORDER BY name",
    [$user_id]
);

// Calculate totals for current filter
$totalsQuery = "
    SELECT 
        SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END) as total_income,
        SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) as total_expenses
    FROM transactions t 
    WHERE $whereClause
";
$totals = $db->fetchOne($totalsQuery, $params);

$themeClass = $darkMode ? 'dark' : '';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - <?= APP_NAME ?></title>
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
        
        .transaction-row:hover { background-color: rgba(59, 130, 246, 0.05); }
        .dark .transaction-row:hover { background-color: rgba(59, 130, 246, 0.1); }
        
        .filter-card { transition: all 0.3s ease; }
        .filter-card:hover { transform: translateY(-2px); }
        
        .pagination-btn { transition: all 0.2s ease; }
        .pagination-btn:hover { transform: scale(1.05); }
        
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
                <a href="transactions.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg gradient-primary text-white">
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
                <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg <?= $darkMode ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-100' ?> transition-colors">
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
                        <h1 class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Transactions</h1>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Track and manage all your financial transactions</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="exportTransactions()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-download mr-2"></i>Export
                    </button>
                    <button onclick="openAddModal()" class="gradient-primary text-white px-4 py-2 rounded-lg hover:opacity-90 transition-opacity">
                        <i class="fas fa-plus mr-2"></i>Add Transaction
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Summary Cards -->
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg filter-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Income</p>
                            <p class="text-2xl font-bold text-green-600"><?= formatMoney($totals['total_income'] ?? 0, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-arrow-down text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg filter-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Expenses</p>
                            <p class="text-2xl font-bold text-red-600"><?= formatMoney($totals['total_expenses'] ?? 0, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-arrow-up text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg filter-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <?php 
                            $netAmount = ($totals['total_income'] ?? 0) - ($totals['total_expenses'] ?? 0);
                            $netColor = $netAmount >= 0 ? 'text-green-600' : 'text-red-600';
                            ?>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Net Amount</p>
                            <p class="text-2xl font-bold <?= $netColor ?>"><?= formatMoney($netAmount, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-balance-scale text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg mb-6">
                <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Filters</h3>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Search</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                               class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" 
                               placeholder="Search transactions...">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Type</label>
                        <select name="type" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            <option value="">All Types</option>
                            <option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Income</option>
                            <option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Expense</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Category</label>
                        <select name="category" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>" <?= $categoryFilter == $category['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">From Date</label>
                        <input type="date" name="date_from" value="<?= $dateFrom ?>" 
                               class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">To Date</label>
                        <input type="date" name="date_to" value="<?= $dateTo ?>" 
                               class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                    </div>
                    
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-search mr-2"></i>Filter
                        </button>
                        <a href="transactions.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i>Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Transactions Table -->
            <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl shadow-lg overflow-hidden">
                <div class="px-6 py-4 border-b <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">
                            Transaction History (<?= number_format($totalRecords) ?> records)
                        </h3>
                        <div class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">
                            Page <?= $page ?> of <?= $totalPages ?>
                        </div>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="<?= $darkMode ? 'bg-gray-700' : 'bg-gray-50' ?>">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Method</th>
                                <th class="px-6 py-3 text-right text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-right text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Balance</th>
                                <th class="px-6 py-3 text-center text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y <?= $darkMode ? 'divide-gray-700' : 'divide-gray-200' ?>">
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                                        <i class="fas fa-exchange-alt text-4xl mb-4"></i>
                                        <p class="text-lg mb-2">No transactions found</p>
                                        <p>Try adjusting your filters or add your first transaction</p>
                                        <button onclick="openAddModal()" class="mt-4 text-blue-600 hover:text-blue-700 font-medium">Add Transaction</button>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr class="transaction-row">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-900' ?>"><?= formatDate($transaction['transaction_date'], 'M j, Y') ?></div>
                                            <div class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>"><?= formatDate($transaction['transaction_date'], 'g:i A') ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium <?= $darkMode ? 'text-white' : 'text-gray-900' ?>"><?= htmlspecialchars($transaction['description']) ?></div>
                                            <?php if ($transaction['bill_name']): ?>
                                                <div class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">Bill: <?= htmlspecialchars($transaction['bill_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($transaction['reference_number']): ?>
                                                <div class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">Ref: <?= htmlspecialchars($transaction['reference_number']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-8 h-8 rounded-lg flex items-center justify-center mr-3" style="background-color: <?= $transaction['category_color'] ?? '#6b7280' ?>;">
                                                    <i class="<?= $transaction['category_icon'] ?? 'fas fa-money-bill' ?> text-white text-sm"></i>
                                                </div>
                                                <div class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-900' ?>"><?= htmlspecialchars($transaction['category_name'] ?? 'Uncategorized') ?></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= 
                                                $transaction['payment_method'] === 'cash' ? 'bg-green-100 text-green-800' :
                                                ($transaction['payment_method'] === 'bank' ? 'bg-blue-100 text-blue-800' :
                                                ($transaction['payment_method'] === 'mobile_money' ? 'bg-purple-100 text-purple-800' : 'bg-yellow-100 text-yellow-800'))
                                            ?>">
                                                <i class="fas fa-<?= 
                                                    $transaction['payment_method'] === 'cash' ? 'money-bill' :
                                                    ($transaction['payment_method'] === 'bank' ? 'university' :
                                                    ($transaction['payment_method'] === 'mobile_money' ? 'mobile-alt' : 'credit-card'))
                                                ?> mr-1"></i>
                                                <?= ucfirst(str_replace('_', ' ', $transaction['payment_method'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <div class="text-sm font-semibold <?= $transaction['type'] === 'income' ? 'text-green-600' : 'text-red-600' ?>">
                                                <?= $transaction['type'] === 'income' ? '+' : '-' ?><?= formatMoney($transaction['amount'], $currency) ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <div class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-900' ?>"><?= formatMoney($transaction['balance_after'], $currency) ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <div class="flex items-center justify-center space-x-2">
                                                <button onclick="viewTransaction(<?= $transaction['id'] ?>)" class="text-blue-600 hover:text-blue-800 transition-colors" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button onclick="editTransaction(<?= $transaction['id'] ?>)" class="text-green-600 hover:text-green-800 transition-colors" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="deleteTransaction(<?= $transaction['id'] ?>)" class="text-red-600 hover:text-red-800 transition-colors" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="px-6 py-4 border-t <?= $darkMode ? 'border-gray-700' :'border-gray-200' ?>">
                       <div class="flex items-center justify-between">
                           <div class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">
                               Showing <?= ($page - 1) * $perPage + 1 ?> to <?= min($page * $perPage, $totalRecords) ?> of <?= number_format($totalRecords) ?> results
                           </div>
                           
                           <div class="flex items-center space-x-2">
                               <?php if ($page > 1): ?>
                                   <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                                      class="pagination-btn bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition-all">
                                       <i class="fas fa-chevron-left"></i>
                                   </a>
                               <?php endif; ?>
                               
                               <?php
                               $startPage = max(1, $page - 2);
                               $endPage = min($totalPages, $page + 2);
                               
                               for ($i = $startPage; $i <= $endPage; $i++):
                               ?>
                                   <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                                      class="pagination-btn px-3 py-2 rounded-lg transition-all <?= $i === $page ? 'bg-blue-600 text-white' : ($darkMode ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300') ?>">
                                       <?= $i ?>
                                   </a>
                               <?php endfor; ?>
                               
                               <?php if ($page < $totalPages): ?>
                                   <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                                      class="pagination-btn bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition-all">
                                       <i class="fas fa-chevron-right"></i>
                                   </a>
                               <?php endif; ?>
                           </div>
                       </div>
                   </div>
               <?php endif; ?>
           </div>
       </div>
   </main>
   
   <!-- Add/Edit Transaction Modal -->
   <div id="transaction-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
       <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
           <div class="flex items-center justify-between mb-6">
               <h3 id="transaction-modal-title" class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Add Transaction</h3>
               <button onclick="closeModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                   <i class="fas fa-times"></i>
               </button>
           </div>
           
           <form id="transaction-form" class="space-y-4">
               <input type="hidden" id="transaction-id">
               <input type="hidden" id="form-action" value="add">
               
               <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                   <div>
                       <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Type *</label>
                       <select id="transaction-type" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                           <option value="">Select Type</option>
                           <option value="income">Income</option>
                           <option value="expense">Expense</option>
                       </select>
                   </div>
                   
                   <div>
                       <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Amount *</label>
                       <input type="number" id="transaction-amount" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00" required>
                   </div>
               </div>
               
               <div>
                   <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Description *</label>
                   <input type="text" id="transaction-description" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="Transaction description" required>
               </div>
               
               <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                   <div>
                       <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Category *</label>
                       <select id="transaction-category" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                           <option value="">Select Category</option>
                           <?php foreach ($categories as $category): ?>
                               <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                           <?php endforeach; ?>
                       </select>
                   </div>
                   
                   <div>
                       <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Payment Method *</label>
                       <select id="transaction-payment-method" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                           <option value="cash">Cash</option>
                           <option value="bank">Bank</option>
                           <option value="mobile_money">Mobile Money</option>
                           <option value="card">Card</option>
                       </select>
                   </div>
               </div>
               
               <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                   <div>
                       <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Reference Number</label>
                       <input type="text" id="transaction-reference" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="Optional reference">
                   </div>
                   
                   <div>
                       <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Date</label>
                       <input type="datetime-local" id="transaction-date" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" value="<?= date('Y-m-d\TH:i') ?>">
                   </div>
               </div>
               
               <div>
                   <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Notes</label>
                   <textarea id="transaction-notes" rows="3" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="Additional notes (optional)"></textarea>
               </div>
               
               <div class="flex space-x-3 pt-4">
                   <button type="button" onclick="closeModal()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                       Cancel
                   </button>
                   <button type="submit" class="flex-1 gradient-primary text-white py-2 px-4 rounded-lg hover:opacity-90 transition-opacity">
                       <span id="submit-text">Add Transaction</span>
                   </button>
               </div>
           </form>
       </div>
   </div>
   
   <!-- View Transaction Modal -->
   <div id="view-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
       <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-lg">
           <div class="flex items-center justify-between mb-6">
               <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Transaction Details</h3>
               <button onclick="closeViewModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                   <i class="fas fa-times"></i>
               </button>
           </div>
           
           <div id="transaction-details" class="space-y-4">
               <!-- Transaction details will be populated here -->
           </div>
       </div>
   </div>
   
   <script>
       let isEditMode = false;
       
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
       
       function openAddModal() {
           isEditMode = false;
           document.getElementById('transaction-modal-title').textContent = 'Add Transaction';
           document.getElementById('submit-text').textContent = 'Add Transaction';
           document.getElementById('form-action').value = 'add';
           document.getElementById('transaction-form').reset();
           document.getElementById('transaction-id').value = '';
           document.getElementById('transaction-date').value = new Date().toISOString().slice(0, 16);
           document.getElementById('transaction-modal').classList.remove('hidden');
       }
       
       function closeModal() {
           document.getElementById('transaction-modal').classList.add('hidden');
       }
       
       function closeViewModal() {
           document.getElementById('view-modal').classList.add('hidden');
       }
       
       function viewTransaction(id) {
           fetch(`ajax/get_transaction.php?id=${id}`)
               .then(response => response.json())
               .then(data => {
                   if (data.success) {
                       const transaction = data.transaction;
                       const detailsContainer = document.getElementById('transaction-details');
                       
                       detailsContainer.innerHTML = `
                           <div class="grid grid-cols-2 gap-4">
                               <div>
                                   <label class="text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Type</label>
                                   <p class="text-sm ${transaction.type === 'income' ? 'text-green-600' : 'text-red-600'} font-semibold">${transaction.type.toUpperCase()}</p>
                               </div>
                               <div>
                                   <label class="text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Amount</label>
                                   <p class="text-sm font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= $currency ?> ${parseFloat(transaction.amount).toFixed(2)}</p>
                               </div>
                               <div class="col-span-2">
                                   <label class="text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Description</label>
                                   <p class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">${transaction.description}</p>
                               </div>
                               <div>
                                   <label class="text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Payment Method</label>
                                   <p class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">${transaction.payment_method.replace('_', ' ')}</p>
                               </div>
                               <div>
                                   <label class="text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Date</label>
                                   <p class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">${new Date(transaction.transaction_date).toLocaleString()}</p>
                               </div>
                               ${transaction.reference_number ? `
                               <div class="col-span-2">
                                   <label class="text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Reference</label>
                                   <p class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">${transaction.reference_number}</p>
                               </div>
                               ` : ''}
                               ${transaction.notes ? `
                               <div class="col-span-2">
                                   <label class="text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Notes</label>
                                   <p class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">${transaction.notes}</p>
                               </div>
                               ` : ''}
                           </div>
                       `;
                       
                       document.getElementById('view-modal').classList.remove('hidden');
                   } else {
                       showNotification(data.message || 'Error loading transaction', 'error');
                   }
               })
               .catch(error => {
                   showNotification('Network error. Please try again.', 'error');
               });
       }
       
       function editTransaction(id) {
           fetch(`ajax/get_transaction.php?id=${id}`)
               .then(response => response.json())
               .then(data => {
                   if (data.success) {
                       const transaction = data.transaction;
                       isEditMode = true;
                       
                       document.getElementById('transaction-modal-title').textContent = 'Edit Transaction';
                       document.getElementById('submit-text').textContent = 'Update Transaction';
                       document.getElementById('form-action').value = 'edit';
                       document.getElementById('transaction-id').value = transaction.id;
                       
                       document.getElementById('transaction-type').value = transaction.type;
                       document.getElementById('transaction-amount').value = transaction.amount;
                       document.getElementById('transaction-description').value = transaction.description;
                       document.getElementById('transaction-category').value = transaction.category_id;
                       document.getElementById('transaction-payment-method').value = transaction.payment_method;
                       document.getElementById('transaction-reference').value = transaction.reference_number || '';
                       document.getElementById('transaction-notes').value = transaction.notes || '';
                       
                       // Format date for datetime-local input
                       const date = new Date(transaction.transaction_date);
                       document.getElementById('transaction-date').value = date.toISOString().slice(0, 16);
                       
                       document.getElementById('transaction-modal').classList.remove('hidden');
                   } else {
                       showNotification(data.message || 'Error loading transaction', 'error');
                   }
               })
               .catch(error => {
                   showNotification('Network error. Please try again.', 'error');
               });
       }
       
       function deleteTransaction(id) {
           if (confirm('Are you sure you want to delete this transaction? This action cannot be undone.')) {
               fetch('ajax/delete_transaction.php', {
                   method: 'POST',
                   headers: {
                       'Content-Type': 'application/json',
                   },
                   body: JSON.stringify({ id: id })
               })
               .then(response => response.json())
               .then(result => {
                   if (result.success) {
                       showNotification('Transaction deleted successfully!', 'success');
                       setTimeout(() => location.reload(), 1000);
                   } else {
                       showNotification(result.message || 'Error deleting transaction', 'error');
                   }
               })
               .catch(error => {
                   showNotification('Network error. Please try again.', 'error');
               });
           }
       }
       
       function exportTransactions() {
           const params = new URLSearchParams(window.location.search);
           params.set('export', 'csv');
           window.location.href = 'ajax/export_transactions.php?' + params.toString();
       }
       
       // Form submission
       document.getElementById('transaction-form').addEventListener('submit', async (e) => {
           e.preventDefault();
           
           const formData = new FormData();
           const action = document.getElementById('form-action').value;
           
           if (action === 'edit') {
               formData.append('id', document.getElementById('transaction-id').value);
           }
           
           formData.append('type', document.getElementById('transaction-type').value);
           formData.append('amount', document.getElementById('transaction-amount').value);
           formData.append('description', document.getElementById('transaction-description').value);
           formData.append('category_id', document.getElementById('transaction-category').value);
           formData.append('payment_method', document.getElementById('transaction-payment-method').value);
           formData.append('reference_number', document.getElementById('transaction-reference').value);
           formData.append('notes', document.getElementById('transaction-notes').value);
           
           const endpoint = action === 'edit' ? 'ajax/update_transaction.php' : 'ajax/add_transaction.php';
           
           try {
               const response = await fetch(endpoint, {
                   method: 'POST',
                   body: formData
               });
               
               const result = await response.json();
               
               if (result.success) {
                   closeModal();
                   showNotification(action === 'edit' ? 'Transaction updated successfully!' : 'Transaction added successfully!', 'success');
                   setTimeout(() => location.reload(), 1000);
               } else {
                   showNotification(result.message || 'Error saving transaction', 'error');
               }
           } catch (error) {
               showNotification('Network error. Please try again.', 'error');
           }
       });
       
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
       
       // Auto-submit filters
       document.querySelectorAll('select[name="type"], select[name="category"]').forEach(select => {
           select.addEventListener('change', () => {
               if (select.value !== '') {
                   select.closest('form').submit();
               }
           });
       });
       
       // Keyboard shortcuts
       document.addEventListener('keydown', (e) => {
           if (e.ctrlKey || e.metaKey) {
               if (e.key === 'n') {
                   e.preventDefault();
                   openAddModal();
               }
           }
           
           if (e.key === 'Escape') {
               closeModal();
               closeViewModal();
           }
       });
       
       // Touch gestures for mobile sidebar
       let touchStartX = 0;
       
       document.addEventListener('touchstart', (e) => {
           touchStartX = e.touches[0].clientX;
       });
       
       document.addEventListener('touchend', (e) => {
           const touchEndX = e.changedTouches[0].clientX;
           const diffX = touchStartX - touchEndX;
           
           if (Math.abs(diffX) > 100) {
               if (diffX > 0 && touchStartX < 50) {
                   sidebar.classList.remove('-translate-x-full');
                   mobileOverlay.classList.remove('hidden');
               } else if (diffX < 0 && !sidebar.classList.contains('-translate-x-full')) {
                   sidebar.classList.add('-translate-x-full');
                   mobileOverlay.classList.add('hidden');
               }
           }
       });
   </script>
</body>
</html>