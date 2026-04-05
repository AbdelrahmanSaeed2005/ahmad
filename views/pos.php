<?php 
/* File Path: views/pos.php - Modern UI 2026 Enhanced */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// --- 1. معالجة طلبات AJAX ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'search') {
        $q = $_GET['q'] ?? '';
        if (strlen($q) < 1) { echo json_encode([]); exit; }
        $stmt = $pdo->prepare("SELECT id, name, barcode, selling_price, min_selling_price, stock_quantity 
                               FROM products WHERE (name LIKE ? OR barcode LIKE ?) 
                               AND deleted_at IS NULL AND stock_quantity > 0 LIMIT 10");
        $searchQuery = "%$q%";
        $stmt->execute([$searchQuery, $searchQuery]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // مسح الباركود: تطابق تام مع حقل barcode أو مع رقم المنتج (نفس قيمة Code 128 المطبّعة من ID)
    if ($_GET['action'] === 'lookup_barcode') {
        header('Content-Type: application/json; charset=utf-8');
        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            echo json_encode(['ok' => false, 'msg' => 'رمز فارغ']);
            exit;
        }
        $idMatch = -1;
        if (preg_match('/^\d+$/', $code)) {
            $idMatch = (int) $code;
        }
        $stmt = $pdo->prepare(
            "SELECT id, name, barcode, selling_price, min_selling_price, stock_quantity 
             FROM products 
             WHERE deleted_at IS NULL AND (barcode = ? OR id = ?) 
             LIMIT 1"
        );
        $stmt->execute([$code, $idMatch]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['ok' => false, 'msg' => 'لم يُعثر على منتج بهذا الباركود']);
            exit;
        }
        echo json_encode(['ok' => true, 'product' => $row]);
        exit;
    }
    
    // ✅ إضافة بحث العملاء
    if ($_GET['action'] === 'search_customers') {
        $q = $_GET['q'] ?? '';
        if (strlen($q) < 1) { echo json_encode([]); exit; }
        $stmt = $pdo->prepare("SELECT id, name, phone_number FROM customers 
                               WHERE (name LIKE ? OR phone_number LIKE ?) 
                               AND deleted_at IS NULL ORDER BY name LIMIT 20");
        $searchQuery = "%$q%";
        $stmt->execute([$searchQuery, $searchQuery]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($_GET['action'] === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $pdo->beginTransaction();
            $total = $data['total'];
            $method = $data['method'];
            
            // معالجة العميل المؤقت
            $cust_id = null;
            $walkin_name = null;
            $walkin_phone = null;
            
            if (isset($data['walkin_customer']) && $data['walkin_customer']) {
                $walkin_name = $data['walkin_name'] ?? 'عميل نقدي';
                $walkin_phone = $data['walkin_phone'] ?? '';
            } else {
                $cust_id = $data['customer_id'] ?: null;
            }
            
            $user_id = $_SESSION['user_id'];
            
            foreach ($data['cart'] as $item) {
                $check_stmt = $pdo->prepare("SELECT name, selling_price, min_selling_price, stock_quantity, cost_price FROM products WHERE id = ? AND deleted_at IS NULL");
                $check_stmt->execute([$item['id']]);
                $product = $check_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) throw new Exception("المنتج غير موجود");
                if ($product['stock_quantity'] < $item['qty']) throw new Exception("الكمية غير كافية للمنتج " . $product['name']);
                if ($item['custom_price'] < $product['min_selling_price']) throw new Exception("السعر أقل من الحد الأدنى للمنتج " . $product['name']);
                if ((float) $item['custom_price'] < (float) $product['cost_price']) throw new Exception("لا يمكن البيع بأقل من تكلفة المنتج: " . $product['name']);
            }
            
            $stmt = $pdo->prepare("INSERT INTO invoices 
                (customer_id, user_id, total_amount, payment_method, walkin_customer_name, walkin_customer_phone) 
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$cust_id, $user_id, $total, $method, $walkin_name, $walkin_phone]);
            $sale_id = $pdo->lastInsertId();
            
            foreach ($data['cart'] as $item) {
                $stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                $stmt->execute([$sale_id, $item['id'], $item['qty'], $item['custom_price']]);
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")->execute([$item['qty'], $item['id']]);
            }
            
            if ($method === 'credit' && $cust_id) {
                $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")->execute([$total, $cust_id]);
            } else {
                $description = "بيع فاتورة رقم #$sale_id - طريقة: $method";
                if ($walkin_name) {
                    $description .= " - عميل: $walkin_name";
                }
                recordTransaction($pdo, [
                    'direction' => 'in',
                    'amount' => (float) $total,
                    'payment_method' => $method,
                    'description' => $description,
                    'user_id' => (int) $user_id,
                    'related_type' => 'sale',
                    'related_id' => (int) $sale_id,
                ]);
                if ($method !== 'cash') {
                    $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE wallet_name = ?")->execute([$total, $method]);
                }
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'msg' => 'تمت العملية بنجاح! رقم الفاتورة: ' . $sale_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }
}
$customers = $pdo->query("SELECT id, name, phone_number FROM customers WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
require_once '../includes/header.php'; 
?>

<style>
    /* جميع الـ CSS الموجود كما هو - بدون تغيير */
    :root {
        --primary-color: #4f46e5;
        --primary-hover: #4338ca;
        --bg-body: #f8fafc;
        --bg-card: #ffffff;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --input-bg: #ffffff;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.12);
        --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        
        --mobile-padding: 0.75rem;
        --mobile-font-size: 0.9rem;
        --mobile-header-size: 1.1rem;
    }

    [data-theme="dark"] {
        --bg-body: #0f172a;
        --bg-card: #1e293b;
        --text-main: #f1f5f9;
        --text-muted: #94a3b8;
        --border-color: #334155;
        --input-bg: #1e293b;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.4);
    }

    body {
        background-color: var(--bg-body);
        color: var(--text-main);
        transition: var(--transition);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        -webkit-tap-highlight-color: transparent;
    }

    /* ===== MOBILE FIRST LAYOUT ===== */
    .pos-container {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
        padding: 0.75rem;
        max-width: 100%;
        overflow-x: hidden;
    }

    @media (min-width: 320px) {
        .pos-container {
            padding: 0.5rem;
            gap: 0.75rem;
        }
    }

    @media (min-width: 375px) {
        .pos-container {
            padding: 0.75rem;
            gap: 1rem;
        }
    }

    @media (min-width: 425px) {
        .pos-container {
            padding: 1rem;
            gap: 1.25rem;
        }
    }

    @media (min-width: 768px) {
        .pos-container {
            padding: 1.5rem;
            gap: 1.5rem;
        }
    }

    @media (min-width: 992px) {
        .pos-container {
            grid-template-columns: 1fr 380px;
        }
    }

    @media (min-width: 1400px) {
        .pos-container {
            max-width: 1600px;
            margin: 0 auto;
        }
    }

    .modern-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        overflow: hidden;
        width: 100%;
    }

    .search-section {
        position: relative;
        margin-bottom: 1rem;
    }

    @media (max-width: 480px) {
        .search-section {
            margin-bottom: 0.75rem;
        }
    }

    .search-input-group {
        display: flex;
        align-items: center;
        background: var(--bg-card);
        border: 2px solid var(--border-color);
        border-radius: 12px;
        padding: 0.5rem 0.75rem;
        transition: var(--transition);
    }

    @media (max-width: 480px) {
        .search-input-group {
            padding: 0.4rem 0.6rem;
        }
        
        .search-input-group input {
            font-size: 0.95rem;
        }
    }

    .search-input-group input {
        border: none;
        background: transparent;
        color: var(--text-main);
        width: 100%;
        padding: 0.5rem;
        outline: none;
        font-size: 1rem;
    }

    #searchResults {
        position: absolute;
        width: 100%;
        max-height: 250px;
        overflow-y: auto;
        z-index: 1050;
        background: var(--bg-card);
        border-radius: 12px;
        box-shadow: var(--shadow-md);
        margin-top: 0.25rem;
    }

    @media (max-width: 480px) {
        #searchResults {
            max-height: 200px;
        }
    }

    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }

    .cart-table {
        width: 100%;
        min-width: 400px;
    }

    @media (max-width: 768px) {
        .cart-table {
            min-width: 350px;
        }
    }

    @media (max-width: 480px) {
        .cart-table {
            min-width: 300px;
        }
    }

    @media (max-width: 360px) {
        .cart-table {
            min-width: 280px;
        }
    }

    @media (max-width: 420px) {
        .cart-table thead {
            display: none;
        }

        .cart-table tbody tr {
            display: block;
            margin-bottom: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 0.75rem;
            background: var(--bg-card);
        }

        .cart-table tbody td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border: none;
            text-align: right;
        }

        .cart-table tbody td:before {
            content: attr(data-label);
            font-weight: 600;
            color: var(--text-muted);
            margin-left: 1rem;
            font-size: 0.85rem;
        }

        .cart-table tbody td:last-child {
            border-top: 1px dashed var(--border-color);
            margin-top: 0.5rem;
            padding-top: 0.75rem;
        }
    }

    .qty-input, .price-input {
        background: var(--bg-body);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        border-radius: 8px;
        text-align: center;
        width: 80px;
        padding: 0.4rem;
        transition: var(--transition);
        font-size: 0.9rem;
    }

    @media (max-width: 480px) {
        .qty-input, .price-input {
            width: 65px;
            padding: 0.3rem;
            font-size: 0.85rem;
        }
    }

    @media (max-width: 360px) {
        .qty-input, .price-input {
            width: 55px;
            padding: 0.25rem;
            font-size: 0.8rem;
        }
    }

    .pos-sidebar {
        position: relative;
        top: 0;
        height: auto;
        width: 100%;
    }

    @media (min-width: 992px) {
        .pos-sidebar {
            position: sticky;
            top: 1rem;
            height: fit-content;
        }
    }

    #grandTotal {
        font-size: 2rem;
        word-break: break-word;
    }

    @media (max-width: 480px) {
        #grandTotal {
            font-size: 1.75rem;
        }
        
        #grandTotal small {
            font-size: 0.9rem;
        }
    }

    @media (max-width: 360px) {
        #grandTotal {
            font-size: 1.5rem;
        }
    }

    .form-select-lg {
        font-size: 1rem;
        padding: 0.75rem;
    }

    @media (max-width: 480px) {
        .form-select-lg {
            font-size: 0.95rem;
            padding: 0.6rem;
        }
    }

    .btn-primary-modern {
        background: var(--primary-color);
        color: white;
        border: none;
        border-radius: 12px;
        padding: 1rem;
        font-weight: 700;
        width: 100%;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        font-size: 1rem;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }

    @media (max-width: 480px) {
        .btn-primary-modern {
            padding: 0.875rem;
            font-size: 0.95rem;
            border-radius: 10px;
        }
    }

    .theme-switch {
        position: fixed;
        bottom: 1rem;
        left: 1rem;
        z-index: 9999;
        background: var(--primary-color);
        color: white;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: var(--shadow-md);
        transition: var(--transition);
    }

    @media (max-width: 480px) {
        .theme-switch {
            width: 40px;
            height: 40px;
            bottom: 0.75rem;
            left: 0.75rem;
        }
        
        .theme-switch i {
            font-size: 1.1rem;
        }
    }

    @media (max-width: 360px) {
        .theme-switch {
            width: 35px;
            height: 35px;
        }
    }

    .toast-container {
        padding: 0.75rem;
        max-width: 100%;
        z-index: 9999;
    }

    @media (max-width: 480px) {
        .toast-container {
            padding: 0.5rem;
            left: 0;
            right: 0;
            bottom: 0;
        }
        
        .toast {
            max-width: calc(100% - 1rem);
            margin: 0 auto 0.5rem;
            font-size: 0.9rem;
        }
    }

    /* أنماط جديدة للعميل المؤقت */
    .customer-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 0.5rem;
    }

    .customer-tab {
        flex: 1;
        padding: 0.75rem;
        border: none;
        background: transparent;
        color: var(--text-muted);
        font-weight: 600;
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        transition: var(--transition);
        cursor: pointer;
    }

    .customer-tab.active {
        color: var(--primary-color);
        border-bottom: 3px solid var(--primary-color);
        background: rgba(79, 70, 229, 0.05);
    }

    .walkin-fields {
        animation: slideDown 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    #customerDiv, #walkinDiv {
        transition: all 0.3s ease;
    }

    .form-control:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    #customerDiv {
        margin-bottom: 1rem;
    }

    /* ✅ أنماط جديدة للبحث عن العملاء */
    .customer-search-container {
        position: relative;
        margin-bottom: 1rem;
    }
    
    .customer-search-input {
        width: 100%;
        padding: 0.75rem;
        border: 2px solid var(--border-color);
        border-radius: 10px;
        background: var(--bg-card);
        color: var(--text-main);
        font-size: 0.95rem;
        transition: var(--transition);
    }
    
    .customer-search-input:focus {
        border-color: var(--primary-color);
        outline: none;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }
    
    .customer-search-results {
        position: absolute;
        width: 100%;
        max-height: 200px;
        overflow-y: auto;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: var(--shadow-md);
        z-index: 1060;
        display: none;
    }
    
    .customer-search-item {
        padding: 0.75rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid var(--border-color);
        transition: var(--transition);
    }
    
    .customer-search-item:hover {
        background: var(--bg-hover);
        color: var(--primary-color);
    }
    
    .customer-search-item:last-child {
        border-bottom: none;
    }
    
    .customer-search-item small {
        color: var(--text-muted);
        display: block;
        font-size: 0.75rem;
    }

    .form-select {
        background: var(--bg-card);
        border: 2px solid var(--border-color);
        color: var(--text-main);
        border-radius: 10px;
        padding: 0.75rem;
        width: 100%;
        font-size: 0.95rem;
    }

    .bg-light {
        background: var(--bg-body) !important;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    @media (max-width: 480px) {
        .bg-light {
            flex-direction: column;
            text-align: center;
        }
    }

    @keyframes fadeIn {
        from { 
            opacity: 0; 
            transform: translateY(10px); 
        }
        to { 
            opacity: 1; 
            transform: translateY(0); 
        }
    }

    .animate-in { 
        animation: fadeIn 0.4s ease-out forwards; 
    }

    button, 
    .btn, 
    .list-group-item,
    select {
        touch-action: manipulation;
    }

    .ripple {
        position: relative;
        overflow: hidden;
    }

    .ripple:after {
        content: "";
        display: block;
        position: absolute;
        width: 100%;
        height: 100%;
        top: 0;
        left: 0;
        pointer-events: none;
        background-image: radial-gradient(circle, #fff 10%, transparent 10.01%);
        background-repeat: no-repeat;
        background-position: 50%;
        transform: scale(10, 10);
        opacity: 0;
        transition: transform .5s, opacity 1s;
    }

    .ripple:active:after {
        transform: scale(0, 0);
        opacity: .3;
        transition: 0s;
    }

    ::-webkit-scrollbar {
        width: 4px;
        height: 4px;
    }

    ::-webkit-scrollbar-track {
        background: var(--bg-body);
    }

    ::-webkit-scrollbar-thumb {
        background: var(--text-muted);
        border-radius: 4px;
    }

    @supports (padding: max(0px)) {
        body {
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }
        
        .theme-switch {
            bottom: max(1rem, env(safe-area-inset-bottom));
            left: max(1rem, env(safe-area-inset-left));
        }
    }

    @media (orientation: landscape) and (max-height: 600px) {
        .pos-container {
            gap: 0.75rem;
        }
        
        .modern-card {
            border-radius: 12px;
        }
        
        .btn-primary-modern {
            padding: 0.75rem;
        }
    }

    @media (max-width: 320px) {
        :root {
            --mobile-padding: 0.5rem;
            --mobile-font-size: 0.85rem;
        }
        
        .pos-container {
            padding: 0.5rem;
        }
        
        h1.fw-black {
            font-size: 1.5rem;
        }
        
        .badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .text-muted.small {
            font-size: 0.75rem;
        }
        
        #grandTotal {
            font-size: 1.5rem;
        }
    }

    @media print {
        .theme-switch,
        .btn-primary-modern,
        #productSearch,
        .search-section {
            display: none !important;
        }
        
        .modern-card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        * {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }
    }

    :focus-visible {
        outline: 3px solid var(--primary-color);
        outline-offset: 2px;
    }

    #emptyCart {
        padding: 2rem 1rem !important;
    }

    @media (max-width: 480px) {
        #emptyCart {
            padding: 1.5rem 0.75rem !important;
        }
        
        #emptyCart i {
            font-size: 3rem !important;
        }
        
        #emptyCart p {
            font-size: 0.9rem;
        }
    }

    #itemCount {
        font-size: 0.9rem;
        padding: 0.4rem 0.8rem;
    }

    @media (max-width: 480px) {
        #itemCount {
            font-size: 0.8rem;
            padding: 0.3rem 0.6rem;
        }
    }

    .list-group-item {
        background: var(--bg-card);
        color: var(--text-main);
        border: none;
        border-bottom: 1px solid var(--border-color);
    }

    .list-group-item:active {
        background: var(--bg-hover);
    }

    .payment-grid {
        width: 100%;
    }

    .payment-grid select {
        width: 100%;
    }

    @media (max-width: 380px) {
        .d-flex.justify-content-between.align-items-center.p-3 {
            flex-direction: column;
            gap: 0.5rem;
            text-align: center;
        }
        
        .border-bottom h6 {
            margin-bottom: 0.5rem !important;
        }
    }

    [dir="rtl"] .me-2 {
        margin-left: 0.5rem !important;
        margin-right: 0 !important;
    }

    [dir="rtl"] .ms-auto {
        margin-right: auto !important;
        margin-left: 0 !important;
    }

    html {
        scroll-behavior: smooth;
    }

    .animate-in {
        will-change: transform, opacity;
    }

    /* مسح الباركود */
    .barcode-scan-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.5rem;
        flex-wrap: wrap;
    }
    .barcode-scan-actions .btn-scan {
        border: 2px dashed var(--border-color);
        background: var(--bg-card);
        color: var(--text-main);
        border-radius: 10px;
        padding: 0.45rem 0.85rem;
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        cursor: pointer;
        transition: var(--transition);
    }
    .barcode-scan-actions .btn-scan:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
        background: rgba(79, 70, 229, 0.06);
    }
    #scanPreview {
        margin-top: 0.5rem;
        padding: 0.6rem 0.75rem;
        border-radius: 10px;
        background: rgba(79, 70, 229, 0.08);
        border: 1px solid rgba(79, 70, 229, 0.2);
        font-size: 0.9rem;
        display: none;
    }
    #scanPreview.visible { display: block; }
    #barcodeReader.barcode-reader-host {
        width: 100%;
        min-height: 280px;
        border-radius: 12px;
        overflow: hidden;
        background: #000;
        position: relative;
        z-index: 1;
    }
    #barcodeReader video {
        object-fit: cover;
    }
