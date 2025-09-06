<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Get all user data
    $userData = [
        'user' => $db->fetchOne("SELECT id, username, full_name, email, salary, created_at FROM users WHERE id = ?", [$user_id]),
        'categories' => $db->fetchAll("SELECT * FROM categories WHERE user_id = ? OR is_default = 1", [$user_id]),
        'transactions' => $db->fetchAll("SELECT * FROM transactions WHERE user_id = ? ORDER BY transaction_date DESC", [$user_id]),
        'bills' => $db->fetchAll("SELECT * FROM bills WHERE user_id = ? ORDER BY due_date DESC", [$user_id]),
        'budgets' => $db->fetchAll("SELECT * FROM budgets WHERE user_id = ? ORDER BY created_at DESC", [$user_id]),
        'wallet_balance' => $db->fetchOne("SELECT * FROM wallet_balance WHERE user_id = ?", [$user_id]),
        'settings' => $db->fetchAll("SELECT * FROM settings WHERE user_id = ?", [$user_id]),
        'notifications' => $db->fetchAll("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100", [$user_id])
    ];
    
    // Add metadata
    $backup = [
        'metadata' => [
            'app_name' => APP_NAME,
            'app_version' => APP_VERSION,
            'backup_date' => date('Y-m-d H:i:s'),
            'user_id' => $user_id,
            'timezone' => TIMEZONE
        ],
        'data' => $userData
    ];
    
    // Create filename
    $filename = 'luidigitals_backup_' . $user_id . '_' . date('Y-m-d_H-i-s') . '.json';
    
    // Set headers for download
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen(json_encode($backup, JSON_PRETTY_PRINT)));
    
    echo json_encode($backup, JSON_PRETTY_PRINT);
    exit;
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Backup failed: ' . $e->getMessage()
    ]);
}
?>