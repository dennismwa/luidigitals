<?php
require_once 'config/database.php';

$error = '';
$success = '';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

if ($_POST) {
    if (!validateCSRFToken($_POST['_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            $db = Database::getInstance();
            $user = $db->fetchOne(
                "SELECT * FROM users WHERE username = ?",
                [$username]
            );
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['last_login'] = time();
                
                // Update last login
                $db->execute(
                    "UPDATE users SET updated_at = NOW() WHERE id = ?",
                    [$user['id']]
                );
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= APP_NAME ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="<?= PRIMARY_COLOR ?>">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
    
    <!-- PWA Meta Tags -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Luidigitals">
<meta name="mobile-web-app-capable" content="yes">
<meta name="msapplication-TileColor" content="#204cb0">
<meta name="msapplication-tap-highlight" content="no">

<!-- Favicon and Icons -->
<link rel="icon" type="image/png" sizes="32x32" href="assets/icon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/icon-16.png">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<link rel="mask-icon" href="assets/icon-192.png" color="#204cb0">
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, <?= PRIMARY_COLOR ?> 0%, <?= SUCCESS_COLOR ?> 100%);
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .floating-animation {
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center p-4">
    
    <!-- Floating Background Elements -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-10 left-10 w-20 h-20 bg-white bg-opacity-10 rounded-full floating-animation"></div>
        <div class="absolute top-32 right-20 w-16 h-16 bg-white bg-opacity-5 rounded-full floating-animation" style="animation-delay: -1s;"></div>
        <div class="absolute bottom-20 left-20 w-24 h-24 bg-white bg-opacity-5 rounded-full floating-animation" style="animation-delay: -2s;"></div>
        <div class="absolute bottom-32 right-10 w-12 h-12 bg-white bg-opacity-10 rounded-full floating-animation" style="animation-delay: -0.5s;"></div>
    </div>

    <!-- Login Container -->
    <div class="w-full max-w-md">
        <!-- Logo and Title -->
        <div class="text-center mb-8">
            <div class="mx-auto w-24 h-24 glass-effect rounded-full flex items-center justify-center mb-6 pulse-animation">
                <i class="fas fa-wallet text-4xl text-white"></i>
            </div>
            <h1 class="text-4xl font-bold text-white mb-2"><?= APP_NAME ?></h1>
            <p class="text-white text-opacity-80">Secure Wallet Management System</p>
        </div>

        <!-- Login Form -->
        <div class="glass-effect rounded-2xl p-8 shadow-2xl">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                
                <?php if ($error): ?>
                    <div class="bg-red-500 bg-opacity-20 border border-red-400 text-red-100 px-4 py-3 rounded-lg">
                        <i class="fas fa-exclamation-triangle mr-2"></i><?= $error ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="bg-green-500 bg-opacity-20 border border-green-400 text-green-100 px-4 py-3 rounded-lg">
                        <i class="fas fa-check-circle mr-2"></i><?= $success ?>
                    </div>
                <?php endif; ?>

                <div class="space-y-4">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-white text-opacity-60"></i>
                        </div>
                        <input 
                            type="text" 
                            name="username"
                            class="block w-full pl-10 pr-3 py-3 bg-white bg-opacity-10 border border-white border-opacity-20 rounded-lg focus:ring-2 focus:ring-white focus:border-white text-white placeholder-white placeholder-opacity-60 focus:outline-none transition-all duration-300"
                            placeholder="Username"
                            value="<?= isset($_POST['username']) ? sanitizeInput($_POST['username']) : '' ?>"
                            required
                            autocomplete="username"
                        >
                    </div>

                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-white text-opacity-60"></i>
                        </div>
                        <input 
                            type="password" 
                            name="password"
                            class="block w-full pl-10 pr-12 py-3 bg-white bg-opacity-10 border border-white border-opacity-20 rounded-lg focus:ring-2 focus:ring-white focus:border-white text-white placeholder-white placeholder-opacity-60 focus:outline-none transition-all duration-300"
                            placeholder="Password"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-white text-opacity-60 hover:text-white transition-colors">
                            <i class="fas fa-eye" id="password-toggle-icon"></i>
                        </button>
                    </div>
                </div>

                <button 
                    type="submit"
                    class="w-full bg-white bg-opacity-20 hover:bg-opacity-30 text-white font-semibold py-3 px-4 rounded-lg transition-all duration-300 transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-white focus:ring-opacity-50"
                >
                    <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                </button>
            </form>

            <!-- Additional Info -->
            <div class="mt-6 pt-6 border-t border-white border-opacity-20">
                <div class="flex items-center justify-center space-x-4 text-white text-opacity-60 text-sm">
                    <div class="flex items-center">
                        <i class="fas fa-shield-alt mr-1"></i>
                        <span>Secure</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-mobile-alt mr-1"></i>
                        <span>Responsive</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-moon mr-1"></i>
                        <span>Dark Mode</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6 text-white text-opacity-60 text-sm">
            <p>&copy; <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</p>
            <p>v<?= APP_VERSION ?></p>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordField = document.querySelector('input[name="password"]');
            const toggleIcon = document.getElementById('password-toggle-icon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordField.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }

        // Auto-focus first input
        document.querySelector('input[name="username"]').focus();

        // Service Worker Registration for PWA
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then(registration => console.log('SW registered'))
                .catch(error => console.log('SW registration failed'));
        }

        // Form validation enhancement
        document.querySelector('form').addEventListener('submit', function(e) {
            const username = document.querySelector('input[name="username"]').value.trim();
            const password = document.querySelector('input[name="password"]').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return false;
            }
        });
    </script>
</body>
</html>