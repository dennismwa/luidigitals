<?php
require_once '../config/database.php';

// This script should be run via cron job daily to process savings reminders
// Add to crontab: 0 9 * * * /usr/bin/php /path/to/your/project/ajax/process_savings_reminders.php

$db = Database::getInstance();

try {
    // Get all active savings accounts that need reminders
    $accounts = $db->fetchAll(
        "SELECT sa.*, u.username, u.full_name 
         FROM savings_accounts sa 
         JOIN users u ON sa.user_id = u.id 
         WHERE sa.status = 'active' 
         AND sa.auto_save_amount > 0 
         AND sa.current_amount < sa.target_amount"
    );
    
    $today = date('Y-m-d');
    $processed = 0;
    
    foreach ($accounts as $account) {
        // Check if reminder should be sent based on frequency
        $shouldSendReminder = false;
        $lastReminder = $db->fetchOne(
            "SELECT created_at FROM savings_reminders 
             WHERE savings_account_id = ? AND is_sent = 1 
             ORDER BY created_at DESC LIMIT 1",
            [$account['id']]
        );
        
        if (!$lastReminder) {
            $shouldSendReminder = true;
        } else {
            $lastReminderDate = date('Y-m-d', strtotime($lastReminder['created_at']));
            
            switch ($account['reminder_frequency']) {
                case 'daily':
                    $shouldSendReminder = $lastReminderDate < $today;
                    break;
                case 'weekly':
                    $shouldSendReminder = strtotime($lastReminderDate) < strtotime('-7 days');
                    break;
                case 'monthly':
                    $shouldSendReminder = strtotime($lastReminderDate) < strtotime('-30 days');
                    break;
            }
        }
        
        if ($shouldSendReminder) {
            // Calculate progress
            $progress = $account['target_amount'] > 0 ? 
                round(($account['current_amount'] / $account['target_amount']) * 100, 1) : 0;
            
            // Create reminder message
            $remaining = $account['target_amount'] - $account['current_amount'];
            $message = "Don't forget to save for your '{$account['name']}' goal! ";
            $message .= "You're {$progress}% there with " . formatMoney($remaining, 'KES') . " remaining.";
            
            if ($account['target_date']) {
                $daysLeft = ceil((strtotime($account['target_date']) - time()) / (60 * 60 * 24));
                if ($daysLeft > 0) {
                    $message .= " You have {$daysLeft} days left to reach your target.";
                }
            }
            
            // Create notification
            $db->execute(
                "INSERT INTO notifications (user_id, title, message, type) 
                 VALUES (?, ?, ?, ?)",
                [
                    $account['user_id'],
                    '💰 Savings Reminder',
                    $message,
                    'info'
                ]
            );
            
            // Log the reminder
            $db->execute(
                "INSERT INTO savings_reminders (savings_account_id, user_id, reminder_date, message, is_sent) 
                 VALUES (?, ?, ?, ?, 1)",
                [$account['id'], $account['user_id'], $today, $message]
            );
            
            $processed++;
        }
    }
    
    echo "Processed {$processed} savings reminders.\n";
    
    // Also check for accounts nearing their target date
    $nearingDeadline = $db->fetchAll(
        "SELECT sa.*, u.username, u.full_name 
         FROM savings_accounts sa 
         JOIN users u ON sa.user_id = u.id 
         WHERE sa.status = 'active' 
         AND sa.target_date IS NOT NULL 
         AND sa.current_amount < sa.target_amount 
         AND DATEDIFF(sa.target_date, CURDATE()) BETWEEN 1 AND 7"
    );
    
    foreach ($nearingDeadline as $account) {
        $daysLeft = ceil((strtotime($account['target_date']) - time()) / (60 * 60 * 24));
        $remaining = $account['target_amount'] - $account['current_amount'];
        
        // Check if we already sent a deadline warning recently
        $recentWarning = $db->fetchOne(
            "SELECT id FROM notifications 
             WHERE user_id = ? 
             AND title LIKE '%Deadline Warning%' 
             AND created_at > DATE_SUB(NOW(), INTERVAL 2 DAY)",
            [$account['user_id']]
        );
        
        if (!$recentWarning) {
            $message = "⏰ Your savings goal '{$account['name']}' is due in {$daysLeft} day(s)! ";
            $message .= "You still need to save " . formatMoney($remaining, 'KES') . " to reach your target.";
            
            $db->execute(
                "INSERT INTO notifications (user_id, title, message, type) 
                 VALUES (?, ?, ?, ?)",
                [
                    $account['user_id'],
                    '⏰ Savings Deadline Warning',
                    $message,
                    'warning'
                ]
            );
        }
    }
    
    echo "Deadline warnings processed.\n";
    
} catch (Exception $e) {
    echo "Error processing savings reminders: " . $e->getMessage() . "\n";
    error_log("Savings reminders error: " . $e->getMessage());
}

