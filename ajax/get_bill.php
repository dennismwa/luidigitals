<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $bill_id = intval($_GET['id'] ?? 0);
    
    if ($bill_id <= 0) {
        throw new Exception('Invalid bill ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    $bill = $db->fetchOne(
        "SELECT * FROM bills WHERE id = ? AND user_id = ?",
        [$bill_id, $user_id]
    );
    
    if (!$bill) {
        throw new Exception('Bill not found.');
    }
    
    echo json_encode([
        'success' => true,
        'bill' => $bill
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>