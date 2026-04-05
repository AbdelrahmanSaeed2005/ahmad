<?php
// includes/dashboard_functions.php
require_once 'db_connect.php';

// دالة للحصول على تاريخ آخر تصفية أرباح
function getLastProfitWithdrawalDate($pdo) {
    $stmt = $pdo->prepare("SELECT MAX(created_at) AS last_date FROM profit_withdrawals");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['last_date'] ?? date('Y-m-d H:i:s', strtotime('-1 month'));
}

// دالة لحساب ربح البضاعة منذ آخر تصفية
function getProfitSinceLastWithdrawal($pdo) {
    $lastDate = getLastProfitWithdrawalDate($pdo);
    $stmt = $pdo->prepare("
        SELECT SUM((si.unit_price - p.cost_price) * si.quantity) AS total_profit
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        WHERE s.created_at > ?
    ");
    $stmt->execute([$lastDate]);
    $result = $stmt->fetch();
    return $result['total_profit'] ?? 0;
}

// دالة لحساب إجمالي المصاريف منذ آخر تصفية
function getExpensesSinceLastWithdrawal($pdo) {
    $lastDate = getLastProfitWithdrawalDate($pdo);
    $stmt = $pdo->prepare("SELECT SUM(amount) AS total_expenses FROM expenses WHERE created_at > ?");
    $stmt->execute([$lastDate]);
    $result = $stmt->fetch();
    return $result['total_expenses'] ?? 0;
}

// دالة لحساب صافي الربح المستحق
function getNetProfitDue($pdo) {
    $profit = getProfitSinceLastWithdrawal($pdo);
    $expenses = getExpensesSinceLastWithdrawal($pdo);
    return $profit - $expenses;
}

// دالة لحساب صافي مبيعات اليوم (نقدي)
function getTodayCashSales($pdo) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT SUM(total_amount) AS total_sales FROM sales WHERE DATE(created_at) = ? AND payment_method = 'cash'");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    return $result['total_sales'] ?? 0;
}

// دالة لحساب عدد الفواتير اليوم
function getTodayInvoiceCount($pdo) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) AS invoice_count FROM sales WHERE DATE(created_at) = ?");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    return $result['invoice_count'] ?? 0;
}

// دالة للحصول على المخزون المنخفض
function getLowStockProducts($pdo) {
    $stmt = $pdo->prepare("SELECT name, stock_quantity FROM products WHERE stock_quantity < 5 AND deleted_at IS NULL ORDER BY stock_quantity ASC LIMIT 5");
    $stmt->execute();
    return $stmt->fetchAll();
}

// دالة لحساب صافي النقدية
function getCurrentCashBalance($pdo) {
    $stmt = $pdo->prepare("SELECT balance_after FROM cash_transactions ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['balance_after'] ?? 0;
}

// دالة لحساب عدد المرتجعات اليوم
function getTodayReturnsCount($pdo) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) AS returns_count FROM returns WHERE DATE(created_at) = ?");
    $stmt->execute([$today]);
    $result = $stmt->fetch();
    return $result['returns_count'] ?? 0;
}

// دالة لحساب رصيد السلفة
function getLoanBalance($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT SUM(amount) AS total_loans FROM loan_requests WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result['total_loans'] ?? 0;
}

// دوال للرسوم البيانية
function getMonthlySalesData($pdo) {
    $stmt = $pdo->prepare("
        SELECT MONTH(created_at) AS month, SUM(total_amount) AS sales
        FROM sales
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY MONTH(created_at)
        ORDER BY MONTH(created_at)
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMonthlyExpensesData($pdo) {
    $stmt = $pdo->prepare("
        SELECT MONTH(created_at) AS month, SUM(amount) AS expenses
        FROM expenses
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY MONTH(created_at)
        ORDER BY MONTH(created_at)
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTopProductsData($pdo) {
    $stmt = $pdo->prepare("
        SELECT p.name, SUM(si.quantity) AS total_sold
        FROM sale_items si
        JOIN products p ON si.product_id = p.id
        GROUP BY p.id
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// دالة لحساب الصافي في الدرج
function getCurrentDrawerBalance($pdo) {
    $stmt = $pdo->prepare("SELECT balance_after FROM cash_transactions ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['balance_after'] ?? 0;
}

// دالة لحساب رصيد فودافون كاش
function getVodafoneCashBalance($pdo) {
    $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM cash_transactions WHERE type='income' AND description LIKE '%vodafone%'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['total'] ?? 0;
}

// دالة لحساب حساب البنك
function getBankBalance($pdo) {
    $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM cash_transactions WHERE type='income' AND description LIKE '%bank%'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['total'] ?? 0;
}

// دالة لحساب ديون العملاء
function getCustomerDebts($pdo) {
    $stmt = $pdo->prepare("SELECT SUM(balance) AS total_debts FROM customers WHERE balance > 0");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['total_debts'] ?? 0;
}

// دالة لحساب نواقص المخزن
function getLowStockCount($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS low_stock FROM products WHERE stock_quantity < 5 AND deleted_at IS NULL");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['low_stock'] ?? 0;
}

// دالة لحساب مصاريف إجمالي
function getTotalExpenses($pdo) {
    $stmt = $pdo->prepare("SELECT SUM(amount) AS total_expenses FROM expenses");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['total_expenses'] ?? 0;
}

// دالة لحساب مبيعات كاش إجمالي
function getTotalCashSales($pdo) {
    $stmt = $pdo->prepare("SELECT SUM(total_amount) AS total_cash_sales FROM sales WHERE payment_method = 'cash'");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result['total_cash_sales'] ?? 0;
}

// دالة للبحث عن المنتجات
function searchProducts($pdo, $query) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE (name LIKE ? OR barcode LIKE ?) AND deleted_at IS NULL AND stock_quantity > 0");
    $stmt->execute(["%$query%", "%$query%"]);
    return $stmt->fetchAll();
}

// دالة للحصول على منتج واحد
function getProductById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// دالة لحفظ الفاتورة
function saveSale($pdo, $user_id, $customer_id, $total_amount, $payment_method, $items) {
    $invoice_number = 'INV-' . date('Ymd') . '-' . rand(1000, 9999);
    $stmt = $pdo->prepare("INSERT INTO sales (invoice_number, user_id, customer_id, total_amount, payment_method) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$invoice_number, $user_id, $customer_id, $total_amount, $payment_method]);
    $sale_id = $pdo->lastInsertId();

    foreach ($items as $item) {
        $stmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$sale_id, $item['id'], $item['quantity'], $item['selling_price'], $item['total']]);
        $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
        $stmt->execute([$item['quantity'], $item['id']]);
    }

    if ($payment_method === 'cash') {
        $stmt = $pdo->prepare("INSERT INTO cash_transactions (type, amount, description, related_id, related_type, balance_after, user_id) VALUES ('income', ?, 'Sale payment', ?, 'sale', (SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type='income') - (SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type='expense'), ?)");
        $stmt->execute([$total_amount, $sale_id, $user_id]);
    }

    logAudit($pdo, $user_id, 'sale_created', "Invoice: $invoice_number, Total: $total_amount");
    return $invoice_number;
}

// دالة للتحقق من إمكانية البيع
function canSellProduct($product, $quantity, $selling_price) {
    if ($product['stock_quantity'] < $quantity) return 'مخزون غير كافي';
    if ($selling_price < $product['cost_price']) return 'السعر أقل من التكلفة';
    if ($selling_price < $product['min_selling_price']) return 'السعر أقل من الحد الأدنى';
    return true;
}
?>