</style>

<div class="pos-container">
    <div class="pos-main animate-in">
        <div class="search-section">
            <div class="search-input-group">
                <i class="bi bi-search text-muted fs-5"></i>
                <input type="text" id="productSearch" placeholder="ابحث أو امسح الباركود (F2) — السكانر يعمل هنا مباشرة..." autocomplete="off">
            </div>
            <div class="barcode-scan-actions">
                <button type="button" class="btn-scan" id="btnFocusSearch" title="إرجاع التركيز لحقل الباركود">
                    <i class="bi bi-keyboard"></i> تركيز الباركود
                </button>
                <button type="button" class="btn-scan" id="btnOpenCameraScan" title="مسح بالكاميرا">
                    <i class="bi bi-camera-fill"></i> مسح بالكاميرا
                </button>
            </div>
            <div id="scanPreview" class="animate-in"></div>
            <div id="searchResults" class="list-group position-absolute w-100 shadow-lg modern-card" style="z-index: 1050; border:none; display:none;"></div>
        </div>

        <div class="modern-card">
            <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                <h6 class="mb-0 fw-bold"><i class="bi bi-cart3 me-2 text-primary"></i>سلة المشتريات</h6>
                <span class="badge bg-soft-primary text-primary" id="itemCount">0 أصناف</span>
            </div>
            <div class="table-responsive" style="min-height: 500px;">
                <table class="table cart-table align-middle">
                    <thead>
                        <tr>
                            <th class="text-end">المنتج</th>
                            <th width="120">السعر</th>
                            <th width="120">الكمية</th>
                            <th width="120">الإجمالي</th>
                            <th width="50"></th>
                        </tr>
                    </thead>
                    <tbody id="cartBody">
                        </tbody>
                </table>
                <div id="emptyCart" class="text-center py-5">
                    <i class="bi bi-basket2 text-muted display-1 opacity-25"></i>
                    <p class="text-muted mt-3">السلة فارغة، ابدأ بإضافة منتجات</p>
                </div>
            </div>
        </div>
    </div>

    <div class="pos-sidebar animate-in" style="animation-delay: 0.1s;">
        <div class="modern-card p-4">
            <div class="text-center mb-4">
                <span class="text-muted small">المبلغ الإجمالي المستحق</span>
                <h1 class="fw-black mt-1" style="color: var(--primary-color);" id="grandTotal">0.00 <small class="fs-6">ج.م</small></h1>
            </div>

            <hr class="opacity-10 mb-4">

            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">طريقة الدفع</label>
                <div class="payment-grid d-flex flex-wrap gap-2">
                    <select class="form-select form-select-lg rounded-3" id="paymentMethod" onchange="toggleCustomer()">
                        <option value="cash">نقدي 💵</option>
                        <option value="credit">آجل 📝</option>
                        <option value="vodafone">فودافون كاش 📱</option>
                        <option value="bank">تحويل بنكي 🏦</option>
                    </select>
                </div>
            </div>

            <!-- علامات تبويب العملاء -->
            <div class="customer-tabs mb-3">
                <button type="button" class="customer-tab active" id="tabRegistered" onclick="switchCustomerType('registered')">
                    <i class="bi bi-person-badge me-1"></i>
                    عميل مسجل
                </button>
                <button type="button" class="customer-tab" id="tabWalkin" onclick="switchCustomerType('walkin')">
                    <i class="bi bi-person-plus me-1"></i>
                    عميل مؤقت (غير مسجل)
                </button>
            </div>

            <!-- قسم العميل المسجل مع إضافة البحث -->
            <div id="registeredCustomerDiv">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-primary">اختر العميل المسجل</label>
                    
                    <!-- ✅ إضافة حقل البحث عن العملاء -->
                    <div class="customer-search-container">
                        <input type="text" class="customer-search-input" id="customerSearch" placeholder="ابحث باسم العميل...">
                        <div class="customer-search-results" id="customerSearchResults"></div>
                    </div>
                    
                    <select class="form-select" id="customerId" style="display: none;" onchange="updateCustomerInfo()">
                        <option value="">-- اختر العميل --</option>
                        <?php foreach($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-phone="<?= htmlspecialchars($c['phone_number'] ?? '') ?>">
                                <?= htmlspecialchars($c['name']) ?> 
                                <?= !empty($c['phone_number']) ? ' - ' . htmlspecialchars($c['phone_number']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- قسم العميل المؤقت -->
            <div id="walkinDiv" style="display: none;">
                <div class="walkin-fields">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-success">
                            <i class="bi bi-person me-1"></i>
                            اسم العميل
                        </label>
                        <input type="text" class="form-control" id="walkinName" 
                               placeholder="مثال: أحمد محمد" value="عميل نقدي">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-success">
                            <i class="bi bi-telephone me-1"></i>
                            رقم الهاتف (اختياري)
                        </label>
                        <input type="text" class="form-control" id="walkinPhone" 
                               placeholder="مثال: 01234567890">
                    </div>
                </div>
            </div>

            <!-- قسم الآجل (للعملاء المسجلين فقط) -->
            <div id="creditWarning" class="mb-3 d-none">
                <div class="alert alert-warning small p-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    العملاء المؤقتين لا يمكنهم الشراء بالآجل
                </div>
            </div>

            <button class="btn-primary-modern mt-2" onclick="processSale()">
                <i class="bi bi-shield-check"></i>
                إتمام الفاتورة (Enter)
            </button>

            <div class="mt-4 p-3 rounded-3 bg-light border text-center small text-muted d-flex justify-content-between">
                <span>رقم الجلسة: #<?= date('mdHi') ?></span>
                <span>المستخدم: <?= $_SESSION['user_name'] ?? 'Admin' ?></span>
            </div>
        </div>
    </div>
</div>

<div class="theme-switch" onclick="toggleTheme()" title="تبديل الوضع">
    <i id="themeIcon" class="bi bi-moon-stars-fill"></i>
</div>

<div class="toast-container position-fixed bottom-0 start-0 p-4"></div>

<div class="modal fade" id="cameraScanModal" tabindex="-1" aria-hidden="true" data-bs-focus="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-upc-scan me-2 text-primary"></i>مسح الباركود بالكاميرا</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="small text-muted mb-2">اسمح باستخدام الكاميرا عند طلب المتصفح. على اللاب تُستخدم عادة كاميرا أمامية؛ على الجوال يُفضّل الخلفية.</p>
                <div id="cameraScanStatus" class="small text-primary mb-2" style="min-height:1.25rem;"></div>
                <div id="barcodeReader" class="barcode-reader-host"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let cart = [];
let customerType = 'registered'; // registered or walkin

// --- Theme Management ---
function toggleTheme() {
    const root = document.documentElement;
    const current = root.getAttribute('data-theme');
    const target = current === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', target);
    localStorage.setItem('pos_theme', target);
    document.getElementById('themeIcon').className = target === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
}

let html5QrScanner = null;

function focusProductSearch() {
    const el = document.getElementById('productSearch');
    if (el) {
        el.focus();
        el.select();
    }
}

function showScanPreview(p) {
    const box = document.getElementById('scanPreview');
    if (!box) return;
    box.innerHTML = '<strong>' + escapeHtml(p.name) + '</strong> — <span class="text-primary fw-bold">' + Number(p.selling_price).toFixed(2) + '</span> <small class="text-muted">ج.م</small> · متاح: ' + p.stock_quantity;
    box.classList.add('visible');
}

function hideScanPreview() {
    const box = document.getElementById('scanPreview');
    if (box) {
        box.classList.remove('visible');
        box.innerHTML = '';
    }
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function lookupBarcodeAndAdd(rawCode, opts) {
    const code = (rawCode || '').trim();
    if (!code) return Promise.resolve();
    const url = 'pos.php?action=lookup_barcode&code=' + encodeURIComponent(code);
    return fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.ok || !res.product) {
                showToast(res.msg || 'المنتج غير موجود', 'error');
                hideScanPreview();
                return;
            }
            showScanPreview(res.product);
            if (opts && opts.previewOnly) return;
            addToCart(res.product);
        })
        .catch(function () {
            showToast('تعذر الاتصال بالخادم', 'error');
            hideScanPreview();
        });
}

