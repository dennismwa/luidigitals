<?php
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found - Luidigitals Wallet</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #204cb0 0%, #16ac2e 100%);
        }
        .floating-animation {
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
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

    <!-- 404 Container -->
    <div class="text-center text-white max-w-md">
        <!-- Large 404 -->
        <h1 class="text-9xl font-bold mb-4 opacity-20">404</h1>
        
        <!-- Error Icon -->
        <div class="mx-auto w-24 h-24 bg-white bg-opacity-20 rounded-full flex items-center justify-center mb-6">
            <i class="fas fa-search text-4xl text-white"></i>
        </div>
        
        <!-- Title and Message -->
        <h2 class="text-4xl font-bold mb-4">Page Not Found</h2>
        <p class="text-white text-opacity-80 mb-8">
            The page you're looking for doesn't exist or has been moved. Let's get you back to your wallet dashboard.
        </p>
        
        <!-- Actions -->
        <div class="space-y-4">
            <a href="dashboard.php" class="block w-full bg-white bg-opacity-20 hover:bg-opacity-30 text-white font-semibold py-3 px-6 rounded-lg transition-all duration-300 transform hover:scale-105">
                <i class="fas fa-home mr-2"></i>Go to Dashboard
            </a>
            
            <button onclick="history.back()" class="block w-full bg-white bg-opacity-10 hover:bg-opacity-20 text-white font-semibold py-3 px-6 rounded-lg transition-all duration-300">
                <i class="fas fa-arrow-left mr-2"></i>Go Back
            </button>
        </div>
        
        <!-- Quick Links -->
        <div class="mt-8 pt-8 border-t border-white border-opacity-20">
            <h3 class="text-lg font-semibold mb-4">Quick Links:</h3>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <a href="transactions.php" class="text-white text-opacity-80 hover:text-opacity-100 transition-colors">
                    <i class="fas fa-exchange-alt mr-1"></i>Transactions
                </a>
                <a href="bills.php" class="text-white text-opacity-80 hover:text-opacity-100 transition-colors">
                    <i class="fas fa-file-invoice mr-1"></i>Bills
                </a>
                <a href="budgets.php" class="text-white text-opacity-80 hover:text-opacity-100 transition-colors">
                    <i class="fas fa-chart-pie mr-1"></i>Budgets
                </a>
                <a href="reports.php" class="text-white text-opacity-80 hover:text-opacity-100 transition-colors">
                    <i class="fas fa-chart-line mr-1"></i>Reports
                </a>
            </div>
        </div>
    </div>
</body>
</html>