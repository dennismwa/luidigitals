<?php
require_once '../config/database.php';
requireLogin();

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Get filters from URL parameters
    $typeFilter = $_GET['type'] ?? '';
    $categoryFilter = intval($_GET['category'] ?? 0);
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $search = sanitizeInput($_GET['search'] ?? '');
    
    // Build WHERE clause
    $whereConditions = ["t.user_id = ?"];
    $params = [$user_id];
    
    if ($typeFilter && in_array($typeFilter, ['income', 'expense'])) {
        $whereConditions[] = "t.type = ?";
        $params[] = $typeFilter;
    }
    
    if ($categoryFilter > 0) {
        $whereConditions[] = "t.category_id = ?";
        $params[] = $categoryFilter;
    }
    
    if ($dateFrom) {
        $whereConditions[] = "DATE(t.transaction_date) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $whereConditions[] = "DATE(t.transaction_date) <= ?";
        $params[] = $dateTo;
    }
    
    if ($search) {
        $whereConditions[] = "(t.description LIKE ? OR t.reference_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get transactions
    $transactions = $db->fetchAll(
        "SELECT t.*, c.name as category_name, b.name as bill_name
         FROM transactions t
         LEFT JOIN categories c ON t.category_id = c.id
         LEFT JOIN bills b ON t.bill_id = b.id
         WHERE $whereClause
         ORDER BY t.transaction_date DESC",
        $params
    );
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create file handle
    $output = fopen('php://output', 'w');
    
    // Add CSV headers
    fputcsv($output, [
        'Date',
        'Type',
        'Amount',
        'Description',
        'Category',
        'Payment Method',
        'Reference Number',
        'Balance After',
        'Bill Name',
        'Notes',
        'Created At'
    ]);
    
    // Add transaction data
    foreach ($transactions as $transaction) {
        fputcsv($output, [
            formatDate($transaction['transaction_date'], 'Y-m-d H:i:s'),
            ucfirst($transaction['type']),
            number_format($transaction['amount'], 2),
            $transaction['description'],
            $transaction['category_name'] ?? 'Uncategorized',
            ucfirst(str_replace('_', ' ', $transaction['payment_method'])),
            $transaction['reference_number'] ?? '',
            number_format($transaction['balance_after'], 2),
            $transaction['bill_name'] ?? '',
            $transaction['notes'] ?? '',
            formatDate($transaction['created_at'], 'Y-m-d H:i:s')
        ]);
    }
    
    fclose($output);
    exit;
    
} catch (Exception $e) {
    // Redirect back with error
    header('Location: ../transactions.php?error=' . urlencode('Export failed: ' . $e->getMessage()));
    exit;
}
?>