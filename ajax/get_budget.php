<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $budget_id = intval($_GET['id'] ?? 0);
    
    if ($budget_id <= 0) {
        throw new Exception('Invalid budget ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    $budget = $db->fetchOne(
        "SELECT * FROM budgets WHERE id = ? AND user_id = ?",
        [$budget_id, $user_id]
    );
    
    if (!$budget) {
        throw new Exception('Budget not found.');
    }
    
    echo json_encode([
        'success' => true,
        'budget' => $budget
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>