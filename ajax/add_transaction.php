<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Validate input
    $type = sanitizeInput($_POST['type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = sanitizeInput($_POST['description'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $payment_method = sanitizeInput($_POST['payment_method'] ?? 'cash');
    
    if (empty($type) || $amount <= 0 || empty($description) || $category_id <= 0) {
        throw new Exception('Please fill in all required fields.');
    }
    
    if (!in_array($type, ['income', 'expense'])) {
        throw new Exception('Invalid transaction type.');
    }
    
    if (!in_array($payment_method, ['cash', 'bank', 'mobile_money', 'card'])) {
        throw new Exception('Invalid payment method.');
    }
    
    // Verify category belongs to user or is default
    $category = $db->fetchOne(
        "SELECT * FROM categories WHERE id = ? AND (user_id = ? OR is_default = 1)",
        [$category_id, $user_id]
    );
    
    if (!$category) {
        throw new Exception('Invalid category selected.');
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Get current balance
    $currentBalance = $db->fetchOne(
        "SELECT current_balance FROM wallet_balance WHERE user_id = ?",
        [$user_id]
    );
    
    if (!$currentBalance) {
        // Create balance record if doesn't exist
        $db->execute(
            "INSERT INTO wallet_balance (user_id, current_balance) VALUES (?, 0)",
            [$user_id]
        );
        $balance = 0;
    } else {
        $balance = $currentBalance['current_balance'];
    }
    
    // Calculate new balance
    $newBalance = $type === 'income' ? $balance + $amount : $balance - $amount;
    
    // Check for negative balance on expenses
    if ($type === 'expense' && $newBalance < 0) {
        throw new Exception('Insufficient funds for this transaction.');
    }
    
    // Insert transaction
    $transactionId = $db->query(
        "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after, transaction_date) 
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$user_id, $category_id, $type, $amount, $description, $payment_method, $newBalance]
    );
    
    // Update wallet balance
    $db->execute(
        "UPDATE wallet_balance SET 
         current_balance = ?,
         total_income = total_income + ?,
         total_expenses = total_expenses + ?,
         updated_at = NOW()
         WHERE user_id = ?",
        [
            $newBalance,
            $type === 'income' ? $amount : 0,
            $type === 'expense' ? $amount : 0,
            $user_id
        ]
    );
    
    // Check for budget alerts
    if ($type === 'expense') {
        $currentMonth = date('Y-m');
        $monthlySpending = $db->fetchOne(
            "SELECT SUM(amount) as total FROM transactions 
             WHERE user_id = ? AND category_id = ? AND type = 'expense' 
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$user_id, $category_id, $currentMonth]
        );
        
        $budget = $db->fetchOne(
            "SELECT * FROM budgets WHERE user_id = ? AND category_id = ? 
             AND period_start <= CURDATE() AND period_end >= CURDATE()",
            [$user_id, $category_id]
        );
        
        if ($budget && $monthlySpending['total'] > ($budget['allocated_amount'] * $budget['alert_threshold'] / 100)) {
            // Create alert notification
            $db->execute(
                "INSERT INTO notifications (user_id, title, message, type) 
                 VALUES (?, ?, ?, ?)",
                [
                    $user_id,
                    'Budget Alert',
                    "You've exceeded {$budget['alert_threshold']}% of your {$category['name']} budget.",
                    'warning'
                ]
            );
        }
    }
    
    // Check for low balance alert
    $lowBalanceThreshold = floatval($db->fetchOne(
        "SELECT setting_value FROM settings WHERE user_id = ? AND setting_key = 'low_balance_alert'",
        [$user_id]
    )['setting_value'] ?? 5000);
    
    if ($newBalance <= $lowBalanceThreshold) {
        $db->execute(
            "INSERT INTO notifications (user_id, title, message, type) 
             VALUES (?, ?, ?, ?)",
            [
                $user_id,
                'Low Balance Alert',
                "Your balance is now " . formatMoney($newBalance) . ". Consider adding funds.",
                'warning'
            ]
        );
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Transaction added successfully',
        'new_balance' => $newBalance,
        'transaction_id' => $db->lastInsertId()
    ]);
    
} catch (Exception $e) {
    if ($db->connection->inTransaction()) {
        $db->rollback();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>