// Auto-save processing (if enabled)
try {
    $autoSaveAccounts = $db->fetchAll(
        "SELECT sa.*, wb.current_balance 
         FROM savings_accounts sa 
         JOIN wallet_balance wb ON sa.user_id = wb.user_id
         WHERE sa.status = 'active' 
         AND sa.auto_save_amount > 0 
         AND sa.current_amount < sa.target_amount
         AND wb.current_balance >= sa.auto_save_amount"
    );
    
    foreach ($autoSaveAccounts as $account) {
        // Check if auto-save should happen based on frequency
        $shouldAutoSave = false;
        $lastAutoSave = $db->fetchOne(
            "SELECT created_at FROM savings_transactions 
             WHERE savings_account_id = ? AND description LIKE '%Auto-save%' 
             ORDER BY created_at DESC LIMIT 1",
            [$account['id']]
        );
        
        if (!$lastAutoSave) {
            $shouldAutoSave = true;
        } else {
            $lastAutoSaveDate = date('Y-m-d', strtotime($lastAutoSave['created_at']));
            
            switch ($account['reminder_frequency']) {
                case 'daily':
                    $shouldAutoSave = $lastAutoSaveDate < $today;
                    break;
                case 'weekly':
                    $shouldAutoSave = strtotime($lastAutoSaveDate) < strtotime('-7 days');
                    break;
                case 'monthly':
                    $shouldAutoSave = strtotime($lastAutoSaveDate) < strtotime('-30 days');
                    break;
            }
        }
        
        if ($shouldAutoSave) {
            $db->beginTransaction();
            
            try {
                // Add savings transaction
                $db->execute(
                    "INSERT INTO savings_transactions (savings_account_id, user_id, amount, transaction_type, description, balance_before, balance_after) 
                     VALUES (?, ?, ?, 'deposit', ?, ?, ?)",
                    [
                        $account['id'], 
                        $account['user_id'], 
                        $account['auto_save_amount'], 
                        "Auto-save deposit",
                        $account['current_amount'],
                        $account['current_amount'] + $account['auto_save_amount']
                    ]
                );
                
                // Update savings account balance
                $db->execute(
                    "UPDATE savings_accounts SET current_amount = current_amount + ?, updated_at = NOW() WHERE id = ?",
                    [$account['auto_save_amount'], $account['id']]
                );
                
                // Update wallet balance
                $db->execute(
                    "UPDATE wallet_balance SET 
                     current_balance = current_balance - ?, 
                     total_expenses = total_expenses + ?, 
                     updated_at = NOW() WHERE user_id = ?",
                    [$account['auto_save_amount'], $account['auto_save_amount'], $account['user_id']]
                );
                
                // Create wallet transaction
                $current_balance = $account['current_balance'] - $account['auto_save_amount'];
                $db->execute(
                    "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after) 
                     VALUES (?, ?, 'expense', ?, ?, 'bank', ?)",
                    [$account['user_id'], 23, $account['auto_save_amount'], "Auto-save: {$account['name']}", $current_balance]
                );
                
                // Check if target reached
                $new_amount = $account['current_amount'] + $account['auto_save_amount'];
                if ($new_amount >= $account['target_amount']) {
                    $db->execute(
                        "INSERT INTO notifications (user_id, title, message, type) 
                         VALUES (?, ?, ?, ?)",
                        [
                            $account['user_id'],
                            'Savings Goal Achieved! 🎉',
                            "Congratulations! Your auto-save has helped you reach your savings target for '{$account['name']}'.",
                            'success'
                        ]
                    );
                } else {
                    // Regular auto-save notification
                    $db->execute(
                        "INSERT INTO notifications (user_id, title, message, type) 
                         VALUES (?, ?, ?, ?)",
                        [
                            $account['user_id'],
                            '🤖 Auto-Save Completed',
                            "Auto-saved " . formatMoney($account['auto_save_amount'], 'KES') . " to your '{$account['name']}' savings goal.",
                            'success'
                        ]
                    );
                }
                
                $db->commit();
                $processed++;
                
            } catch (Exception $e) {
                $db->rollback();
                echo "Error processing auto-save for account {$account['id']}: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "Processed auto-save for {$processed} accounts.\n";
    
} catch (Exception $e) {
    echo "Error processing auto-save: " . $e->getMessage() . "\n";
    error_log("Auto-save error: " . $e->getMessage());
}
?>