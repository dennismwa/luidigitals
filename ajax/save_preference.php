<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $key = sanitizeInput($input['key'] ?? '');
    $value = sanitizeInput($input['value'] ?? '');
    
    if (empty($key)) {
        throw new Exception('Setting key is required.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Update or insert setting
    $db->execute(
        "INSERT INTO settings (user_id, setting_key, setting_value) 
         VALUES (?, ?, ?) 
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$user_id, $key, $value]
    );
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>