<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $bill_id = intval($input['id'] ?? 0);
    
    if ($bill_id <= 0) {
        throw new Exception('Invalid bill ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Check if bill exists and belongs to user
    $bill = $db->fetchOne(
        "SELECT * FROM bills WHERE id = ? AND user_id = ?",
        [$bill_id, $user_id]
    );
    
    if (!$bill) {
        throw new Exception('Bill not found.');
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Delete related transactions first
    $db->execute(
        "DELETE FROM transactions WHERE bill_id = ? AND user_id = ?",
        [$bill_id, $user_id]
    );
    
    // Delete the bill
    $db->execute(
        "DELETE FROM bills WHERE id = ? AND user_id = ?",
        [$bill_id, $user_id]
    );
    
    // Create notification
    $db->execute(
        "INSERT INTO notifications (user_id, title, message, type) 
         VALUES (?, ?, ?, ?)",
        [
            $user_id,
            'Bill Deleted',
            "Bill '{$bill['name']}' has been deleted.",
            'info'
        ]
    );
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Bill deleted successfully'
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