async function stopCameraScanner() {
    if (!html5QrScanner) return;
    try {
        await html5QrScanner.stop();
    } catch (e) { /* */ }
    try {
        html5QrScanner.clear();
    } catch (e2) { /* */ }
    html5QrScanner = null;
}

function isCameraScanModalOpen() {
    const m = document.getElementById('cameraScanModal');
    return !!(m && m.classList.contains('show'));
}

function buildBarcodeScanConfig() {
    const formats = (typeof Html5QrcodeSupportedFormats !== 'undefined')
        ? [
            Html5QrcodeSupportedFormats.CODE_128,
            Html5QrcodeSupportedFormats.EAN_13,
            Html5QrcodeSupportedFormats.EAN_8,
            Html5QrcodeSupportedFormats.UPC_A,
            Html5QrcodeSupportedFormats.UPC_E,
            Html5QrcodeSupportedFormats.CODE_39
        ]
        : undefined;
    const config = {
        fps: 10,
        disableFlip: false,
        aspectRatio: 1.3333333,
        qrbox: function (viewfinderW, viewfinderH) {
            const w = Math.floor(viewfinderW * 0.92);
            const h = Math.max(80, Math.floor(Math.min(viewfinderH, viewfinderW) * 0.35));
            return { width: w, height: h };
        }
    };
    if (formats) {
        config.formatsToSupport = formats;
    }
    return config;
}

