<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $darkMode = isset($input['dark_mode']) && $input['dark_mode'] ? '1' : '0';
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Update or insert dark mode setting
    $db->execute(
        "INSERT INTO settings (user_id, setting_key, setting_value) 
         VALUES (?, 'dark_mode', ?) 
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$user_id, $darkMode]
    );
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>