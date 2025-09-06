<?php
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Validate input
    $name = sanitizeInput($_POST['name'] ?? '');
    $icon = sanitizeInput($_POST['icon'] ?? 'fas fa-money-bill');
    $color = sanitizeInput($_POST['color'] ?? '#204cb0');
    
    if (empty($name)) {
        throw new Exception('Category name is required.');
    }
    
    // Check for duplicate category name (including default categories)
    $existing = $db->fetchOne(
        "SELECT id FROM categories 
         WHERE LOWER(name) = LOWER(?) 
         AND (user_id = ? OR is_default = 1)",
        [$name, $user_id]
    );
    
    if ($existing) {
        throw new Exception('A category with this name already exists.');
    }
    
    // Insert new category
    $db->execute(
        "INSERT INTO categories (user_id, name, icon, color, is_default) VALUES (?, ?, ?, ?, 0)",
        [$user_id, $name, $icon, $color]
    );
    
    $category_id = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Category added successfully',
        'category' => [
            'id' => $category_id,
            'name' => $name,
            'icon' => $icon,
            'color' => $color
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>