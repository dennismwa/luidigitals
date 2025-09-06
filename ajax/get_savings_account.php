<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $account_id = intval($_GET['id'] ?? 0);
    
    if ($account_id <= 0) {
        throw new Exception('Invalid savings account ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    $account = $db->fetchOne(
        "SELECT * FROM savings_accounts WHERE id = ? AND user_id = ?",
        [$account_id, $user_id]
    );
    
    if (!$account) {
        throw new Exception('Savings account not found.');
    }
    
    echo json_encode([
        'success' => true,
        'account' => $account
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}