function pickPreferredCameraId(devices) {
    if (!devices || !devices.length) return null;
    const back = devices.find(function (d) {
        const l = (d.label || '').toLowerCase();
        return /back|rear|environment|wide|خلف|خلفية|واسع/.test(l);
    });
    return (back || devices[0]).id;
}

function humanizeCameraError(err) {
    const name = err && err.name ? String(err.name) : '';
    const msg = (err && err.message) ? String(err.message) : '';
    if (name === 'NotAllowedError' || /permission/i.test(msg)) {
        return 'تم رفض إذن الكاميرا. افتح إعدادات المتصفح للموقع واسمح بالكاميرا، ثم أعد المحاولة.';
    }
    if (name === 'NotFoundError' || /no camera|not found/i.test(msg)) {
        return 'لم يُعثر على كاميرا متصلة أو مفعّلة.';
    }
    if (name === 'NotReadableError' || /busy|in use|occupied/i.test(msg)) {
        return 'الكاميرا مستخدمة من تطبيق آخر. أغلق التطبيق الآخر ثم أعد المحاولة.';
    }
    if (name === 'SecurityError' || /secure context|https/i.test(msg)) {
        return 'المتصفح يمنع الكاميرا: استخدم https أو ادخل من localhost، أو جرّب متصفحاً آخر (Chrome / Edge).';
    }
    if (name === 'OverconstrainedError' || /constraint|overconstrained/i.test(msg)) {
        return 'إعدادات الكاميرا غير مدعومة. جرّب كاميرا أخرى من إعدادات الجهاز.';
    }
    return msg || 'تعذر تشغيل الكاميرا.';
}

