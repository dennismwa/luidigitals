<?php
require_once 'config/database.php';
requireLogin();

// This script adds the remaining_balance column to the bills table
// Run this once: yoursite.com/add_remaining_balance.php

$db = Database::getInstance();

echo "<h1>Database Migration - Adding Remaining Balance Column</h1>";

try {
    // Check if column already exists
    $columnExists = $db->fetchOne(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_NAME = 'bills' 
         AND COLUMN_NAME = 'remaining_balance' 
         AND TABLE_SCHEMA = DATABASE()"
    );
    
    if ($columnExists) {
        echo "<p style='color: orange;'>⚠️ Column 'remaining_balance' already exists in bills table.</p>";
    } else {
        // Add the remaining_balance column
        $db->execute(
            "ALTER TABLE bills 
             ADD COLUMN remaining_balance DECIMAL(10,2) DEFAULT NULL AFTER amount"
        );
        echo "<p style='color: green;'>✅ Added 'remaining_balance' column to bills table.</p>";
    }
    
    // Update existing bills to set remaining_balance = amount for pending/overdue bills
    $db->execute(
        "UPDATE bills 
         SET remaining_balance = amount 
         WHERE status IN ('pending', 'overdue') 
         AND remaining_balance IS NULL"
    );
    echo "<p style='color: green;'>✅ Updated existing bills with remaining balance.</p>";
    
    // Set remaining_balance to 0 for paid bills
    $db->execute(
        "UPDATE bills 
         SET remaining_balance = 0 
         WHERE status = 'paid' 
         AND remaining_balance IS NULL"
    );
    echo "<p style='color: green;'>✅ Set remaining balance to 0 for paid bills.</p>";
    
    // Add partial status if it doesn't exist (check if any bills use 'partial' status)
    $partialExists = $db->fetchOne(
        "SELECT id FROM bills WHERE status = 'partial' LIMIT 1"
    );
    
    if (!$partialExists) {
        echo "<p style='color: blue;'>ℹ️ 'partial' status is now available for bills.</p>";
    }
    
    echo "<h2 style='color: green;'>✅ Migration completed successfully!</h2>";
    echo "<p><a href='bills.php'>Go to Bills</a> | <a href='dashboard.php'>Go to Dashboard</a></p>";
    echo "<p><em>You can delete this migration file after running it.</em></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Migration failed:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "<p>Please check your database connection and try again.</p>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
h1, h2 { color: #333; }
p { background: white; padding: 10px; border-radius: 5px; margin: 10px 0; }
a { color: #0066cc; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>