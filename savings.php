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
            
            if ($action === 'add_savings_account') {
                $name = sanitizeInput($_POST['name']);
                $target_amount = floatval($_POST['target_amount']);
                $target_date = $_POST['target_date'];
                $description = sanitizeInput($_POST['description'] ?? '');
                $color = sanitizeInput($_POST['color'] ?? '#16ac2e');
                $icon = sanitizeInput($_POST['icon'] ?? 'fas fa-piggy-bank');
                $reminder_frequency = sanitizeInput($_POST['reminder_frequency'] ?? 'weekly');
                $auto_save_amount = floatval($_POST['auto_save_amount'] ?? 0);
                
                if (empty($name) || $target_amount <= 0) {
                    throw new Exception('Please fill in all required fields.');
                }
                
                if (!empty($target_date) && strtotime($target_date) <= time()) {
                    throw new Exception('Target date must be in the future.');
                }
                
                $db->execute(
                    "INSERT INTO savings_accounts (user_id, name, target_amount, target_date, description, color, icon, reminder_frequency, auto_save_amount) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$user_id, $name, $target_amount, $target_date, $description, $color, $icon, $reminder_frequency, $auto_save_amount]
                );
                
                // Create notification
                $db->execute(
                    "INSERT INTO notifications (user_id, title, message, type) 
                     VALUES (?, ?, ?, ?)",
                    [
                        $user_id,
                        'Savings Account Created',
                        "New savings account '{$name}' with target " . formatMoney($target_amount, $currency) . " has been created.",
                        'success'
                    ]
                );
                
                $success = 'Savings account created successfully!';
                
            } elseif ($action === 'update_savings_account') {
                $account_id = intval($_POST['account_id']);
                $name = sanitizeInput($_POST['name']);
                $target_amount = floatval($_POST['target_amount']);
                $target_date = $_POST['target_date'];
                $description = sanitizeInput($_POST['description'] ?? '');
                $color = sanitizeInput($_POST['color'] ?? '#16ac2e');
                $icon = sanitizeInput($_POST['icon'] ?? 'fas fa-piggy-bank');
                $reminder_frequency = sanitizeInput($_POST['reminder_frequency'] ?? 'weekly');
                $auto_save_amount = floatval($_POST['auto_save_amount'] ?? 0);
                
                // Check if account belongs to user
                $existingAccount = $db->fetchOne(
                    "SELECT id FROM savings_accounts WHERE id = ? AND user_id = ?",
                    [$account_id, $user_id]
                );
                
                if (!$existingAccount) {
                    throw new Exception('Savings account not found.');
                }
                
                $db->execute(
                    "UPDATE savings_accounts SET name = ?, target_amount = ?, target_date = ?, description = ?, 
                     color = ?, icon = ?, reminder_frequency = ?, auto_save_amount = ?, updated_at = NOW()
                     WHERE id = ? AND user_id = ?",
                    [$name, $target_amount, $target_date, $description, $color, $icon, $reminder_frequency, $auto_save_amount, $account_id, $user_id]
                );
                
                $success = 'Savings account updated successfully!';
                
            } elseif ($action === 'add_savings_transaction') {
                $account_id = intval($_POST['account_id']);
                $amount = floatval($_POST['amount']);
                $transaction_type = sanitizeInput($_POST['transaction_type']);
                $description = sanitizeInput($_POST['description']);
                
                if ($account_id <= 0 || $amount <= 0 || empty($transaction_type) || empty($description)) {
                    throw new Exception('Please fill in all required fields.');
                }
                
                // Get savings account
                $savingsAccount = $db->fetchOne(
                    "SELECT * FROM savings_accounts WHERE id = ? AND user_id = ?",
                    [$account_id, $user_id]
                );
                
                if (!$savingsAccount) {
                    throw new Exception('Savings account not found.');
                }
                
                // Check wallet balance for deposits
                if ($transaction_type === 'deposit') {
                    $balance = $db->fetchOne(
                        "SELECT current_balance FROM wallet_balance WHERE user_id = ?",
                        [$user_id]
                    );
                    
                    if (!$balance || $balance['current_balance'] < $amount) {
                        throw new Exception('Insufficient wallet balance for this deposit.');
                    }
                }
                
                // Check savings balance for withdrawals
                if ($transaction_type === 'withdrawal' && $savingsAccount['current_amount'] < $amount) {
                    throw new Exception('Insufficient savings balance for this withdrawal.');
                }
                
                $db->beginTransaction();
                
                // Add savings transaction
                $db->execute(
                    "INSERT INTO savings_transactions (savings_account_id, user_id, amount, transaction_type, description) 
                     VALUES (?, ?, ?, ?, ?)",
                    [$account_id, $user_id, $amount, $transaction_type, $description]
                );
                
                // Update savings account balance
                if ($transaction_type === 'deposit') {
                    $new_amount = $savingsAccount['current_amount'] + $amount;
                    
                    // Update wallet balance
                    $db->execute(
                        "UPDATE wallet_balance SET current_balance = current_balance - ?, 
                         total_expenses = total_expenses + ?, updated_at = NOW() WHERE user_id = ?",
                        [$amount, $amount, $user_id]
                    );
                    
                    // Create wallet transaction
                    $current_balance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
                    $db->execute(
                        "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after) 
                         VALUES (?, ?, 'expense', ?, ?, 'bank', ?)",
                        [$user_id, 23, $amount, "Savings deposit: {$savingsAccount['name']}", $current_balance['current_balance']]
                    );
                    
                } else {
                    $new_amount = $savingsAccount['current_amount'] - $amount;
                    
                    // Update wallet balance
                    $db->execute(
                        "UPDATE wallet_balance SET current_balance = current_balance + ?, 
                         total_income = total_income + ?, updated_at = NOW() WHERE user_id = ?",
                        [$amount, $amount, $user_id]
                    );
                    
                    // Create wallet transaction
                    $current_balance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
                    $db->execute(
                        "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after) 
                         VALUES (?, ?, 'income', ?, ?, 'bank', ?)",
                        [$user_id, 23, $amount, "Savings withdrawal: {$savingsAccount['name']}", $current_balance['current_balance']]
                    );
                }
                
                $db->execute(
                    "UPDATE savings_accounts SET current_amount = ?, updated_at = NOW() WHERE id = ?",
                    [$new_amount, $account_id]
                );
                
                // Check if target reached
                if ($transaction_type === 'deposit' && $new_amount >= $savingsAccount['target_amount']) {
                    $db->execute(
                        "INSERT INTO notifications (user_id, title, message, type) 
                         VALUES (?, ?, ?, ?)",
                        [
                            $user_id,
                            'Savings Goal Achieved! 🎉',
                            "Congratulations! You've reached your savings target for '{$savingsAccount['name']}'.",
                            'success'
                        ]
                    );
                }
                
                $db->commit();
                $success = ucfirst($transaction_type) . ' completed successfully!';
            }
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            $error = $e->getMessage();
        }
    }
}