/** سكانر USB يعمل كلوحة مفاتيح: توجيه المدخلات إلى حقل البحث ما لم تكن في حقل نصوص آخر */
function initUsbBarcodeScannerRouting() {
    document.addEventListener('keydown', function (eV) {
        if (eV.ctrlKey || eV.metaKey || eV.altKey) return;
        if (isCameraScanModalOpen()) return;

        const active = document.activeElement;
        const tag = active && active.tagName ? active.tagName.toLowerCase() : '';

        if (active && active.isContentEditable) return;
        if (active && active.id === 'productSearch') return;

        if (tag === 'textarea') return;
        if (tag === 'input') {
            const id = active.id || '';
            if (id === 'customerSearch' || id === 'walkinName' || id === 'walkinPhone') return;
            if (active.classList.contains('qty-input') || active.classList.contains('price-input')) return;
        }
        if (tag === 'select' || tag === 'option') return;

        if (eV.key === 'Enter' && (tag === 'button' || tag === 'a')) return;

        const isPrintable = eV.key.length === 1 && eV.key >= ' ' && eV.key <= '~';
        if (!isPrintable && eV.key !== 'Enter') return;

        const ps = document.getElementById('productSearch');
        if (!ps) return;

        eV.preventDefault();
        eV.stopPropagation();
        ps.focus();
        if (isPrintable) {
            ps.value = ps.value + eV.key;
            ps.dispatchEvent(new Event('input', { bubbles: true }));
        } else if (eV.key === 'Enter') {
            const q = ps.value.trim();
            const res = document.getElementById('searchResults');
            if (res) res.style.display = 'none';
            if (q.length >= 1) lookupBarcodeAndAdd(q);
        }
    }, true);
}

