<?php
require_once 'config/database.php';
requireLogin();

// This script should be run once to clean up existing duplicates
// You can access it via yoursite.com/cleanup_duplicates.php

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

echo "<h1>Database Cleanup - Removing Duplicates</h1>";

try {
    $db->beginTransaction();
    
    // 1. Clean up duplicate categories
    echo "<h2>Cleaning up duplicate categories...</h2>";
    
    // Find duplicate categories by name (case insensitive)
    $duplicateCategories = $db->fetchAll(
        "SELECT name, GROUP_CONCAT(id ORDER BY id) as ids, COUNT(*) as count
         FROM categories 
         WHERE (user_id = ? OR is_default = 1)
         GROUP BY LOWER(name) 
         HAVING count > 1",
        [$user_id]
    );
    
    foreach ($duplicateCategories as $duplicate) {
        $ids = explode(',', $duplicate['ids']);
        $keepId = $ids[0]; // Keep the first (oldest) category
        $removeIds = array_slice($ids, 1); // Remove the rest
        
        echo "Found duplicate category '{$duplicate['name']}' with IDs: {$duplicate['ids']}<br>";
        echo "Keeping ID {$keepId}, removing IDs: " . implode(', ', $removeIds) . "<br>";
        
        // Update transactions to use the kept category
        foreach ($removeIds as $removeId) {
            $db->execute(
                "UPDATE transactions SET category_id = ? WHERE category_id = ? AND user_id = ?",
                [$keepId, $removeId, $user_id]
            );
            
            $db->execute(
                "UPDATE bills SET category_id = ? WHERE category_id = ? AND user_id = ?",
                [$keepId, $removeId, $user_id]
            );
            
            $db->execute(
                "UPDATE budgets SET category_id = ? WHERE category_id = ? AND user_id = ?",
                [$keepId, $removeId, $user_id]
            );
        }
        
        // Delete duplicate categories
        $placeholders = str_repeat('?,', count($removeIds) - 1) . '?';
        $params = array_merge($removeIds, [$user_id]);
        $db->execute(
            "DELETE FROM categories WHERE id IN ($placeholders) AND (user_id = ? OR is_default = 0)",
            $params
        );
        
        echo "Cleaned up duplicate category '{$duplicate['name']}'<br><br>";
    }
    
    // 2. Clean up duplicate bills (same name, amount, due_date, and status)
    echo "<h2>Cleaning up duplicate bills...</h2>";
    
    $duplicateBills = $db->fetchAll(
        "SELECT name, amount, due_date, status, GROUP_CONCAT(id ORDER BY id) as ids, COUNT(*) as count
         FROM bills 
         WHERE user_id = ?
         GROUP BY name, amount, due_date, status 
         HAVING count > 1",
        [$user_id]
    );
    
    foreach ($duplicateBills as $duplicate) {
        $ids = explode(',', $duplicate['ids']);
        $keepId = $ids[0]; // Keep the first (oldest) bill
        $removeIds = array_slice($ids, 1); // Remove the rest
        
        echo "Found duplicate bill '{$duplicate['name']}' ({$duplicate['amount']}) with IDs: {$duplicate['ids']}<br>";
        echo "Keeping ID {$keepId}, removing IDs: " . implode(', ', $removeIds) . "<br>";
        
        // Update transactions to reference the kept bill
        foreach ($removeIds as $removeId) {
            $db->execute(
                "UPDATE transactions SET bill_id = ? WHERE bill_id = ? AND user_id = ?",
                [$keepId, $removeId, $user_id]
            );
        }
        
        // Delete duplicate bills
        $placeholders = str_repeat('?,', count($removeIds) - 1) . '?';
        $params = array_merge($removeIds, [$user_id]);
        $db->execute(
            "DELETE FROM bills WHERE id IN ($placeholders) AND user_id = ?",
            $params
        );
        
        echo "Cleaned up duplicate bill '{$duplicate['name']}'<br><br>";
    }
    
    // 3. Clean up orphaned transactions
    echo "<h2>Cleaning up orphaned transactions...</h2>";
    
    $orphanedTransactions = $db->fetchAll(
        "SELECT t.id, t.description 
         FROM transactions t 
         LEFT JOIN categories c ON t.category_id = c.id 
         WHERE t.user_id = ? AND c.id IS NULL",
        [$user_id]
    );
    
    if (!empty($orphanedTransactions)) {
        // Get or create an "Uncategorized" category
        $uncategorized = $db->fetchOne(
            "SELECT id FROM categories WHERE name = 'Uncategorized' AND (user_id = ? OR is_default = 1) LIMIT 1",
            [$user_id]
        );
        
        if (!$uncategorized) {
            $db->execute(
                "INSERT INTO categories (user_id, name, icon, color, is_default) VALUES (?, 'Uncategorized', 'fas fa-question', '#6b7280', 0)",
                [$user_id]
            );
            $uncategorizedId = $db->lastInsertId();
        } else {
            $uncategorizedId = $uncategorized['id'];
        }
        
        // Update orphaned transactions
        foreach ($orphanedTransactions as $transaction) {
            $db->execute(
                "UPDATE transactions SET category_id = ? WHERE id = ?",
                [$uncategorizedId, $transaction['id']]
            );
            echo "Fixed orphaned transaction: {$transaction['description']}<br>";
        }
    }
    
    $db->commit();
    
    echo "<h2 style='color: green;'>✅ Cleanup completed successfully!</h2>";
    echo "<p><a href='dashboard.php'>Return to Dashboard</a></p>";
    
    // Optional: Delete this file after running
    echo "<p><em>You should delete this cleanup file after running it.</em></p>";
    
} catch (Exception $e) {
    $db->rollback();
    echo "<h2 style='color: red;'>❌ Error during cleanup:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>