// Get savings accounts with progress
$savingsAccounts = $db->fetchAll(
    "SELECT sa.*, 
     COALESCE(sa.current_amount, 0) as current_amount,
     CASE 
         WHEN sa.target_amount > 0 THEN ROUND((sa.current_amount / sa.target_amount) * 100, 2)
         ELSE 0 
     END as progress_percentage,
     CASE 
         WHEN sa.target_date IS NOT NULL THEN DATEDIFF(sa.target_date, CURDATE())
         ELSE NULL 
     END as days_remaining
     FROM savings_accounts sa 
     WHERE sa.user_id = ? 
     ORDER BY sa.created_at DESC",
    [$user_id]
);

// Get recent savings transactions
$recentTransactions = $db->fetchAll(
    "SELECT st.*, sa.name as account_name, sa.color as account_color, sa.icon as account_icon
     FROM savings_transactions st
     JOIN savings_accounts sa ON st.savings_account_id = sa.id
     WHERE st.user_id = ?
     ORDER BY st.created_at DESC
     LIMIT 10",
    [$user_id]
);

// Calculate statistics
$totalSaved = array_sum(array_column($savingsAccounts, 'current_amount'));
$totalTargets = array_sum(array_column($savingsAccounts, 'target_amount'));
$completedGoals = count(array_filter($savingsAccounts, function($account) {
    return $account['current_amount'] >= $account['target_amount'];
}));

