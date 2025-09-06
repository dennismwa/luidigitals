<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Reset all budgets spent amounts to 0
    $db->execute(
        "UPDATE budgets SET spent_amount = 0, updated_at = NOW() WHERE user_id = ?",
        [$user_id]
    );
    
    // Create notification
    $db->execute(
        "INSERT INTO notifications (user_id, title, message, type) 
         VALUES (?, ?, ?, ?)",
        [
            $user_id,
            'Budgets Reset',
            'All budget spending amounts have been reset to zero.',
            'info'
        ]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'All budgets reset successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>