async function recreateBarcodeReaderElement() {
    const readerEl = document.getElementById('barcodeReader');
    if (!readerEl) return;
    await stopCameraScanner();
    readerEl.innerHTML = '';
    html5QrScanner = new Html5Qrcode('barcodeReader');
}

async function tryStartCamera(cameraConfig, scanConfig, onDecoded) {
    await recreateBarcodeReaderElement();
    await html5QrScanner.start(
        cameraConfig,
        scanConfig,
        onDecoded,
        function () { /* frame noise */ }
    );
}

async function runBarcodeCameraSession(camModal) {
    const statusEl = document.getElementById('cameraScanStatus');
    if (statusEl) statusEl.textContent = 'جاري تشغيل الكاميرا…';

    if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        showToast('للكاميرا من عنوان شبكة (مثل 192.168…) قد تحتاج https. جرّب Chrome أو Edge واسمح بالكاميرا للموقع.', 'warning');
    }

    const scanConfig = buildBarcodeScanConfig();

    const onDecoded = async function (decodedText) {
        await stopCameraScanner();
        if (statusEl) statusEl.textContent = '';
        await lookupBarcodeAndAdd(decodedText);
        const inst = bootstrap.Modal.getInstance(camModal);
        if (inst) inst.hide();
    };

    let lastErr = null;

    try {
        const devices = await Html5Qrcode.getCameras();
        if (devices && devices.length) {
            const preferredId = pickPreferredCameraId(devices);
            const rest = devices.filter(function (d) { return d.id !== preferredId; });
            const prefDev = devices.find(function (d) { return d.id === preferredId; });
            const ordered = (prefDev ? [prefDev] : []).concat(rest);
            for (let di = 0; di < ordered.length; di++) {
                try {
                    if (statusEl) statusEl.textContent = 'فتح الكاميرا…';
                    await tryStartCamera(ordered[di].id, scanConfig, onDecoded);
                    if (statusEl) statusEl.textContent = '';
                    return;
                } catch (errDev) {
                    lastErr = errDev;
                    await stopCameraScanner();
                }
            }
        }
    } catch (e) {
        lastErr = e;
    }

    const tryList = [
        { label: 'كاميرا خلفية', config: { facingMode: 'environment' } },
        { label: 'كاميرا أمامية', config: { facingMode: 'user' } },
        { label: 'افتراضي', config: {} }
    ];

    for (let i = 0; i < tryList.length; i++) {
        try {
            if (statusEl) statusEl.textContent = 'محاولة: ' + tryList[i].label + '…';
            await tryStartCamera(tryList[i].config, scanConfig, onDecoded);
            if (statusEl) statusEl.textContent = '';
            return;
        } catch (err) {
            lastErr = err;
            await stopCameraScanner();
        }
    }

    if (statusEl) statusEl.textContent = '';
    showToast(humanizeCameraError(lastErr), 'error');
    const inst = bootstrap.Modal.getInstance(camModal);
    if (inst) inst.hide();
}

