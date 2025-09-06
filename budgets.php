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

// Handle form submissions
if ($_POST) {
    if (!validateCSRFToken($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'add_budget') {
                $name = sanitizeInput($_POST['name']);
                $allocated_amount = floatval($_POST['allocated_amount']);
                $category_id = intval($_POST['category_id']);
                $period_start = $_POST['period_start'];
                $period_end = $_POST['period_end'];
                $alert_threshold = floatval($_POST['alert_threshold']);
                
                if (empty($name) || $allocated_amount <= 0 || $category_id <= 0 || empty($period_start) || empty($period_end)) {
                    throw new Exception('Please fill in all required fields.');
                }
                
                if ($alert_threshold < 0 || $alert_threshold > 100) {
                    throw new Exception('Alert threshold must be between 0 and 100.');
                }
                
                $db->execute(
                    "INSERT INTO budgets (user_id, category_id, name, allocated_amount, period_start, period_end, alert_threshold) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$user_id, $category_id, $name, $allocated_amount, $period_start, $period_end, $alert_threshold]
                );
                
                $success = 'Budget created successfully!';
            } elseif ($action === 'update_budget') {
                $budget_id = intval($_POST['budget_id']);
                $name = sanitizeInput($_POST['name']);
                $allocated_amount = floatval($_POST['allocated_amount']);
                $category_id = intval($_POST['category_id']);
                $period_start = $_POST['period_start'];
                $period_end = $_POST['period_end'];
                $alert_threshold = floatval($_POST['alert_threshold']);
                
                $db->execute(
                    "UPDATE budgets SET name = ?, allocated_amount = ?, category_id = ?, 
                     period_start = ?, period_end = ?, alert_threshold = ?, updated_at = NOW()
                     WHERE id = ? AND user_id = ?",
                    [$name, $allocated_amount, $category_id, $period_start, $period_end, $alert_threshold, $budget_id, $user_id]
                );
                
                $success = 'Budget updated successfully!';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get budgets with spending data
$budgets = $db->fetchAll(
    "SELECT b.*, c.name as category_name, c.icon as category_icon, c.color as category_color,
     COALESCE((
         SELECT SUM(t.amount) 
         FROM transactions t 
         WHERE t.user_id = b.user_id 
         AND t.category_id = b.category_id 
         AND t.type = 'expense'
         AND t.transaction_date BETWEEN b.period_start AND b.period_end
     ), 0) as spent_amount
     FROM budgets b
     LEFT JOIN categories c ON b.category_id = c.id
     WHERE b.user_id = ?
     ORDER BY b.period_end DESC, b.created_at DESC",
    [$user_id]
);

// Update spent amounts in database
foreach ($budgets as $budget) {
    $db->execute(
        "UPDATE budgets SET spent_amount = ? WHERE id = ?",
        [$budget['spent_amount'], $budget['id']]
    );
}

// Get categories
$categories = $db->fetchAll(
    "SELECT id, name, icon, color FROM categories 
     WHERE (user_id = ? OR is_default = 1) 
     GROUP BY LOWER(name) 
     ORDER BY name",
    [$user_id]
);

// Calculate statistics
$totalAllocated = array_sum(array_column($budgets, 'allocated_amount'));
$totalSpent = array_sum(array_column($budgets, 'spent_amount'));
$activeBudgets = count(array_filter($budgets, function($budget) {
    return strtotime($budget['period_end']) >= time();
}));
$overBudget = count(array_filter($budgets, function($budget) {
    return $budget['spent_amount'] > $budget['allocated_amount'];
}));

$themeClass = $darkMode ? 'dark' : '';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budgets - <?= APP_NAME ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
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
        
        .budget-card { transition: all 0.3s ease; }
        .budget-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
        
        .progress-bar {
            transition: width 0.5s ease-in-out;
        }
        
        .budget-over { border-left: 4px solid #ef4444; }
        .budget-warning { border-left: 4px solid #f59e0b; }
        .budget-good { border-left: 4px solid #10b981; }
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
                    <div class="w-10 h-10 bg-gradient-to-r from-blue-600 to-blue-700 rounded-lg flex items-center justify-center">
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
                <a href="budgets.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg bg-gradient-to-r from-blue-600 to-blue-700 text-white">
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
            
            <div class="p-4 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-green-500 to-green-600 rounded-full flex items-center justify-center">
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
                        <h1 class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Budget Management</h1>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Track and manage your spending budgets</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="resetAllBudgets()" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-redo mr-2"></i>Reset All
                    </button>
                    <button onclick="openAddBudgetModal()" class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-4 py-2 rounded-lg hover:opacity-90 transition-opacity">
                        <i class="fas fa-plus mr-2"></i>Add Budget
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Alert Messages -->
        <div class="p-6">
            <?php if (isset($error)): ?>
                <div class="bg-red-500 bg-opacity-20 border border-red-400 text-red-100 px-4 py-3 rounded-lg mb-6">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?= $error ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="bg-green-500 bg-opacity-20 border border-green-400 text-green-100 px-4 py-3 rounded-lg mb-6">
                    <i class="fas fa-check-circle mr-2"></i><?= $success ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Allocated</p>
                            <p class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatMoney($totalAllocated, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-wallet text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Spent</p>
                            <p class="text-2xl font-bold text-red-600"><?= formatMoney($totalSpent, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-arrow-up text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Active Budgets</p>
                            <p class="text-2xl font-bold text-green-600"><?= $activeBudgets ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-pie text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Over Budget</p>
                            <p class="text-2xl font-bold text-red-600"><?= $overBudget ?></p>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Budgets Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (empty($budgets)): ?>
                    <div class="col-span-full text-center py-12 <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                        <i class="fas fa-chart-pie text-4xl mb-4"></i>
                        <p class="text-lg mb-2">No budgets found</p>
                        <p>Create your first budget to start tracking your spending</p>
                        <button onclick="openAddBudgetModal()" class="mt-4 text-blue-600 hover:text-blue-700 font-medium">Create Budget</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($budgets as $budget): ?>
                        <?php
                        $percentage = $budget['allocated_amount'] > 0 ? min(100, ($budget['spent_amount'] / $budget['allocated_amount']) * 100) : 0;
                        $remaining = $budget['allocated_amount'] - $budget['spent_amount'];
                        $isExpired = strtotime($budget['period_end']) < time();
                        $isOverBudget = $budget['spent_amount'] > $budget['allocated_amount'];
                        $isWarning = $percentage >= $budget['alert_threshold'] && !$isOverBudget;
                        
                        $statusClass = $isOverBudget ? 'budget-over' : ($isWarning ? 'budget-warning' : 'budget-good');
                        $progressColor = $isOverBudget ? 'bg-red-500' : ($isWarning ? 'bg-yellow-500' : 'bg-green-500');
                        ?>
                        <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg budget-card <?= $statusClass ?>">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: <?= $budget['category_color'] ?? '#6b7280' ?>;">
                                        <i class="<?= $budget['category_icon'] ?? 'fas fa-chart-pie' ?> text-white text-lg"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= htmlspecialchars($budget['name']) ?></h3>
                                        <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= $budget['category_name'] ?></p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button onclick="editBudget(<?= $budget['id'] ?>)" class="text-blue-600 hover:text-blue-800 transition-colors">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteBudget(<?= $budget['id'] ?>)" class="text-red-600 hover:text-red-800 transition-colors">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <div class="mb-4">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Progress</span>
                                    <span class="text-sm font-medium <?= $isOverBudget ? 'text-red-600' : ($isWarning ? 'text-yellow-600' : 'text-green-600') ?>"><?= number_format($percentage, 1) ?>%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-3">
                                    <div class="<?= $progressColor ?> h-3 rounded-full progress-bar" style="width: <?= min(100, $percentage) ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Allocated</span>
                                    <span class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatMoney($budget['allocated_amount'], $currency) ?></span>
                                </div>
                                
                                <div class="flex justify-between items-center">
                                    <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Spent</span>
                                    <span class="font-semibold text-red-600"><?= formatMoney($budget['spent_amount'], $currency) ?></span>
                                </div>
                                
                                <div class="flex justify-between items-center">
                                    <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Remaining</span>
                                    <span class="font-semibold <?= $remaining >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= formatMoney($remaining, $currency) ?></span>
                                </div>
                                
                                <div class="flex justify-between items-center">
                                    <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Period</span>
                                    <span class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">
                                        <?= formatDate($budget['period_start'], 'M j') ?> - <?= formatDate($budget['period_end'], 'M j, Y') ?>
                                    </span>
                                </div>
                                
                                <?php if ($isExpired): ?>
                                    <div class="pt-3 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            <i class="fas fa-calendar-times mr-1"></i>Expired
                                        </span>
                                    </div>
                                <?php elseif ($isOverBudget): ?>
                                    <div class="pt-3 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Over Budget
                                        </span>
                                    </div>
                                <?php elseif ($isWarning): ?>
                                    <div class="pt-3 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                            <i class="fas fa-exclamation-circle mr-1"></i>Warning: <?= $budget['alert_threshold'] ?>% reached
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <!-- Add/Edit Budget Modal -->
    <div id="budget-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-lg">
            <div class="flex items-center justify-between mb-6">
                <h3 id="budget-modal-title" class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Add Budget</h3>
                <button onclick="closeBudgetModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="budget-form" method="POST" class="space-y-4">
                <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" id="budget-action" value="add_budget">
                <input type="hidden" name="budget_id" id="budget-id">
                
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Budget Name *</label>
                    <input type="text" name="name" id="budget-name" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="e.g., Monthly Food Budget" required>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Allocated Amount *</label>
                        <input type="number" name="allocated_amount" id="budget-amount" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00" required>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Category *</label>
                        <select name="category_id" id="budget-category" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Period Start *</label>
                        <input type="date" name="period_start" id="budget-start" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Period End *</label>
                        <input type="date" name="period_end" id="budget-end" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Alert Threshold (%)</label>
                    <input type="number" name="alert_threshold" id="budget-threshold" min="0" max="100" value="80" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                    <p class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?> mt-1">Get notified when spending reaches this percentage</p>
                </div>
                
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="closeBudgetModal()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 bg-gradient-to-r from-blue-600 to-blue-700 text-white py-2 px-4 rounded-lg hover:opacity-90 transition-opacity">
                        <span id="budget-submit-text">Add Budget</span>
                    </button>
                </div>
            </form>
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
        
        function openAddBudgetModal() {
            isEditMode = false;
            document.getElementById('budget-modal-title').textContent = 'Add Budget';
            document.getElementById('budget-submit-text').textContent = 'Add Budget';
            document.getElementById('budget-action').value = 'add_budget';
            document.getElementById('budget-form').reset();
            document.getElementById('budget-id').value = '';
            
            // Set default dates
            const today = new Date();
            const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, today.getDate());
            document.getElementById('budget-start').value = today.toISOString().split('T')[0];
            document.getElementById('budget-end').value = nextMonth.toISOString().split('T')[0];
            
            document.getElementById('budget-modal').classList.remove('hidden');
        }
        
        function closeBudgetModal() {
            document.getElementById('budget-modal').classList.add('hidden');
        }
        
        function editBudget(id) {
            // Fetch budget data and populate form
            fetch(`ajax/get_budget.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const budget = data.budget;
                        isEditMode = true;
                        document.getElementById('budget-modal-title').textContent = 'Edit Budget';
                        document.getElementById('budget-submit-text').textContent = 'Update Budget';
                        document.getElementById('budget-action').value = 'update_budget';
                        document.getElementById('budget-id').value = budget.id;
                        
                        // Populate form fields
                        document.getElementById('budget-name').value = budget.name;
                        document.getElementById('budget-amount').value = budget.allocated_amount;
                        document.getElementById('budget-category').value = budget.category_id;
                        document.getElementById('budget-start').value = budget.period_start;
                        document.getElementById('budget-end').value = budget.period_end;
                        document.getElementById('budget-threshold').value = budget.alert_threshold;
                        
                        document.getElementById('budget-modal').classList.remove('hidden');
                    } else {
                        showNotification(data.message || 'Error loading budget', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Network error. Please try again.', 'error');
                });
        }
        
        function deleteBudget(id) {
            if (confirm('Are you sure you want to delete this budget? This action cannot be undone.')) {
                fetch('ajax/delete_budget.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: id })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showNotification('Budget deleted successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification(result.message || 'Error deleting budget', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Network error. Please try again.', 'error');
                });
            }
        }
        
        function resetAllBudgets() {
            if (confirm('Are you sure you want to reset all budget spending amounts to zero?')) {
                fetch('ajax/reset_budgets.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showNotification('All budgets reset successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification(result.message || 'Error resetting budgets', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Network error. Please try again.', 'error');
                });
            }
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
        
        // Form validation
        document.getElementById('budget-form').addEventListener('submit', function(e) {
            const startDate = new Date(document.getElementById('budget-start').value);
            const endDate = new Date(document.getElementById('budget-end').value);
            const amount = parseFloat(document.getElementById('budget-amount').value);
            
            if (amount <= 0) {
                e.preventDefault();
                showNotification('Amount must be greater than 0', 'error');
                return false;
            }
            
            if (endDate <= startDate) {
                e.preventDefault();
                showNotification('End date must be after start date', 'error');
                return false;
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                if (e.key === 'n') {
                    e.preventDefault();
                    openAddBudgetModal();
                }
            }
            
            if (e.key === 'Escape') {
                closeBudgetModal();
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