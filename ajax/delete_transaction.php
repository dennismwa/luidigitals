<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $transaction_id = intval($input['id'] ?? 0);
    
    if ($transaction_id <= 0) {
        throw new Exception('Invalid transaction ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Get transaction details
    $transaction = $db->fetchOne(
        "SELECT * FROM transactions WHERE id = ? AND user_id = ?",
        [$transaction_id, $user_id]
    );
    
    if (!$transaction) {
        throw new Exception('Transaction not found.');
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Get current balance
    $currentBalance = $db->fetchOne(
        "SELECT current_balance FROM wallet_balance WHERE user_id = ?",
        [$user_id]
    );
    
    $balance = $currentBalance['current_balance'];
    
    // Reverse transaction impact
    if ($transaction['type'] === 'income') {
        $newBalance = $balance - $transaction['amount'];
    } else {
        $newBalance = $balance + $transaction['amount'];
    }
    
    // Delete transaction
    $db->execute(
        "DELETE FROM transactions WHERE id = ? AND user_id = ?",
        [$transaction_id, $user_id]
    );
    
    // Update wallet balance
    $db->execute(
        "UPDATE wallet_balance SET 
         current_balance = ?,
         total_income = total_income - ?,
         total_expenses = total_expenses - ?,
         updated_at = NOW()
         WHERE user_id = ?",
        [
            $newBalance,
            $transaction['type'] === 'income' ? $transaction['amount'] : 0,
            $transaction['type'] === 'expense' ? $transaction['amount'] : 0,
            $user_id
        ]
    );
    
    // Update balance_after for subsequent transactions
    $balanceChange = $transaction['type'] === 'income' ? -$transaction['amount'] : $transaction['amount'];
    $db->execute(
        "UPDATE transactions SET 
         balance_after = balance_after + ?
         WHERE user_id = ? AND transaction_date > ?",
        [$balanceChange, $user_id, $transaction['transaction_date']]
    );
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Transaction deleted successfully',
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