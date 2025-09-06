<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $transaction_id = intval($_GET['id'] ?? 0);
    
    if ($transaction_id <= 0) {
        throw new Exception('Invalid transaction ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    $transaction = $db->fetchOne(
        "SELECT * FROM transactions WHERE id = ? AND user_id = ?",
        [$transaction_id, $user_id]
    );
    
    if (!$transaction) {
        throw new Exception('Transaction not found.');
    }
    
    echo json_encode([
        'success' => true,
        'transaction' => $transaction
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>