<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $account_id = intval($input['id'] ?? 0);
    
    if ($account_id <= 0) {
        throw new Exception('Invalid savings account ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Check if account exists and belongs to user
    $account = $db->fetchOne(
        "SELECT * FROM savings_accounts WHERE id = ? AND user_id = ?",
        [$account_id, $user_id]
    );
    
    if (!$account) {
        throw new Exception('Savings account not found.');
    }
    
    // Check if account has money in it
    if ($account['current_amount'] > 0) {
        // Return money to wallet before deleting
        $db->beginTransaction();
        
        // Add money back to wallet
        $db->execute(
            "UPDATE wallet_balance SET 
             current_balance = current_balance + ?,
             total_income = total_income + ?,
             updated_at = NOW()
             WHERE user_id = ?",
            [$account['current_amount'], $account['current_amount'], $user_id]
        );
        
        // Create transaction record
        $current_balance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
        $db->execute(
            "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after) 
             VALUES (?, ?, 'income', ?, ?, 'bank', ?)",
            [$user_id, 23, $account['current_amount'], "Savings account closure: {$account['name']}", $current_balance['current_balance']]
        );
        
        // Delete related reminders first
        $db->execute(
            "DELETE FROM savings_reminders WHERE savings_account_id = ?",
            [$account_id]
        );
        
        // Delete related transactions
        $db->execute(
            "DELETE FROM savings_transactions WHERE savings_account_id = ?",
            [$account_id]
        );
        
        // Delete the account
        $db->execute(
            "DELETE FROM savings_accounts WHERE id = ? AND user_id = ?",
            [$account_id, $user_id]
        );
        
        // Create notification
        $db->execute(
            "INSERT INTO notifications (user_id, title, message, type) 
             VALUES (?, ?, ?, ?)",
            [
                $user_id,
                'Savings Account Deleted',
                "Savings account '{$account['name']}' has been deleted and " . formatMoney($account['current_amount'], 'KES') . " has been returned to your wallet.",
                'info'
            ]
        );
        
        $db->commit();
    } else {
        // No money to return, just delete
        $db->beginTransaction();
        
        // Delete related reminders first
        $db->execute(
            "DELETE FROM savings_reminders WHERE savings_account_id = ?",
            [$account_id]
        );
        
        // Delete related transactions
        $db->execute(
            "DELETE FROM savings_transactions WHERE savings_account_id = ?",
            [$account_id]
        );
        
        // Delete the account
        $db->execute(
            "DELETE FROM savings_accounts WHERE id = ? AND user_id = ?",
            [$account_id, $user_id]
        );
        
        // Create notification
        $db->execute(
            "INSERT INTO notifications (user_id, title, message, type) 
             VALUES (?, ?, ?, ?)",
            [
                $user_id,
                'Savings Account Deleted',
                "Savings account '{$account['name']}' has been deleted.",
                'info'
            ]
        );
        
        $db->commit();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Savings account deleted successfully'
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