$themeClass = $darkMode ? 'dark' : '';
?>

<!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savings - <?= APP_NAME ?></title>
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
        
        .savings-card { transition: all 0.3s ease; }
        .savings-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
        
        .progress-bar { transition: width 0.5s ease-in-out; }
        
        .gradient-primary { background: linear-gradient(135deg, <?= PRIMARY_COLOR ?> 0%, #1e40af 100%); }
        .gradient-success { background: linear-gradient(135deg, <?= SUCCESS_COLOR ?> 0%, #10b981 100%); }
        
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        
        .floating-animation {
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
        }
        
        @media (max-width: 768px) {
            .sidebar-mobile {
                transform: translateX(-100%);
            }
            .sidebar-mobile.open {
                transform: translateX(0);
            }
        }
        /* Add to your custom CSS file or in a <style> tag */
.savings-progress-ring {
    transform: rotate(-90deg);
}

.savings-card-glow {
    box-shadow: 0 0 20px rgba(22, 172, 46, 0.3);
}

.completed-goal {
    background: linear-gradient(45deg, #16ac2e, #10b981);
    color: white;
}

.savings-floating {
    animation: savings-float 4s ease-in-out infinite;
}

@keyframes savings-float {
    0%, 100% { transform: translateY(0px) rotate(0deg); }
    25% { transform: translateY(-10px) rotate(1deg); }
    50% { transform: translateY(-5px) rotate(-1deg); }
    75% { transform: translateY(-8px) rotate(1deg); }
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
                <a href="savings.php" class="flex items-center space-x-3 px-4 py-3 rounded-lg gradient-success text-white">
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
                        <h1 class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Savings Management</h1>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Track your savings goals and manage multiple accounts</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="openAddAccountModal()" class="gradient-success text-white px-4 py-2 rounded-lg hover:opacity-90 transition-opacity">
                        <i class="fas fa-plus mr-2"></i>New Savings Goal
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
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg savings-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Saved</p>
                            <p class="text-2xl font-bold text-green-600"><?= formatMoney($totalSaved, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center floating-animation">
                            <i class="fas fa-piggy-bank text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg savings-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Total Targets</p>
                            <p class="text-2xl font-bold text-blue-600"><?= formatMoney($totalTargets, $currency) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center floating-animation" style="animation-delay: -0.5s;">
                            <i class="fas fa-target text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg savings-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Active Goals</p>
                            <p class="text-2xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= count($savingsAccounts) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center floating-animation" style="animation-delay: -1s;">
                            <i class="fas fa-bullseye text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg savings-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Completed Goals</p>
                            <p class="text-2xl font-bold text-yellow-600"><?= $completedGoals ?></p>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center floating-animation" style="animation-delay: -1.5s;">
                            <i class="fas fa-trophy text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Savings Accounts Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">
                <?php if (empty($savingsAccounts)): ?>
                    <div class="col-span-full text-center py-12 <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                        <div class="floating-animation">
                            <i class="fas fa-piggy-bank text-6xl mb-4"></i>
                        </div>
                        <p class="text-lg mb-2">No savings goals yet</p>
                        <p class="mb-4">Create your first savings goal to start building your financial future</p>
                        <button onclick="openAddAccountModal()" class="gradient-success text-white px-6 py-3 rounded-lg hover:opacity-90 transition-opacity">
                            <i class="fas fa-plus mr-2"></i>Create Your First Goal
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($savingsAccounts as $account): ?>
                        <?php
                        $isCompleted = $account['current_amount'] >= $account['target_amount'];
                        $daysRemaining = $account['days_remaining'];
                        $isOverdue = $daysRemaining !== null && $daysRemaining < 0;
                        ?>
                        <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg savings-card <?= $isCompleted ? 'ring-2 ring-green-500' : ($isOverdue ? 'ring-2 ring-red-500' : '') ?>">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: <?= $account['color'] ?>;">
                                        <i class="<?= $account['icon'] ?> text-white text-lg"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= htmlspecialchars($account['name']) ?></h3>
                                        <?php if ($account['description']): ?>
                                            <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= htmlspecialchars($account['description']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button onclick="editSavingsAccount(<?= $account['id'] ?>)" class="text-blue-600 hover:text-blue-800 transition-colors" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteSavingsAccount(<?= $account['id'] ?>)" class="text-red-600 hover:text-red-800 transition-colors" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <div class="mb-4">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Progress</span>
                                    <span class="text-sm font-medium <?= $isCompleted ? 'text-green-600' : 'text-blue-600' ?>"><?= number_format($account['progress_percentage'], 1) ?>%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-3">
                                    <div class="<?= $isCompleted ? 'bg-green-500' : 'bg-blue-500' ?> h-3 rounded-full progress-bar" style="width: <?= min(100, $account['progress_percentage']) ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Current Amount</span>
                                    <span class="font-semibold text-green-600"><?= formatMoney($account['current_amount'], $currency) ?></span>
                                </div>
                                
                                <div class="flex justify-between items-center">
                                    <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Target Amount</span>
                                    <span class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatMoney($account['target_amount'], $currency) ?></span>
                                </div>
                                
                                <div class="flex justify-between items-center">
                                    <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Remaining</span>
                                    <span class="font-semibold <?= $isCompleted ? 'text-green-600' : 'text-blue-600' ?>">
                                        <?= $isCompleted ? 'Goal Achieved! 🎉' : formatMoney($account['target_amount'] - $account['current_amount'], $currency) ?>
                                    </span>
                                </div>
                                
                                <?php if ($account['target_date']): ?>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Target Date</span>
                                        <span class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= formatDate($account['target_date'], 'M j, Y') ?></span>
                                    </div>
                                    
                                    <?php if ($daysRemaining !== null): ?>
                                        <div class="flex justify-between items-center">
                                            <span class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Time Remaining</span>
                                            <span class="text-sm <?= $isOverdue ? 'text-red-600' : ($daysRemaining <= 30 ? 'text-yellow-600' : 'text-green-600') ?>">
                                                <?php if ($isOverdue): ?>
                                                    Overdue by <?= abs($daysRemaining) ?> days
                                                <?php elseif ($daysRemaining == 0): ?>
                                                    Due today
                                                <?php else: ?>
                                                    <?= $daysRemaining ?> days left
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if ($account['auto_save_amount'] > 0): ?>
                                    <div class="pt-3 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                        <div class="flex items-center text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">
                                            <i class="fas fa-robot mr-2"></i>
                                            Auto-save: <?= formatMoney($account['auto_save_amount'], $currency) ?> <?= $account['reminder_frequency'] ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-4 pt-4 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                                <div class="grid grid-cols-2 gap-2">
                                    <button onclick="openTransactionModal(<?= $account['id'] ?>, 'deposit')" class="bg-green-500 hover:bg-green-600 text-white py-2 px-3 rounded-lg transition-colors text-sm">
                                        <i class="fas fa-plus mr-1"></i>Deposit
                                    </button>
                                    <button onclick="openTransactionModal(<?= $account['id'] ?>, 'withdrawal')" class="bg-orange-500 hover:bg-orange-600 text-white py-2 px-3 rounded-lg transition-colors text-sm" <?= $account['current_amount'] <= 0 ? 'disabled' : '' ?>>
                                        <i class="fas fa-minus mr-1"></i>Withdraw
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Recent Transactions -->
            <?php if (!empty($recentTransactions)): ?>
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                    <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Recent Savings Transactions</h3>
                    <div class="space-y-4">
                        <?php foreach ($recentTransactions as $transaction): ?>
                            <div class="flex items-center space-x-4 p-3 rounded-lg border <?= $darkMode ? 'border-gray-700 hover:bg-gray-700' : 'border-gray-200 hover:bg-gray-50' ?> transition-colors">
                                <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: <?= $transaction['account_color'] ?>;">
                                    <i class="<?= $transaction['account_icon'] ?> text-white"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= htmlspecialchars($transaction['description']) ?></p>
                                    <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>"><?= htmlspecialchars($transaction['account_name']) ?></p>
                                    <p class="text-xs <?= $darkMode ? 'text-gray-500' : 'text-gray-400' ?>"><?= formatDate($transaction['created_at'], 'M j, Y g:i A') ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold <?= $transaction['transaction_type'] === 'deposit' ? 'text-green-600' : 'text-orange-600' ?>">
                                        <?= $transaction['transaction_type'] === 'deposit' ? '+' : '-' ?><?= formatMoney($transaction['amount'], $currency) ?>
                                    </p>
                                    <p class="text-xs <?= $darkMode ? 'text-gray-500' : 'text-gray-400' ?>"><?= ucfirst($transaction['transaction_type']) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <!-- Add/Edit Savings Account Modal -->
    <div id="account-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-6">
                <h3 id="account-modal-title" class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Create Savings Goal</h3>
                <button onclick="closeAccountModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="account-form" method="POST" class="space-y-4">
                <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" id="account-action" value="add_savings_account">
                <input type="hidden" name="account_id" id="account-id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Goal Name *</label>
                        <input type="text" name="name" id="account-name" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="e.g., Emergency Fund, Vacation" required>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Target Amount *</label>
                        <input type="number" name="target_amount" id="account-target" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00" required>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Description</label>
                    <input type="text" name="description" id="account-description" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="Optional description">
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Target Date</label>
                        <input type="date" name="target_date" id="account-date" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Reminder Frequency</label>
                        <select name="reminder_frequency" id="account-reminder" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            <option value="daily">Daily</option>
                            <option value="weekly" selected>Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Icon</label>
                        <select name="icon" id="account-icon" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                            <option value="fas fa-piggy-bank">🐷 Piggy Bank</option>
                            <option value="fas fa-home">🏠 House</option>
                            <option value="fas fa-car">🚗 Car</option>
                            <option value="fas fa-graduation-cap">🎓 Education</option>
                            <option value="fas fa-plane">✈️ Travel</option>
                            <option value="fas fa-ring">💍 Wedding</option>
                            <option value="fas fa-baby">👶 Baby</option>
                            <option value="fas fa-laptop">💻 Technology</option>
                            <option value="fas fa-shield-alt">🛡️ Emergency</option>
                            <option value="fas fa-gift">🎁 Gift</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Color</label>
                        <input type="color" name="color" id="account-color" value="#16ac2e" class="w-full h-10 <?= $darkMode ? 'bg-gray-700 border-gray-600' : 'bg-white border-gray-300' ?> border rounded-lg">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Auto-Save Amount</label>
                        <input type="number" name="auto_save_amount" id="account-auto-save" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00">
                    </div>
                </div>
                
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="closeAccountModal()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 gradient-success text-white py-2 px-4 rounded-lg hover:opacity-90 transition-opacity">
                        <span id="account-submit-text">Create Goal</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Savings Transaction Modal -->
    <div id="transaction-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-md">
            <div class="flex items-center justify-between mb-6">
                <h3 id="transaction-modal-title" class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Make Transaction</h3>
                <button onclick="closeTransactionModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div id="transaction-details" class="mb-4">
                <!-- Transaction details will be populated here -->
            </div>
            
            <form id="transaction-form" method="POST" class="space-y-4">
                <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="add_savings_transaction">
                <input type="hidden" name="account_id" id="transaction-account-id">
                <input type="hidden" name="transaction_type" id="transaction-type">
                
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Amount *</label>
                    <input type="number" name="amount" id="transaction-amount" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00" required>
                </div>
                
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Description *</label>
                    <input type="text" name="description" id="transaction-description" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="Transaction description" required>
                </div>
                
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="closeTransactionModal()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button type="submit" id="transaction-submit" class="flex-1 py-2 px-4 rounded-lg text-white transition-colors">
                        <span id="transaction-submit-text">Process</span>
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
        
        function openAddAccountModal() {
            isEditMode = false;
            document.getElementById('account-modal-title').textContent = 'Create Savings Goal';
            document.getElementById('account-submit-text').textContent = 'Create Goal';
            document.getElementById('account-action').value = 'add_savings_account';
            document.getElementById('account-form').reset();
            document.getElementById('account-id').value = '';
            document.getElementById('account-color').value = '#16ac2e';
            
            // Set minimum date to tomorrow
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('account-date').min = tomorrow.toISOString().split('T')[0];
            
            document.getElementById('account-modal').classList.remove('hidden');
        }
        
        function closeAccountModal() {
            document.getElementById('account-modal').classList.add('hidden');
        }
        
        function editSavingsAccount(id) {
            // This would fetch account data via AJAX
            fetch(`ajax/get_savings_account.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const account = data.account;
                        isEditMode = true;
                        document.getElementById('account-modal-title').textContent = 'Edit Savings Goal';
                        document.getElementById('account-submit-text').textContent = 'Update Goal';
                        document.getElementById('account-action').value = 'update_savings_account';
                        document.getElementById('account-id').value = account.id;
                        
                        // Populate form fields
                        document.getElementById('account-name').value = account.name;
                        document.getElementById('account-target').value = account.target_amount;
                        document.getElementById('account-description').value = account.description || '';
                        document.getElementById('account-date').value = account.target_date || '';
                        document.getElementById('account-reminder').value = account.reminder_frequency;
                        document.getElementById('account-icon').value = account.icon;
                        document.getElementById('account-color').value = account.color;
                        document.getElementById('account-auto-save').value = account.auto_save_amount || '';
                        
                        document.getElementById('account-modal').classList.remove('hidden');
                    } else {
                        showNotification(data.message || 'Error loading savings account', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Network error. Please try again.', 'error');
                });
        }
        
        function deleteSavingsAccount(id) {
            if (confirm('Are you sure you want to delete this savings account? This action cannot be undone.')) {
                fetch('ajax/delete_savings_account.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: id })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showNotification('Savings account deleted successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification(result.message || 'Error deleting savings account', 'error');
                    }
                })
                .catch(error => {
                    showNotification('Network error. Please try again.', 'error');
                });
            }
        }
        
        function openTransactionModal(accountId, transactionType) {
            document.getElementById('transaction-account-id').value = accountId;
            document.getElementById('transaction-type').value = transactionType;
            
            const isDeposit = transactionType === 'deposit';
            const title = isDeposit ? 'Make Deposit' : 'Make Withdrawal';
            const buttonText = isDeposit ? 'Deposit' : 'Withdraw';
            const buttonClass = isDeposit ? 'bg-green-500 hover:bg-green-600' : 'bg-orange-500 hover:bg-orange-600';
            
            document.getElementById('transaction-modal-title').textContent = title;
            document.getElementById('transaction-submit-text').textContent = buttonText;
            document.getElementById('transaction-submit').className = `flex-1 py-2 px-4 rounded-lg text-white transition-colors ${buttonClass}`;
            
            // You would fetch account details here
            document.getElementById('transaction-details').innerHTML = `
                <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-lg">
                    <p class="text-sm text-gray-600 dark:text-gray-400">${title} for your savings goal</p>
                </div>
            `;
            
            document.getElementById('transaction-form').reset();
            document.getElementById('transaction-account-id').value = accountId;
            document.getElementById('transaction-type').value = transactionType;
            document.getElementById('transaction-modal').classList.remove('hidden');
        }
        
        function closeTransactionModal() {
            document.getElementById('transaction-modal').classList.add('hidden');
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
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }
        
        // Form validation
        document.getElementById('account-form').addEventListener('submit', function(e) {
            const targetAmount = parseFloat(document.getElementById('account-target').value);
            const targetDate = document.getElementById('account-date').value;
            
            if (targetAmount <= 0) {
                e.preventDefault();
                showNotification('Target amount must be greater than 0', 'error');
                return false;
            }
            
            if (targetDate && new Date(targetDate) <= new Date()) {
                e.preventDefault();
                showNotification('Target date must be in the future', 'error');
                return false;
            }
        });
        
        document.getElementById('transaction-form').addEventListener('submit', function(e) {
            const amount = parseFloat(document.getElementById('transaction-amount').value);
            
            if (amount <= 0) {
                e.preventDefault();
                showNotification('Amount must be greater than 0', 'error');
                return false;
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                if (e.key === 'n') {
                    e.preventDefault();
                    openAddAccountModal();
                }
            }
            
            if (e.key === 'Escape') {
                closeAccountModal();
                closeTransactionModal();
            }
        });
    </script>
</body>
</html>