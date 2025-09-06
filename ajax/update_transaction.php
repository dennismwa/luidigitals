<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Validate input
    $id = intval($_POST['id'] ?? 0);
    $type = sanitizeInput($_POST['type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = sanitizeInput($_POST['description'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $payment_method = sanitizeInput($_POST['payment_method'] ?? 'cash');
    $reference_number = sanitizeInput($_POST['reference_number'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    if ($id <= 0 || empty($type) || $amount <= 0 || empty($description) || $category_id <= 0) {
        throw new Exception('Please fill in all required fields.');
    }
    
    if (!in_array($type, ['income', 'expense'])) {
        throw new Exception('Invalid transaction type.');
    }
    
    if (!in_array($payment_method, ['cash', 'bank', 'mobile_money', 'card'])) {
        throw new Exception('Invalid payment method.');
    }
    
    // Get original transaction
    $originalTransaction = $db->fetchOne(
        "SELECT * FROM transactions WHERE id = ? AND user_id = ?",
        [$id, $user_id]
    );
    
    if (!$originalTransaction) {
        throw new Exception('Transaction not found.');
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
    
    $balance = $currentBalance['current_balance'];
    
    // Reverse original transaction impact
    if ($originalTransaction['type'] === 'income') {
        $balance -= $originalTransaction['amount'];
    } else {
        $balance += $originalTransaction['amount'];
    }
    
    // Apply new transaction impact
    $newBalance = $type === 'income' ? $balance + $amount : $balance - $amount;
    
    // Check for negative balance on expenses
    if ($type === 'expense' && $newBalance < 0) {
        throw new Exception('Insufficient funds for this transaction.');
    }
    
    // Update transaction
    $db->execute(
        "UPDATE transactions SET 
         type = ?, amount = ?, description = ?, category_id = ?, 
         payment_method = ?, reference_number = ?, notes = ?, balance_after = ?,
         updated_at = NOW()
         WHERE id = ? AND user_id = ?",
        [
            $type, $amount, $description, $category_id,
            $payment_method, $reference_number, $notes, $newBalance,
            $id, $user_id
        ]
    );
    
    // Update wallet balance
    $db->execute(
        "UPDATE wallet_balance SET 
         current_balance = ?,
         total_income = total_income - ? + ?,
         total_expenses = total_expenses - ? + ?,
         updated_at = NOW()
         WHERE user_id = ?",
        [
            $newBalance,
            $originalTransaction['type'] === 'income' ? $originalTransaction['amount'] : 0,
            $type === 'income' ? $amount : 0,
            $originalTransaction['type'] === 'expense' ? $originalTransaction['amount'] : 0,
            $type === 'expense' ? $amount : 0,
            $user_id
        ]
    );
    
    // Update balance_after for subsequent transactions
    $db->execute(
        "UPDATE transactions SET 
         balance_after = balance_after - ? + ?
         WHERE user_id = ? AND transaction_date > ? AND id != ?",
        [
            $originalTransaction['type'] === 'income' ? $originalTransaction['amount'] : -$originalTransaction['amount'],
            $type === 'income' ? $amount : -$amount,
            $user_id,
            $originalTransaction['transaction_date'],
            $id
        ]
    );
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Transaction updated successfully',
        'new_balance' => $newBalance
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