
<?php
// Luidigitals Wallet System - Main Entry Point
require_once 'config/database.php';

// Check if user is logged in
if (isLoggedIn()) {
    // Redirect to dashboard if already logged in
    header('Location: dashboard.php');
    exit;
} else {
    // Redirect to login page if not logged in
    header('Location: login.php');
    exit;
}
?>