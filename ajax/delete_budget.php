<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $budget_id = intval($input['id'] ?? 0);
    
    if ($budget_id <= 0) {
        throw new Exception('Invalid budget ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Check if budget exists and belongs to user
    $budget = $db->fetchOne(
        "SELECT * FROM budgets WHERE id = ? AND user_id = ?",
        [$budget_id, $user_id]
    );
    
    if (!$budget) {
        throw new Exception('Budget not found.');
    }
    
    // Delete the budget
    $db->execute(
        "DELETE FROM budgets WHERE id = ? AND user_id = ?",
        [$budget_id, $user_id]
    );
    
    // Create notification
    $db->execute(
        "INSERT INTO notifications (user_id, title, message, type) 
         VALUES (?, ?, ?, ?)",
        [
            $user_id,
            'Budget Deleted',
            "Budget '{$budget['name']}' has been deleted.",
            'info'
        ]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Budget deleted successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>