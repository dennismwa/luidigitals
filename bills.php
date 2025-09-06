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
            
            if ($action === 'add_bill') {
                $name = sanitizeInput($_POST['name']);
                $amount = floatval($_POST['amount']);
                $category_id = intval($_POST['category_id']);
                $due_date = $_POST['due_date'];
                $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
                $recurring_period = $_POST['recurring_period'] ?? 'monthly';
                $auto_pay = isset($_POST['auto_pay']) ? 1 : 0;
                $priority = $_POST['priority'] ?? 'medium';
                $threshold_warning = floatval($_POST['threshold_warning'] ?? 0);
                $notes = sanitizeInput($_POST['notes'] ?? '');
                
                if (empty($name) || $amount <= 0 || $category_id <= 0 || empty($due_date)) {
                    throw new Exception('Please fill in all required fields.');
                }
                
                // Verify category belongs to user or is default
                $category = $db->fetchOne(
                    "SELECT * FROM categories WHERE id = ? AND (user_id = ? OR is_default = 1)",
                    [$category_id, $user_id]
                );
                
                if (!$category) {
                    throw new Exception('Invalid category selected.');
                }
                
                // Check for duplicate bill (same name, amount, and due_date for same user)
                $existingBill = $db->fetchOne(
                    "SELECT id FROM bills WHERE user_id = ? AND name = ? AND amount = ? AND due_date = ? AND status = 'pending'",
                    [$user_id, $name, $amount, $due_date]
                );
                
                if ($existingBill) {
                    throw new Exception('A similar bill already exists with the same name, amount, and due date.');
                }
                
                $db->execute(
                    "INSERT INTO bills (user_id, category_id, name, amount, remaining_balance, due_date, is_recurring, recurring_period, auto_pay, priority, threshold_warning, notes) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$user_id, $category_id, $name, $amount, $amount, $due_date, $is_recurring, $recurring_period, $auto_pay, $priority, $threshold_warning, $notes]
                );
                
                // Create notification
                $db->execute(
                    "INSERT INTO notifications (user_id, title, message, type) 
                     VALUES (?, ?, ?, ?)",
                    [
                        $user_id,
                        'New Bill Added',
                        "Bill '{$name}' for " . formatMoney($amount, $currency) . " has been added.",
                        'info'
                    ]
                );
                
                $success = 'Bill added successfully!';
                
            } elseif ($action === 'update_bill') {
                $bill_id = intval($_POST['bill_id']);
                $name = sanitizeInput($_POST['name']);
                $amount = floatval($_POST['amount']);
                $category_id = intval($_POST['category_id']);
                $due_date = $_POST['due_date'];
                $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
                $recurring_period = $_POST['recurring_period'] ?? 'monthly';
                $auto_pay = isset($_POST['auto_pay']) ? 1 : 0;
                $priority = $_POST['priority'] ?? 'medium';
                $threshold_warning = floatval($_POST['threshold_warning'] ?? 0);
                $notes = sanitizeInput($_POST['notes'] ?? '');
                
                if (empty($name) || $amount <= 0 || $category_id <= 0 || empty($due_date)) {
                    throw new Exception('Please fill in all required fields.');
                }
                
                // Check if bill belongs to user
                $existingBill = $db->fetchOne(
                    "SELECT * FROM bills WHERE id = ? AND user_id = ?",
                    [$bill_id, $user_id]
                );
                
                if (!$existingBill) {
                    throw new Exception('Bill not found.');
                }
                
                // Check for duplicate bill (excluding current bill)
                $duplicateBill = $db->fetchOne(
                    "SELECT id FROM bills WHERE user_id = ? AND name = ? AND amount = ? AND due_date = ? AND status = 'pending' AND id != ?",
                    [$user_id, $name, $amount, $due_date, $bill_id]
                );
                
                if ($duplicateBill) {
                    throw new Exception('A similar bill already exists with the same name, amount, and due date.');
                }
                
                $db->execute(
                    "UPDATE bills SET name = ?, amount = ?, category_id = ?, due_date = ?, 
                     is_recurring = ?, recurring_period = ?, auto_pay = ?, priority = ?, threshold_warning = ?, notes = ?, updated_at = NOW()
                     WHERE id = ? AND user_id = ?",
                    [$name, $amount, $category_id, $due_date, $is_recurring, $recurring_period, $auto_pay, $priority, $threshold_warning, $notes, $bill_id, $user_id]
                );
                
                $success = 'Bill updated successfully!';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get bills with filters
$status_filter = $_GET['status'] ?? '';
$category_filter = intval($_GET['category'] ?? 0);
$priority_filter = $_GET['priority'] ?? '';

$whereConditions = ["b.user_id = ?"];
$params = [$user_id];

if ($status_filter && in_array($status_filter, ['pending', 'paid', 'overdue', 'partial'])) {
    $whereConditions[] = "b.status = ?";
    $params[] = $status_filter;
}

if ($category_filter > 0) {
    $whereConditions[] = "b.category_id = ?";
    $params[] = $category_filter;
}

if ($priority_filter && in_array($priority_filter, ['low', 'medium', 'high'])) {
    $whereConditions[] = "b.priority = ?";
    $params[] = $priority_filter;
}

$whereClause = implode(' AND ', $whereConditions);

// Update overdue bills
$db->execute(
    "UPDATE bills SET status = 'overdue' WHERE status = 'pending' AND due_date < CURDATE() AND user_id = ?",
    [$user_id]
);

$bills = $db->fetchAll(
    "SELECT b.*, c.name as category_name, c.icon as category_icon, c.color as category_color
     FROM bills b
     LEFT JOIN categories c ON b.category_id = c.id
     WHERE $whereClause
     ORDER BY 
       CASE WHEN b.status = 'overdue' THEN 1
            WHEN b.status = 'pending' THEN 2
            WHEN b.status = 'partial' THEN 3
            ELSE 4 END,
       b.due_date ASC",
    $params
);

// Get categories (remove duplicates by grouping by name)
$categories = $db->fetchAll(
    "SELECT id, name, icon, color FROM categories 
     WHERE (user_id = ? OR is_default = 1) 
     GROUP BY name 
     ORDER BY name",
    [$user_id]
);

// Statistics
$stats = $db->fetchOne(
    "SELECT 
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'partial' THEN 1 END) as partial_count,
        COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount,
        SUM(CASE WHEN status = 'partial' THEN remaining_balance ELSE 0 END) as partial_amount,
        SUM(CASE WHEN status = 'overdue' THEN amount ELSE 0 END) as overdue_amount,
        SUM(CASE WHEN status = 'paid' AND DATE_FORMAT(updated_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m') THEN amount ELSE 0 END) as paid_this_month
     FROM bills WHERE user_id = ?",
    [$user_id]
);

$themeClass = $darkMode ? 'dark' : '';
?>

<!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bills - <?= APP_NAME ?></title>
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
        
        .bill-card { transition: all 0.3s ease; }
        .bill-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
        .bill-overdue { border-left: 4px solid #ef4444; }
        .bill-pending { border-left: 4px solid #f59e0b; }
        .bill-paid { border-left: 4px solid #10b981; }
        .bill-partial { border-left: 4px solid #f97316; }
        
        .gradient-primary { background: linear-gradient(135deg, <?= PRIMARY_COLOR ?> 0%, #1e40af 100%); }
        .gradient-success { background: linear-gradient(135deg, <?= SUCCESS_COLOR ?> 0%, #10b981 100%); }
        .gradient-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .gradient-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        
        .notification-badge {
            animation: bounce 1s infinite;
        }
        /* Add to existing styles */
.dark .bg-gray-800 { background-color: #1e293b !important; }
.dark input, .dark select, .dark textarea { 
    background-color: #374151 !important; 
    border-color: #4b5563 !important;
    color: #f9fafb !important;
}
.dark input:focus, .dark select:focus, .dark textarea:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}
        
        @keyframes bounce {
            0%, 20%, 53%, 80%, 100% { transform: translate3d(0,0,0); }
            40%, 43% { transform: translate3d(0, -10px, 0); }
            70% { transform: translate3d(0, -5px, 0); }
            90% { transform: translate3d(0, -2px, 0); }
        }
        
        @media (max-width: 768px) {
            .sidebar-mobile {
                transform: translateX(-100%);
            }
            .sidebar-mobile.open {
                transform: translateX(0);
            }
        }
        
        @media (max-width: 768px) {
    .sidebar-mobile {
        transform: translateX(-100%);
        transition: transform 0.3s ease-in-out;
    }
    .sidebar-mobile.open {
        transform: translateX(0);
    }
    
    /* Add this */
    #sidebar {
        transition: transform 0.3s ease-in-out;
    }
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
                <a href="bills.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg gradient-primary text-white">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Bills</span>
                    <?php if ($stats['pending_count'] + $stats['overdue_count'] + $stats['partial_count'] > 0): ?>
                        <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full notification-badge"><?= $stats['pending_count'] + $stats['overdue_count'] + $stats['partial_count'] ?></span>
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
                        <h1 class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Bills Management</h1>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Manage and track all your bills and payments</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="payAllDue()" class="gradient-warning text-white px-4 py-2 rounded-lg hover:opacity-90 transition-opacity">
                        <i class="fas fa-credit-card mr-2"></i>Pay All Due
                    </button>
                    <button onclick="openAddBillModal()" class="gradient-primary text-white px-4 py-2 rounded-lg hover:opacity-90 transition-opacity">
                        <i class="fas fa-plus mr-2"></i>Add Bill
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
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg bill-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Pending Bills</p>
                            <p class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= $stats['pending_count'] ?></p>
                            <p class="text-sm text-yellow-600"><?= formatMoney($stats['pending_amount'], $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg bill-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Partial Payments</p>
                            <p class="text-2xl font-bold text-orange-600"><?= $stats['partial_count'] ?></p>
                            <p class="text-sm text-orange-600"><?= formatMoney($stats['partial_amount'], $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-coins text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg bill-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Overdue Bills</p>
                            <p class="text-2xl font-bold text-red-600"><?= $stats['overdue_count'] ?></p>
                            <p class="text-sm text-red-600"><?= formatMoney($stats['overdue_amount'], $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg bill-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Paid This Month</p>
                            <p class="text-2xl font-bold text-green-600"><?= $stats['paid_count'] ?></p>
                            <p class="text-sm text-green-600"><?= formatMoney($stats['paid_this_month'], $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg bill-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Bills</p>
                            <p class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= count($bills) ?></p>
                            <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">All time</p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-invoice text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg mb-6">
                <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Filters</h3>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Status</label>
                        <select name="status" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            <option value="">All Status</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Partial</option>
                            <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                            <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Category</label>
                        <select name="category" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>" <?= $category_filter == $category['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Priority</label>
                        <select name="priority" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            <option value="">All Priorities</option>
                            <option value="low" <?= $priority_filter === 'low' ? 'selected' : '' ?>>Low</option>
                            <option value="medium" <?= $priority_filter === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="high" <?= $priority_filter === 'high' ? 'selected' : '' ?>>High</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-search mr-2"></i>Filter
                        </button>
                        <a href="bills.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-times mr-2"></i> 
</a>
</div>
</form>
</div>



        <!-- Bills Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($bills)): ?>
                <div class="col-span-full text-center py-12 <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                    <i class="fas fa-file-invoice text-4xl mb-4"></i>
                    <p class="text-lg mb-2">No bills found</p>
                    <p>Add your first bill to get started</p>
                    <button onclick="openAddBillModal()" class="mt-4 text-blue-600 hover:text-blue-700 font-medium">Add Bill</button>
                </div>
            <?php else: ?>
                <?php foreach ($bills as $bill): ?>
                    <?php
                    $daysUntilDue = (strtotime($bill['due_date']) - time()) / (60 * 60 * 24);
                    $isExpired = strtotime($bill['due_date']) < time();
                    $isOverBudget = $bill['status'] === 'overdue';
                    $isWarning = $daysUntilDue <= 7 && $bill['status'] !== 'paid';
                    
                    $statusClass = $isOverBudget ? 'bill-overdue' : 
                                  ($isWarning ? 'bill-pending' : 'bill-good');
                    $progressColor = $isOverBudget ? 'bg-red-500' : ($isWarning ? 'bg-yellow-500' : 'bg-green-500');
                    ?>
                    <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg bill-card <?= $statusClass ?>">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: <?= $bill['category_color'] ?? '#6b7280' ?>;">
                                    <i class="<?= $bill['category_icon'] ?? 'fas fa-file-invoice' ?> text-white text-lg"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= htmlspecialchars($bill['name']) ?></h3>
                                    <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= $bill['category_name'] ?></p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <button onclick="editBill(<?= $bill['id'] ?>)" class="text-blue-600 hover:text-blue-800 transition-colors" title="Edit">
                                    <i class="fas fa-edit"></i>
                               </button>
                               <button onclick="deleteBill(<?= $bill['id'] ?>)" class="text-red-600 hover:text-red-800 transition-colors" title="Delete">
                                   <i class="fas fa-trash"></i>
                               </button>
                           </div>
                       </div>
                       
                       <div class="space-y-3">
                           <div class="flex justify-between items-center">
                               <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Amount</span>
                               <div class="text-right">
                                   <span class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatMoney($bill['amount'], $currency) ?></span>
                                   <?php if (isset($bill['remaining_balance']) && $bill['remaining_balance'] < $bill['amount'] && $bill['remaining_balance'] > 0): ?>
                                       <p class="text-xs text-orange-600">Remaining: <?= formatMoney($bill['remaining_balance'], $currency) ?></p>
                                   <?php endif; ?>
                               </div>
                           </div>
                           
                           <div class="flex justify-between items-center">
                               <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Due Date</span>
                               <span class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatDate($bill['due_date'], 'M j, Y') ?></span>
                           </div>
                           
                           <div class="flex justify-between items-center">
                               <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Status</span>
                               <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= 
                                   $bill['status'] === 'overdue' ? 'bg-red-100 text-red-800' :
                                   ($bill['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                   ($bill['status'] === 'partial' ? 'bg-orange-100 text-orange-800' : 'bg-green-100 text-green-800'))
                               ?>">
                                   <i class="fas fa-<?= 
                                       $bill['status'] === 'overdue' ? 'exclamation-triangle' :
                                       ($bill['status'] === 'pending' ? 'clock' : 
                                       ($bill['status'] === 'partial' ? 'coins' : 'check-circle'))
                                   ?> mr-1"></i>
                                   <?= ucfirst($bill['status']) ?>
                               </span>
                           </div>
                           
                           <?php if ($bill['priority'] !== 'medium'): ?>
                               <div class="flex justify-between items-center">
                                   <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Priority</span>
                                   <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= 
                                       $bill['priority'] === 'high' ? 'bg-red-100 text-red-800' :
                                       ($bill['priority'] === 'low' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800')
                                   ?>">
                                       <i class="fas fa-<?= 
                                           $bill['priority'] === 'high' ? 'exclamation' :
                                           ($bill['priority'] === 'low' ? 'arrow-down' : 'minus')
                                       ?> mr-1"></i>
                                       <?= ucfirst($bill['priority']) ?>
                                   </span>
                               </div>
                           <?php endif; ?>
                           
                           <?php if ($bill['is_recurring']): ?>
                               <div class="flex justify-between items-center">
                                   <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Recurring</span>
                                   <span class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">
                                       <i class="fas fa-repeat mr-1"></i><?= ucfirst(str_replace('_', ' ', $bill['recurring_period'])) ?>
                                   </span>
                               </div>
                           <?php endif; ?>
                           
                           <?php if ($bill['auto_pay']): ?>
                               <div class="flex justify-between items-center">
                                   <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Auto Pay</span>
                                   <span class="text-sm text-green-600">
                                       <i class="fas fa-check-circle mr-1"></i>Enabled
                                   </span>
                               </div>
                           <?php endif; ?>
                           
                           <?php if ($daysUntilDue <= 7 && $bill['status'] !== 'paid'): ?>
                               <div class="mt-3 pt-3 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                   <div class="flex items-center text-sm <?= $daysUntilDue <= 3 ? 'text-red-600' : 'text-yellow-600' ?>">
                                       <i class="fas fa-clock mr-2"></i>
                                       <?php if ($daysUntilDue < 0): ?>
                                           Overdue by <?= abs(round($daysUntilDue)) ?> days
                                       <?php elseif ($daysUntilDue == 0): ?>
                                           Due today
                                       <?php else: ?>
                                           Due in <?= round($daysUntilDue) ?> days
                                       <?php endif; ?>
                                   </div>
                               </div>
                           <?php endif; ?>
                           
                           <?php if ($bill['notes']): ?>
                               <div class="mt-3 pt-3 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                   <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= htmlspecialchars($bill['notes']) ?></p>
                               </div>
                           <?php endif; ?>
                       </div>
                       
                       <?php if ($bill['status'] === 'pending' || $bill['status'] === 'overdue' || $bill['status'] === 'partial'): ?>
                           <div class="mt-4 pt-4 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                               <div class="grid grid-cols-2 gap-2">
                                   <button onclick="payBillFull(<?= $bill['id'] ?>)" class="gradient-success hover:opacity-90 text-white py-2 px-3 rounded-lg transition-all text-sm">
                                       <i class="fas fa-credit-card mr-1"></i>Pay Full
                                   </button>
                                   <button onclick="payBillPartial(<?= $bill['id'] ?>)" class="bg-blue-500 hover:bg-blue-600 text-white py-2 px-3 rounded-lg transition-all text-sm">
                                       <i class="fas fa-coins mr-1"></i>Pay Part
                                   </button>
                               </div>
                           </div>
                       <?php endif; ?>
                   </div>
               <?php endforeach; ?>
           <?php endif; ?>
       </div>
   </div>
   </main>
   <!-- Partial Payment Modal -->
   <div id="payment-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
       <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-md">
           <div class="flex items-center justify-between mb-6">
               <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Make Payment</h3>
               <button onclick="closePaymentModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                   <i class="fas fa-times"></i>
               </button>
           </div>
       <div id="payment-details" class="mb-4">
           <!-- Payment details will be populated here -->
       </div>
       
       <div class="space-y-4">
           <div>
               <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Payment Amount</label>
               <input type="number" id="payment-amount" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00" required>
               <p class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?> mt-1">Enter the amount you want to pay</p>
           </div>
           
           <div class="flex space-x-3">
               <button onclick="closePaymentModal()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                   Cancel
               </button>
               <button onclick="processPartialPayment()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-lg transition-colors">
                   <i class="fas fa-credit-card mr-2"></i>Pay Now
               </button>
           </div>
       </div>
   </div>
   </div>
   <!-- Add/Edit Bill Modal -->
   <div id="bill-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
       <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
           <div class="flex items-center justify-between mb-6">
               <h3 id="bill-modal-title" class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Add Bill</h3>
               <button onclick="closeBillModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                   <i class="fas fa-times"></i>
               </button>
           </div>
       <form id="bill-form" method="POST" class="space-y-4">
           <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
           <input type="hidden" name="action" id="bill-action" value="add_bill">
           <input type="hidden" name="bill_id" id="bill-id">
           
           <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
               <div>
                   <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Bill Name *</label>
                   <input type="text" name="name" id="bill-name" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="e.g., Electricity Bill" required>
               </div>
               
               <div>
                   <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Amount *</label>
                   <input type="number" name="amount" id="bill-amount" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00" required>
               </div>
           </div>
           
           <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
               <div>
                   <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Category *</label>
                   <select name="category_id" id="bill-category" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                       <option value="">Select Category</option>
                       <?php foreach ($categories as $category): ?>
                           <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                       <?php endforeach; ?>
                   </select>
               </div>
               
               <div>
                   <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Due Date *</label>
                   <input type="date" name="due_date" id="bill-due-date" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
               </div>
           </div>
           
           <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
               <div>
                   <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Priority</label>
                   <select name="priority" id="bill-priority" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                       <option value="low">Low</option>
                       <option value="medium" selected>Medium</option>
                       <option value="high">High</option>
                   </select>
               </div>
               
               <div>
                   <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Recurring Period</label>
                   <select name="recurring_period" id="bill-recurring-period" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                       <option value="weekly">Weekly</option>
                       <option value="monthly" selected>Monthly</option>
                       <option value="quarterly">Quarterly</option>
                       <option value="yearly">Yearly</option>
                   </select>
               </div>
               
               <div>
                   <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Warning Threshold</label>
                   <input type="number" name="threshold_warning" id="bill-threshold" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00">
               </div>
           </div>
           
           <div class="flex space-x-6">
               <label class="flex items-center">
                   <input type="checkbox" name="is_recurring" id="bill-is-recurring" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                   <span class="ml-2 text-sm <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Recurring Bill</span>
               </label>
               
               <label class="flex items-center">
                   <input type="checkbox" name="auto_pay" id="bill-auto-pay" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                   <span class="ml-2 text-sm <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">Auto Pay</span>
               </label>
           </div>
           
           <div>
               <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Notes</label>
               <textarea name="notes" id="bill-notes" rows="3" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="Additional notes (optional)"></textarea>
           </div>
           
           <div class="flex space-x-3 pt-4">
               <button type="button" onclick="closeBillModal()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                   Cancel
               </button>
               <button type="submit" class="flex-1 gradient-primary text-white py-2 px-4 rounded-lg hover:opacity-90 transition-opacity">
                   <span id="bill-submit-text">Add Bill</span>
               </button>
           </div>
       </form>
   </div>
   </div>
   <script>
    let isEditMode = false;
    
    // Wait for DOM to be fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        initializeBillsPage();
    });
    
    function initializeBillsPage() {
        // Mobile menu functionality
        const menuToggle = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar');
        const mobileOverlay = document.getElementById('mobile-overlay');
        const closeSidebar = document.getElementById('close-sidebar');
        
        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.remove('-translate-x-full');
                mobileOverlay.classList.remove('hidden');
            });
        }
        
        if (closeSidebar) {
            closeSidebar.addEventListener('click', () => {
                sidebar.classList.add('-translate-x-full');
                mobileOverlay.classList.add('hidden');
            });
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                sidebar.classList.add('-translate-x-full');
                mobileOverlay.classList.add('hidden');
            });
        }
        
        // Set minimum date to today
        const billDueDate = document.getElementById('bill-due-date');
        if (billDueDate) {
            billDueDate.min = new Date().toISOString().split('T')[0];
        }
        
        // Enable/disable recurring period based on recurring checkbox
        const recurringCheckbox = document.getElementById('bill-is-recurring');
        const recurringPeriod = document.getElementById('bill-recurring-period');
        
        if (recurringCheckbox && recurringPeriod) {
            recurringCheckbox.addEventListener('change', function() {
                recurringPeriod.disabled = !this.checked;
                if (this.checked) {
                    recurringPeriod.classList.remove('opacity-50');
                } else {
                    recurringPeriod.classList.add('opacity-50');
                }
            });
            
            // Initialize state
            if (!recurringCheckbox.checked) {
                recurringPeriod.disabled = true;
                recurringPeriod.classList.add('opacity-50');
            }
        }
        
        // Auto-submit filter form on change
        document.querySelectorAll('select[name="status"], select[name="category"], select[name="priority"]').forEach(select => {
            select.addEventListener('change', () => {
                if (select.value !== '') {
                    select.closest('form').submit();
                }
            });
        });
        
        // Form validation
        const billForm = document.getElementById('bill-form');
        if (billForm) {
            billForm.addEventListener('submit', function(e) {
                const amount = parseFloat(document.getElementById('bill-amount').value);
                const dueDate = new Date(document.getElementById('bill-due-date').value);
                const today = new Date();
                
                if (amount <= 0) {
                    e.preventDefault();
                    showNotification('Amount must be greater than 0', 'error');
                    return false;
                }
                
                if (dueDate < today.setHours(0,0,0,0)) {
                    if (!confirm('The due date is in the past. Are you sure you want to continue?')) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                // Disable submit button to prevent double submission
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
                    
                    // Re-enable after 3 seconds as fallback
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<span id="bill-submit-text">' + (isEditMode ? 'Update Bill' : 'Add Bill') + '</span>';
                    }, 3000);
                }
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                if (e.key === 'n') {
                    e.preventDefault();
                    openAddBillModal();
                }
            }
            
            if (e.key === 'Escape') {
                closeBillModal();
                closePaymentModal();
            }
        });
    }
    
    // Global functions that need to be accessible from HTML onclick attributes
    window.openAddBillModal = function() {
        isEditMode = false;
        document.getElementById('bill-modal-title').textContent = 'Add Bill';
        document.getElementById('bill-submit-text').textContent = 'Add Bill';
        document.getElementById('bill-action').value = 'add_bill';
        document.getElementById('bill-form').reset();
        document.getElementById('bill-id').value = '';
        
        // Set default due date to next month
        const nextMonth = new Date();
        nextMonth.setMonth(nextMonth.getMonth() + 1);
        document.getElementById('bill-due-date').value = nextMonth.toISOString().split('T')[0];
        
        document.getElementById('bill-modal').classList.remove('hidden');
    };
    
    window.closeBillModal = function() {
        document.getElementById('bill-modal').classList.add('hidden');
    };
    
    window.editBill = function(id) {
        fetch(`ajax/get_bill.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const bill = data.bill;
                    isEditMode = true;
                    document.getElementById('bill-modal-title').textContent = 'Edit Bill';
                    document.getElementById('bill-submit-text').textContent = 'Update Bill';
                    document.getElementById('bill-action').value = 'update_bill';
                    document.getElementById('bill-id').value = bill.id;
                    
                    // Populate form fields
                    document.getElementById('bill-name').value = bill.name;
                    document.getElementById('bill-amount').value = bill.amount;
                    document.getElementById('bill-category').value = bill.category_id;
                    document.getElementById('bill-due-date').value = bill.due_date;
                    document.getElementById('bill-priority').value = bill.priority;
                    document.getElementById('bill-recurring-period').value = bill.recurring_period;
                    document.getElementById('bill-threshold').value = bill.threshold_warning || '';
                    document.getElementById('bill-is-recurring').checked = bill.is_recurring == 1;
                    document.getElementById('bill-auto-pay').checked = bill.auto_pay == 1;
                    document.getElementById('bill-notes').value = bill.notes || '';
                    
                    document.getElementById('bill-modal').classList.remove('hidden');
                } else {
                    showNotification(data.message || 'Error loading bill', 'error');
                }
            })
            .catch(error => {
                showNotification('Network error. Please try again.', 'error');
            });
    };
    
    window.deleteBill = function(id) {
        if (confirm('Are you sure you want to delete this bill? This action cannot be undone.')) {
            fetch('ajax/delete_bill.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showNotification('Bill deleted successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(result.message || 'Error deleting bill', 'error');
                }
            })
            .catch(error => {
                showNotification('Network error. Please try again.', 'error');
            });
        }
    };
    
    window.payBillFull = function(id) {
        if (confirm('Are you sure you want to pay this bill in full?')) {
            fetch('ajax/pay_bill.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    bill_id: id,
                    payment_type: 'full'
                })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showNotification(result.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(result.message || 'Error paying bill', 'error');
                }
            })
            .catch(error => {
                showNotification('Network error. Please try again.', 'error');
            });
        }
    };
    
    let currentBillId = null;
    
    window.payBillPartial = function(id) {
        currentBillId = id;
        
        fetch(`ajax/get_bill.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const bill = data.bill;
                    const remainingBalance = bill.remaining_balance || bill.amount;
                    
                    document.getElementById('payment-details').innerHTML = `
    <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-lg">
        <h4 class="font-semibold text-gray-800 dark:text-white">${bill.name}</h4>
        <p class="text-sm text-gray-600 dark:text-gray-400">Total Amount: <?= $currency ?> ${parseFloat(bill.amount).toFixed(2)}</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">Remaining: <?= $currency ?> ${parseFloat(remainingBalance).toFixed(2)}</p>
    </div>
`;
                    
                    document.getElementById('payment-amount').max = remainingBalance;
                    document.getElementById('payment-amount').value = '';
                    document.getElementById('payment-modal').classList.remove('hidden');
                } else {
                    showNotification(data.message || 'Error loading bill', 'error');
                }
            })
            .catch(error => {
                showNotification('Network error. Please try again.', 'error');
            });
    };
    
    window.closePaymentModal = function() {
        document.getElementById('payment-modal').classList.add('hidden');
        currentBillId = null;
    };
    
    window.processPartialPayment = function() {
        const amount = parseFloat(document.getElementById('payment-amount').value);
        
        if (!amount || amount <= 0) {
            showNotification('Please enter a valid payment amount', 'error');
            return;
        }
        
        fetch('ajax/pay_bill.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ 
                bill_id: currentBillId,
                payment_type: 'partial',
                payment_amount: amount
            })
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                closePaymentModal();
                showNotification(result.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showNotification(result.message || 'Error making payment', 'error');
            }
        })
        .catch(error => {
            showNotification('Network error. Please try again.', 'error');
        });
    };
    
    window.payAllDue = function() {
        if (confirm('Are you sure you want to pay all due and overdue bills?')) {
            fetch('ajax/pay_all_bills.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showNotification(`Successfully paid ${result.count} bills for ${result.total_amount}!`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(result.message || 'Error paying bills', 'error');
                }
            })
            .catch(error => {
                showNotification('Network error. Please try again.', 'error');
            });
        }
    };
    
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
                if (document.body.contains(notification)) {
                    document.body.removeChild(notification);
                }
            }, 300);
        }, 3000);
    }
</script>
</body>
</html>