document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('pos_theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    document.getElementById('themeIcon').className = savedTheme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    
    // تهيئة علامات التبويب
    initCustomerTabs();
    
    // تهيئة البحث عن العملاء
    initCustomerSearch();

    focusProductSearch();
    document.getElementById('btnFocusSearch').addEventListener('click', focusProductSearch);

    const camModal = document.getElementById('cameraScanModal');
    document.getElementById('btnOpenCameraScan').addEventListener('click', function () {
        bootstrap.Modal.getOrCreateInstance(camModal).show();
    });

    camModal.addEventListener('shown.bs.modal', function () {
        const statusEl = document.getElementById('cameraScanStatus');
        if (statusEl) statusEl.textContent = '';
        window.requestAnimationFrame(function () {
            window.setTimeout(function () {
                runBarcodeCameraSession(camModal);
            }, 200);
        });
    });

    camModal.addEventListener('hidden.bs.modal', async function () {
        const statusEl = document.getElementById('cameraScanStatus');
        if (statusEl) statusEl.textContent = '';
        await stopCameraScanner();
        focusProductSearch();
    });

    /* سكانر USB (لوحة مفاتيح): توجيه الأحرف إلى حقل الباركود تلقائياً */
    initUsbBarcodeScannerRouting();

    const productSearchEl = document.getElementById('productSearch');
    productSearchEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const q = this.value.trim();
            document.getElementById('searchResults').style.display = 'none';
            if (q.length >= 1) {
                lookupBarcodeAndAdd(q);
            }
        }
    });
});

