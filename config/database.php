<?php
// Database Configuration - Luidigitals Wallet System
define('DB_HOST', 'localhost');
define('DB_NAME', 'vxjtgclw_luigitals_wallet');
define('DB_USER', 'vxjtgclw_luigitals_wallet');
define('DB_PASS', 'LUdWc&Uc6T0Z(Q.H');
define('DB_CHARSET', 'utf8mb4');

// Application Configuration
define('APP_NAME', 'Luidigitals Wallet');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://yourdomian.com');
define('TIMEZONE', 'Africa/Nairobi');

// Security Configuration
define('SESSION_TIMEOUT', 3600); // 1 hour
define('BCRYPT_COST', 12);
define('CSRF_TOKEN_NAME', '_token');

// Application Colors
define('PRIMARY_COLOR', '#204cb0');
define('SUCCESS_COLOR', '#16ac2e');
define('WARNING_COLOR', '#f39c12');
define('DANGER_COLOR', '#e74c3c');

// Set timezone
date_default_timezone_set(TIMEZONE);

// Database Connection Class
class Database {
    private static $instance = null;
    public $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            
            // Show user-friendly error in development
            if (defined('DEBUG') && DEBUG) {
                die("Database connection failed: " . $e->getMessage());
            } else {
                die("Database connection failed. Please check your configuration or contact support.");
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query failed: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params));
            throw new Exception("Database query failed. Please try again.");
        }
    }
    
    public function fetchOne($sql, $params = []) {
        $result = $this->query($sql, $params)->fetch();
        return $result ? $result : false;
    }
    
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function execute($sql, $params = []) {
        return $this->query($sql, $params)->rowCount();
    }
    
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollback();
    }
    
    public function inTransaction() {
        return $this->connection->inTransaction();
    }
}

// Helper Functions
function formatMoney($amount, $currency = 'KES') {
    return $currency . ' ' . number_format($amount, 2);
}

function formatDate($date, $format = 'Y-m-d H:i:s') {
    if ($date instanceof DateTime) {
        return $date->format($format);
    }
    return date($format, strtotime($date));
}

function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        // Check if it's an AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.', 'redirect' => 'login.php']);
            exit;
        }
        
        header('Location: login.php');
        exit;
    }
    
    // Check session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_destroy();
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session timeout. Please login again.', 'redirect' => 'login.php']);
            exit;
        }
        
        header('Location: login.php?timeout=1');
        exit;
    }
    
    $_SESSION['last_activity'] = time();
}

// Error handler
function handleError($errno, $errstr, $errfile, $errline) {
    $error = "Error [$errno]: $errstr in $errfile on line $errline";
    error_log($error);
    
    // Don't show errors to users in production
    if (defined('DEBUG') && DEBUG) {
        echo "<br><strong>Error:</strong> $errstr in <strong>$errfile</strong> on line <strong>$errline</strong><br>";
    }
    
    return true;
}

set_error_handler('handleError');

// Start session with security settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.use_strict_mode', 1);

session_start();

// Regenerate session ID periodically
if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
} elseif ($_SESSION['regenerated'] < (time() - 300)) { // Every 5 minutes
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
}

// CSRF Protection
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Additional security functions
function preventXSS($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

function validateInput($data, $type = 'string', $options = []) {
    switch ($type) {
        case 'email':
            return filter_var($data, FILTER_VALIDATE_EMAIL);
        case 'int':
            return filter_var($data, FILTER_VALIDATE_INT, $options);
        case 'float':
            return filter_var($data, FILTER_VALIDATE_FLOAT, $options);
        case 'url':
            return filter_var($data, FILTER_VALIDATE_URL);
        case 'string':
        default:
            return sanitizeInput($data);
    }
}

// File upload security
function validateFileUpload($file, $allowedTypes = [], $maxSize = 2097152) { // 2MB default
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid parameters.');
    }
    
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('No file sent.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('Exceeded filesize limit.');
        default:
            throw new RuntimeException('Unknown errors.');
    }
    
    if ($file['size'] > $maxSize) {
        throw new RuntimeException('Exceeded filesize limit.');
    }
    
    if (!empty($allowedTypes)) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new RuntimeException('Invalid file format.');
        }
    }
    
    return true;
}

// Rate limiting
function checkRateLimit($key, $limit = 10, $window = 60) {
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }
    
    $now = time();
    $windowStart = $now - $window;
    
    if (!isset($_SESSION['rate_limit'][$key])) {
        $_SESSION['rate_limit'][$key] = [];
    }
    
    // Clean old entries
    $_SESSION['rate_limit'][$key] = array_filter($_SESSION['rate_limit'][$key], function($timestamp) use ($windowStart) {
        return $timestamp > $windowStart;
    });
    
    if (count($_SESSION['rate_limit'][$key]) >= $limit) {
        return false;
    }
    
    $_SESSION['rate_limit'][$key][] = $now;
    return true;
}
?>