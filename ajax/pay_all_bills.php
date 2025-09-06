<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Get all pending and overdue bills
    $bills = $db->fetchAll(
        "SELECT b.*, c.name as category_name FROM bills b 
         LEFT JOIN categories c ON b.category_id = c.id 
         WHERE b.user_id = ? AND b.status IN ('pending', 'overdue')
         ORDER BY b.due_date ASC",
        [$user_id]
    );
    
    if (empty($bills)) {
        throw new Exception('No bills to pay.');
    }
    
    // Check current balance
    $balance = $db->fetchOne(
        "SELECT current_balance FROM wallet_balance WHERE user_id = ?",
        [$user_id]
    );
    
    $totalAmount = array_sum(array_column($bills, 'amount'));
    
    if (!$balance || $balance['current_balance'] < $totalAmount) {
        throw new Exception('Insufficient funds to pay all bills. Total required: ' . formatMoney($totalAmount));
    }
    
    // Start transaction
    $db->beginTransaction();
    
    $newBalance = $balance['current_balance'];
    $paidCount = 0;
    
    foreach ($bills as $bill) {
        // Create transaction record
        $newBalance -= $bill['amount'];
        
        $db->execute(
            "INSERT INTO transactions (user_id, bill_id, category_id, type, amount, description, payment_method, balance_after, transaction_date) 
             VALUES (?, ?, ?, 'expense', ?, ?, 'bank', ?, NOW())",
            [
                $user_id,
                $bill['id'],
                $bill['category_id'],
                $bill['amount'],
                "Bill payment: " . $bill['name'],
                $newBalance
            ]
        );
        
        // Update bill status
        $db->execute(
            "UPDATE bills SET status = 'paid', updated_at = NOW() WHERE id = ?",
            [$bill['id']]
        );
        
        // If recurring bill, create next instance
        if ($bill['is_recurring'] && $bill['recurring_period']) {
            $nextDueDate = date('Y-m-d', strtotime($bill['due_date'] . ' +1 ' . $bill['recurring_period']));
            
            $db->execute(
                "INSERT INTO bills (user_id, category_id, name, amount, due_date, is_recurring, recurring_period, auto_pay, priority, threshold_warning, notes) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $user_id,
                    $bill['category_id'],
                    $bill['name'],
                    $bill['amount'],
                    $nextDueDate,
                    $bill['is_recurring'],
                    $bill['recurring_period'],
                    $bill['auto_pay'],
                    $bill['priority'],
                    $bill['threshold_warning'],
                    $bill['notes']
                ]
            );
        }
        
        $paidCount++;
    }
    
    // Update wallet balance
    $db->execute(
        "UPDATE wallet_balance SET 
         current_balance = ?,
         total_expenses = total_expenses + ?,
         updated_at = NOW()
         WHERE user_id = ?",
        [$newBalance, $totalAmount, $user_id]
    );
    
    // Create notification
    $db->execute(
        "INSERT INTO notifications (user_id, title, message, type) 
         VALUES (?, ?, ?, ?)",
        [
            $user_id,
            'All Bills Paid',
            "Successfully paid {$paidCount} bills totaling " . formatMoney($totalAmount) . ".",
            'success'
        ]
    );
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Successfully paid {$paidCount} bills",
        'count' => $paidCount,
        'total_amount' => formatMoney($totalAmount),
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