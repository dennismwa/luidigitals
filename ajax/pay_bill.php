<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $bill_id = intval($input['bill_id'] ?? 0);
    
    if ($bill_id <= 0) {
        throw new Exception('Invalid bill ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Get bill details
    $bill = $db->fetchOne(
        "SELECT b.*, c.name as category_name FROM bills b 
         LEFT JOIN categories c ON b.category_id = c.id 
         WHERE b.id = ? AND b.user_id = ? AND b.status = 'pending'",
        [$bill_id, $user_id]
    );
    
    if (!$bill) {
        throw new Exception('Bill not found or already paid.');
    }
    
    // Check current balance
    $balance = $db->fetchOne(
        "SELECT current_balance FROM wallet_balance WHERE user_id = ?",
        [$user_id]
    );
    
    if (!$balance || $balance['current_balance'] < $bill['amount']) {
        throw new Exception('Insufficient funds to pay this bill.');
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Create transaction record
    $newBalance = $balance['current_balance'] - $bill['amount'];
    
    $db->execute(
        "INSERT INTO transactions (user_id, bill_id, category_id, type, amount, description, payment_method, balance_after, transaction_date) 
         VALUES (?, ?, ?, 'expense', ?, ?, 'bank', ?, NOW())",
        [
            $user_id,
            $bill_id,
            $bill['category_id'],
            $bill['amount'],
            "Bill payment: " . $bill['name'],
            $newBalance
        ]
    );
    
    // Update bill status
    $db->execute(
        "UPDATE bills SET status = 'paid', updated_at = NOW() WHERE id = ?",
        [$bill_id]
    );
    
    // Update wallet balance
    $db->execute(
        "UPDATE wallet_balance SET 
         current_balance = ?,
         total_expenses = total_expenses + ?,
         updated_at = NOW()
         WHERE user_id = ?",
        [$newBalance, $bill['amount'], $user_id]
    );
    
    // Create notification
    $db->execute(
        "INSERT INTO notifications (user_id, title, message, type, related_bill_id) 
         VALUES (?, ?, ?, ?, ?)",
        [
            $user_id,
            'Bill Paid Successfully',
            "Payment of " . formatMoney($bill['amount']) . " for {$bill['name']} has been processed.",
            'success',
            $bill_id
        ]
    );
    
    // If recurring bill, create next instance ONLY if this is the first time paying
    if ($bill['is_recurring'] && $bill['recurring_period']) {
        // Check if we already created a recurring instance for this bill
        $existingRecurring = $db->fetchOne(
            "SELECT id FROM bills 
             WHERE user_id = ? AND name = ? AND amount = ? AND status = 'pending' 
             AND due_date > ? AND id != ?",
            [$user_id, $bill['name'], $bill['amount'], $bill['due_date'], $bill_id]
        );
        
        // Only create new recurring bill if none exists
        if (!$existingRecurring) {
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
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => $newBillBalance <= 0 ? 'Bill paid successfully' : 'Partial payment made successfully',
        'new_balance' => $newWalletBalance,
        'amount_paid' => $actualPayment,
        'remaining_bill_balance' => $newBillBalance,
        'bill_status' => $newStatus
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