// ✅ دالة تهيئة البحث عن العملاء
function initCustomerSearch() {
    const searchInput = document.getElementById('customerSearch');
    const searchResults = document.getElementById('customerSearchResults');
    const customerSelect = document.getElementById('customerId');
    
    if (!searchInput) return;
    
    let searchTimeout;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 1) {
            searchResults.style.display = 'none';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            fetch(`pos.php?action=search_customers&q=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.length === 0) {
                        searchResults.innerHTML = '<div class="customer-search-item text-muted">لا توجد نتائج</div>';
                    } else {
                        let html = '';
                        data.forEach(customer => {
                            html += `
                                <div class="customer-search-item" onclick="selectCustomer(${customer.id}, '${customer.name.replace(/'/g, "\\'")}', '${customer.phone_number || ''}')">
                                    <strong>${customer.name}</strong>
                                    ${customer.phone_number ? `<small>📞 ${customer.phone_number}</small>` : ''}
                                </div>
                            `;
                        });
                        searchResults.innerHTML = html;
                    }
                    searchResults.style.display = 'block';
                });
        }, 300);
    });
    
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });
}

// ✅ دالة اختيار العميل من نتائج البحث
function selectCustomer(id, name, phone) {
    const customerSelect = document.getElementById('customerId');
    const searchInput = document.getElementById('customerSearch');
    const searchResults = document.getElementById('customerSearchResults');
    
    // تحديث الـ select
        for (let i = 0; i < customerSelect.options.length; i++) {
        if (customerSelect.options[i].value == id) {
            customerSelect.selectedIndex = i;
            break;
        }
    }
    
    // تحديث حقل البحث
    searchInput.value = name;
    searchResults.style.display = 'none';
    
    // تحديث معلومات العميل
    updateCustomerInfo();
}

// ✅ دالة تهيئة علامات تبويب العملاء
function initCustomerTabs() {
    switchCustomerType('registered');
}

// ✅ دالة التبديل بين العميل المسجل والمؤقت
function switchCustomerType(type) {
    customerType = type;
    
    // تحديث شكل التبويبات
    document.getElementById('tabRegistered').classList.toggle('active', type === 'registered');
    document.getElementById('tabWalkin').classList.toggle('active', type === 'walkin');
    
    // إظهار/إخفاء الأقسام المناسبة
    document.getElementById('registeredCustomerDiv').style.display = type === 'registered' ? 'block' : 'none';
    document.getElementById('walkinDiv').style.display = type === 'walkin' ? 'block' : 'none';
    
    // التحقق من طريقة الدفع
    checkPaymentMethod();
}

// ✅ دالة تحديث معلومات العميل (للعرض فقط)
function updateCustomerInfo() {
    // يمكن إضافة أي منطق إضافي هنا إذا لزم الأمر
}

// ✅ دالة التحقق من طريقة الدفع
function checkPaymentMethod() {
    const paymentMethod = document.getElementById('paymentMethod').value;
    const creditWarning = document.getElementById('creditWarning');
    
    if (customerType === 'walkin' && paymentMethod === 'credit') {
        creditWarning.classList.remove('d-none');
        // إجبار تغيير طريقة الدفع
        document.getElementById('paymentMethod').value = 'cash';
        showToast('العملاء المؤقتين لا يمكنهم الشراء بالآجل. تم تغيير طريقة الدفع إلى نقدي', 'warning');
    } else {
        creditWarning.classList.add('d-none');
    }
}

// ✅ تعديل دالة toggleCustomer الموجودة
function toggleCustomer() {
    checkPaymentMethod();
}

// --- Toast Logic (UI Improved) ---
function showToast(message, type = 'info') {
    const container = document.querySelector('.toast-container');
    const id = 't' + Date.now();
    const colors = { 
        success: '#10b981', 
        error: '#ef4444', 
        warning: '#f59e0b', 
        info: '#3b82f6' 
    };
    
    const html = `
        <div id="${id}" class="toast align-items-center text-white border-0 mb-2 animate-in" style="background:${colors[type]}; border-radius:12px; backdrop-filter: blur(8px);">
            <div class="d-flex p-3">
                <div class="toast-body"><i class="bi bi-info-circle me-2"></i> ${message}</div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`;
    container.insertAdjacentHTML('beforeend', html);
    const el = document.getElementById(id);
    new bootstrap.Toast(el, { delay: 3000 }).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

// --- Logic Implementation (Same as your original functions, improved UI binding) ---

document.getElementById('productSearch').addEventListener('input', function() {
    let q = this.value;
    const resultsDiv = document.getElementById('searchResults');
    if(q.length < 1) { resultsDiv.style.display = 'none'; return; }
    
    fetch(`pos.php?action=search&q=${encodeURIComponent(q)}`)
        .then(res => res.json())
        .then(data => {
            let html = '';
            if (data.length === 0) {
                html = '<div class="list-group-item text-muted text-center p-3">لم يتم العثور على نتائج</div>';
            } else {
                data.forEach(p => {
                    html += `
<button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-3 border-0" onclick='addToCart(${JSON.stringify(p)})'>
                        <div>
                            <span class="fw-bold d-block text-main">${p.name}</span>
                            <small class="text-muted">${p.barcode} | متاح: ${p.stock_quantity}</small>
                        </div>
                        <span class="badge bg-primary rounded-pill">${p.selling_price} ج.م</span>
                    </button>`;
                });
            }
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        });
});

function addToCart(product) {
    if (product.stock_quantity <= 0) {
        showToast('عذراً: المنتج نفذ من المخزن', 'error');
        hideScanPreview();
        return;
    }
    let item = cart.find(i => i.id === product.id);
    if(item) {
        if (item.qty + 1 > product.stock_quantity) {
            showToast(`الكمية المتاحة فقط ${product.stock_quantity}`, 'warning');
            hideScanPreview();
            return;
        }
        item.qty++;
    } else {
        cart.push({...product, qty: 1, custom_price: product.selling_price});
    }
    renderCart();
    document.getElementById('searchResults').style.display = 'none';
    document.getElementById('productSearch').value = '';
    showToast(`${product.name} — ${Number(product.selling_price).toFixed(2)} ج.م · أضيف للسلة`, 'success');
    hideScanPreview();
    focusProductSearch();
}

function updatePrice(index, val) {
    let price = parseFloat(val);
    let product = cart[index];
    if (isNaN(price) || price < product.min_selling_price) {
        showToast(`الحد الأدنى للبيع ${product.min_selling_price}`, 'error');
        renderCart();
        return;
    }
    cart[index].custom_price = price;
    renderCart();
}

function updateQuantity(index, val) {
    let qty = parseInt(val);
    let product = cart[index];
    if (isNaN(qty) || qty <= 0 || qty > product.stock_quantity) {
        showToast(`الكمية غير متاحة`, 'error');
        renderCart();
        return;
    }
    cart[index].qty = qty;
    renderCart();
}

function renderCart() {
    let html = '';
    let total = 0;
    const cartBody = document.getElementById('cartBody');
    const emptyDiv = document.getElementById('emptyCart');

    if(cart.length === 0) {
        cartBody.innerHTML = '';
        emptyDiv.style.display = 'block';
        document.getElementById('grandTotal').innerText = "0.00 ج.م";
        document.getElementById('itemCount').innerText = "0 أصناف";
        return;
    }

    emptyDiv.style.display = 'none';
    cart.forEach((item, index) => {
        let sub = item.qty * item.custom_price;
        total += sub;
        html += `
        <tr class="animate-in">
            <td class="text-end">
                <span class="fw-bold text-main d-block">${item.name}</span>
                <small class="text-muted">الحد الأدنى: ${item.min_selling_price}</small>
            </td>
            <td>
                <input type="number" class="price-input" value="${item.custom_price}" onchange="updatePrice(${index}, this.value)" step="0.01">
            </td>
            <td>
                <input type="number" class="qty-input" value="${item.qty}" onchange="updateQuantity(${index}, this.value)">
            </td>
            <td class="fw-bold text-main">${sub.toFixed(2)}</td>
            <td>
                <button class="btn btn-link text-danger p-0" onclick="cart.splice(${index},1); renderCart();">
                    <i class="bi bi-x-circle-fill fs-5"></i>
                </button>
            </td>
        </tr>`;
    });
    
    cartBody.innerHTML = html;
    document.getElementById('grandTotal').innerText = total.toFixed(2) + " ج.م";
    document.getElementById('itemCount').innerText = cart.length + " أصناف";
}

// ✅ تعديل دالة toggleCustomer
function toggleCustomer() {
    checkPaymentMethod();
}

// ✅ دالة processSale المعدلة لتدعم العملاء المؤقتين
function processSale() {
    let method = document.getElementById('paymentMethod').value;
    
    // التحقق من صحة البيانات حسب نوع العميل
    if (customerType === 'registered') {
        let custId = document.getElementById('customerId').value;
        if (method === 'credit' && !custId) { 
            showToast('اختر عميلاً للبيع الآجل', 'error'); 
            return; 
        }
    } else {
        // عميل مؤقت - لا يمكن البيع بالآجل
        if (method === 'credit') {
            showToast('العملاء المؤقتين لا يمكنهم الشراء بالآجل', 'error');
            return;
        }
    }
    
    if (cart.length === 0) { 
        showToast('السلة فارغة!', 'warning'); 
        return; 
    }
    
    const totalVal = cart.reduce((sum, i) => sum + (i.qty * i.custom_price), 0);
    
    // تجهيز البيانات للإرسال
    let postData = {
        cart: cart,
        total: totalVal,
        method: method
    };
    
    if (customerType === 'registered') {
        postData.customer_id = document.getElementById('customerId').value;
        postData.walkin_customer = false;
    } else {
        postData.walkin_customer = true;
        postData.walkin_name = document.getElementById('walkinName').value || 'عميل نقدي';
        postData.walkin_phone = document.getElementById('walkinPhone').value || '';
    }

    fetch('pos.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(postData)
    })
    .then(res => res.json())
    .then(res => {
        if(res.success) {
            showToast(res.msg, 'success');
            cart = [];
            renderCart();
            // إعادة تعيين الحقول
            document.getElementById('walkinName').value = 'عميل نقدي';
            document.getElementById('walkinPhone').value = '';
            document.getElementById('customerId').value = '';
            document.getElementById('customerSearch').value = '';
        } else {
            showToast(res.msg, 'error');
        }
    })
    .catch(error => {
        showToast('حدث خطأ في الاتصال بالخادم', 'error');
        console.error('Error:', error);
    });
}

// Shortcuts
window.addEventListener('keydown', e => {
    if (e.key === 'F2') {
        e.preventDefault();
        focusProductSearch();
    }
    if (e.key === 'Enter' && e.ctrlKey) processSale();
});

document.addEventListener('click', e => {
    if (!e.target.closest('.search-section')) document.getElementById('searchResults').style.display = 'none';
});
</script>

<?php require_once '../includes/footer.php'; ?>