<?php 
/* File Path: http://localhost/project/ERP_System_V2/views/supplier_report.php
 * Description: كشف حساب تفصيلي للمورد - نسخة محسنة بالكامل مع كروت مصغرة
 * التعديلات: تصغير حجم الكروت بنسبة 50%، تحسين المسافات، تبسيط التصميم
 */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

$supplier_id = $_GET['id'] ?? 0;

// 1. جلب بيانات المورد
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch();

if (!$supplier) {
    die("المورد غير موجود أو تم حذفه.");
}

// 2. معالجة دفع مبلغ للمورد
if (isset($_POST['pay_supplier'])) {
    $amount = $_POST['amount'];
    $payment_method = $_POST['payment_method']; 
    $notes = $_POST['notes'];
    $user_id = $_SESSION['user_id'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$amount, $supplier_id]);

        $desc = "تسديد دفعة للمورد: " . $supplier['name'] . " - " . $notes;
        recordTransaction($pdo, [
            'direction' => 'out',
            'amount' => (float) $amount,
            'payment_method' => $payment_method,
            'description' => $desc,
            'user_id' => (int) $user_id,
            'related_type' => 'supplier_payment',
            'supplier_id' => (int) $supplier_id,
            'payment_type' => 'cash',
        ]);
        if ($payment_method !== 'cash') {
            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE wallet_name = ?")->execute([(float) $amount, $payment_method]);
        }

        $pdo->commit();
        header("Location: supplier_report.php?id=$supplier_id&msg=paid"); exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die("خطأ في عملية الدفع: " . $e->getMessage());
    }
}

// 3. إعدادات الترقيم الصفحي
$limit = 10;
$page_purchases = isset($_GET['page_purchases']) ? (int)$_GET['page_purchases'] : 1;
if ($page_purchases < 1) $page_purchases = 1;
$offset_purchases = ($page_purchases - 1) * $limit;

$page_payments = isset($_GET['page_payments']) ? (int)$_GET['page_payments'] : 1;
if ($page_payments < 1) $page_payments = 1;
$offset_payments = ($page_payments - 1) * $limit;

// 4. جلب الإحصائيات
$total_purchases = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id = ?");
$total_purchases->execute([$supplier_id]);
$total_purchases_count = $total_purchases->fetchColumn();
$total_purchases_pages = ceil($total_purchases_count / $limit);

$total_payments = $pdo->prepare("SELECT COUNT(*) FROM cash_transactions WHERE supplier_id = ? AND related_type = 'supplier_payment'");
$total_payments->execute([$supplier_id]);
$total_payments_count = $total_payments->fetchColumn();
$total_payments_pages = ceil($total_payments_count / $limit);

// 5. جلب البيانات
$purchases_list = [];
$payments_list = [];

try {
    $purchases = $pdo->prepare("SELECT 'فاتورة شراء' as type, id, total_amount as amount, created_at, 'invoice' as category 
                                FROM purchases WHERE supplier_id = ? 
                                ORDER BY created_at DESC 
                                LIMIT $limit OFFSET $offset_purchases");
    $purchases->execute([$supplier_id]);
    $purchases_list = $purchases->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $payments = $pdo->prepare("SELECT 'دفعة مسددة' as type, id, amount, created_at, 'payment' as category, payment_method,
                               description, payment_type
                               FROM cash_transactions 
                               WHERE supplier_id = ? AND related_type = 'supplier_payment'
                               ORDER BY created_at DESC 
                               LIMIT $limit OFFSET $offset_payments");
    $payments->execute([$supplier_id]);
    $payments_list = $payments->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (PDOException $e) {
    $purchases_list = [];
    $payments_list = [];
}

$total_purchases_amount = $pdo->prepare("SELECT SUM(total_amount) FROM purchases WHERE supplier_id = ?");
$total_purchases_amount->execute([$supplier_id]);
$total_purchases_sum = $total_purchases_amount->fetchColumn() ?: 0;

$total_payments_amount = $pdo->prepare("SELECT SUM(amount) FROM cash_transactions WHERE supplier_id = ? AND related_type = 'supplier_payment'");
$total_payments_amount->execute([$supplier_id]);
$total_payments_sum = $total_payments_amount->fetchColumn() ?: 0;

require_once '../includes/header.php'; 
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5, user-scalable=yes">
    <title>كشف حساب المورد | <?= htmlspecialchars($supplier['name']) ?></title>
    
    <!-- Bootstrap 5 RTL -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* ===== VARIABLES ===== */
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --primary-dark: #3f37c9;
            --primary-soft: #e2eafc;
            
            --success: #06d6a0;
            --success-light: #d1fae5;
            --danger: #ef476f;
            --danger-light: #fee2e2;
            --warning: #ffb703;
            --warning-light: #fff3cd;
            --info: #4cc9f0;
            --info-light: #e1f3fa;
            
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --bg-hover: #f1f5f9;
            --bg-input: #ffffff;
            
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-muted: #64748b;
            
            --border-light: #e2e8f0;
            --border-medium: #cbd5e1;
            
            --shadow-xs: 0 1px 2px rgba(0,0,0,0.02);
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.02);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.03);
            --shadow-lg: 0 8px 12px rgba(0,0,0,0.04);
            --shadow-xl: 0 12px 24px rgba(0,0,0,0.05);
            
            --radius-xs: 4px;
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --radius-full: 9999px;
            
            --transition: all 0.2s ease;
        }
        
        [data-theme="dark"] {
            --primary: #4895ef;
            --primary-light: #5fa7f0;
            --primary-dark: #3f37c9;
            --primary-soft: #1e3a5f;
            
            --success: #34d399;
            --success-light: #064e3b;
            --danger: #f87171;
            --danger-light: #7f1d1d;
            --warning: #fbbf24;
            --warning-light: #78350f;
            --info: #60a5fa;
            --info-light: #1e3a8a;
            
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --bg-hover: #2d3a4f;
            --bg-input: #1e293b;
            
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            
            --border-light: #334155;
            --border-medium: #475569;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-primary);
            transition: background-color var(--transition), color var(--transition);
            min-height: 100vh;
            overflow-x: hidden;
            line-height: 1.5;
        }
        
        /* ===== THEME TOGGLE (مصغر) ===== */
        .theme-toggle {
            position: fixed;
            bottom: 15px;
            left: 15px;
            width: 36px;
            height: 36px;
            border-radius: var(--radius-full);
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--shadow-md);
            z-index: 9999;
            transition: var(--transition);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        
        .theme-toggle:hover {
            transform: scale(1.1) rotate(15deg);
            border-color: var(--primary);
        }
        
        .theme-toggle i {
            font-size: 1.1rem;
        }
        
        /* ===== MAIN CONTAINER ===== */
        .container-fluid {
            padding: 0.75rem;
            max-width: 100%;
        }
        
        @media (min-width: 768px) {
            .container-fluid {
                padding: 1rem;
            }
        }
        
        @media (min-width: 1200px) {
            .container-fluid {
                max-width: 1400px;
                margin: 0 auto;
                padding: 1.5rem;
            }
        }
        
        /* ===== HEADER SECTION (مصغر) ===== */
        .header-section {
            margin-bottom: 1.25rem;
        }
        
        .supplier-info {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
        }
        
        .supplier-name {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .supplier-name i {
            font-size: 1.3rem;
            color: var(--primary);
        }
        
        .supplier-details {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        
        .supplier-detail {
            color: var(--text-muted);
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: var(--bg-hover);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
        }
        
        .supplier-detail i {
            color: var(--primary);
            font-size: 0.8rem;
        }
        
        .action-button {
            background: linear-gradient(135deg, var(--success), #05b386);
            border: none;
            border-radius: var(--radius-md);
            padding: 0.6rem 1.2rem;
            font-size: 0.85rem;
            color: white;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }
        
        .action-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px -5px var(--success);
        }
        
        .action-button i {
            font-size: 0.9rem;
        }
        
        /* ===== STATS CARDS (مصغرة جداً) ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.6rem;
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 0.4rem;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: repeat(1, 1fr);
                gap: 0.5rem;
            }
        }
        
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 0.85rem 0.75rem;
            box-shadow: var(--shadow-xs);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-light);
        }
        
        .stat-card.bg-danger {
            background: linear-gradient(135deg, var(--danger), #d64161) !important;
            border: none;
        }
        
        .stat-card.bg-danger .stat-label,
        .stat-card.bg-danger .stat-value,
        .stat-card.bg-danger .stat-value small {
            color: white;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 0.2rem;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.3;
        }
        
        .stat-value small {
            font-size: 0.6rem;
            font-weight: 500;
            opacity: 0.8;
        }
        
        .stat-badge {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,0.2);
            color: white;
            display: inline-block;
            margin-top: 0.3rem;
        }
        
        .stat-meta {
            font-size: 0.6rem;
            color: var(--text-muted);
            margin-top: 0.3rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .stat-card.bg-danger .stat-meta {
            color: rgba(255,255,255,0.7);
        }
        
        /* ===== TABLE CARD (مصغر) ===== */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.25rem;
        }
        
        .table-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-light);
            background: var(--bg-hover);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .table-header h5 {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        
        .table-header h5 i {
            font-size: 0.9rem;
            color: var(--primary);
        }
        
        .table-header .badge {
            font-size: 0.65rem;
            padding: 0.3rem 0.6rem;
        }
        
        /* ===== TABLE STYLES (مصغرة) ===== */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            width: 100%;
            margin: 0;
            color: var(--text-primary);
            min-width: 700px;
        }
        
        .table thead th {
            background: var(--bg-body);
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 0.6rem 0.75rem;
            border: none;
            white-space: nowrap;
        }
        
        .table tbody tr {
            transition: var(--transition);
            border-bottom: 1px solid var(--border-light);
        }
        
        .table tbody tr:hover {
            background: var(--bg-hover);
        }
        
        .table tbody td {
            padding: 0.6rem 0.75rem;
            vertical-align: middle;
            font-size: 0.8rem;
        }
        
        .date-cell {
            font-weight: 500;
            font-size: 0.8rem;
            color: var(--text-primary);
        }
        
        .time-cell {
            font-size: 0.6rem;
            color: var(--text-muted);
            margin-top: 0.1rem;
        }
        
        .amount-invoice {
            color: var(--danger);
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: var(--danger-light);
            border-radius: var(--radius-sm);
        }
        
        .amount-payment {
            color: var(--success);
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: var(--success-light);
            border-radius: var(--radius-sm);
        }
        
        .badge-id {
            background: var(--bg-hover);
            color: var(--text-muted);
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.65rem;
            font-weight: 600;
            border: 1px solid var(--border-light);
            display: inline-block;
        }
        
        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: var(--radius-full);
            font-size: 0.6rem;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }
        
        .status-badge.invoice {
            background: var(--danger-light);
            color: var(--danger);
        }
        
        .status-badge.payment {
            background: var(--success-light);
            color: var(--success);
        }
        
        .payment-method-badge {
            padding: 0.15rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.6rem;
            font-weight: 500;
            background: var(--bg-hover);
            color: var(--text-muted);
            border: 1px solid var(--border-light);
            display: inline-block;
            white-space: nowrap;
        }
        
        .btn-view {
            background: transparent;
            border: 1px solid var(--border-light);
            color: var(--text-secondary);
            width: 28px;
            height: 28px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }
        
        .btn-view:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .btn-view i {
            font-size: 0.8rem;
        }
        
        /* ===== PAGINATION (مصغرة) ===== */
        .pagination-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            border-top: 1px solid var(--border-light);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .pagination-info {
            color: var(--text-muted);
            font-size: 0.7rem;
        }
        
        .pagination {
            gap: 0.2rem;
            margin: 0;
        }
        
        .pagination .page-link {
            border: none;
            background: var(--bg-card);
            color: var(--text-secondary);
            border-radius: var(--radius-sm);
            padding: 0.3rem 0.7rem;
            font-size: 0.75rem;
            font-weight: 500;
            box-shadow: var(--shadow-xs);
            transition: var(--transition);
        }
        
        .pagination .page-link:hover {
            background: var(--primary);
            color: white;
        }
        
        .pagination .page-item.active .page-link {
            background: var(--primary);
            color: white;
        }
        
        .pagination .page-item.disabled .page-link {
            background: var(--bg-hover);
            color: var(--text-muted);
            opacity: 0.6;
            pointer-events: none;
        }
        
        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: var(--text-muted);
            opacity: 0.3;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin: 0.25rem 0;
        }
        
        .empty-state .btn {
            margin-top: 0.5rem;
            font-size: 0.8rem;
            padding: 0.3rem 1rem;
        }
        
        /* ===== MODAL (مصغر) ===== */
        .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
        }
        
        .modal-header {
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
        }
        
        .modal-header.bg-success {
            background: linear-gradient(135deg, var(--success), #05b386) !important;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            font-size: 0.8rem;
        }
        
        .modal-header h5 {
            font-size: 1rem;
            font-weight: 600;
        }
        
        .modal-body {
            padding: 1.25rem;
        }
        
        .modal-footer {
            border-top: 1px solid var(--border-light);
            padding: 1rem 1.25rem;
        }
        
        .alert-soft-primary {
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: none;
            border-radius: var(--radius-md);
            padding: 0.75rem;
            font-size: 0.8rem;
        }
        
        /* Form controls */
        .form-control, .form-select {
            background: var(--bg-input);
            border: 1px solid var(--border-light);
            color: var(--text-primary);
            border-radius: var(--radius-md);
            padding: 0.6rem 0.75rem;
            transition: var(--transition);
            font-size: 0.9rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
            outline: none;
        }
        
        .form-control-lg {
            font-size: 1rem;
            padding: 0.75rem;
        }
        
        .input-group-text {
            background: var(--bg-hover);
            border: 1px solid var(--border-light);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        /* Summary card */
        .summary-card {
            background: var(--bg-hover);
            border-radius: var(--radius-md);
            padding: 0.75rem;
            margin-top: 1rem;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }
        
        .summary-row:last-child {
            margin-bottom: 0;
        }
        
        /* ===== FLOATING BUTTONS (مصغرة) ===== */
        .fab-container {
            position: fixed;
            bottom: 15px;
            right: 15px;
            z-index: 9998;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .fab-button {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-full);
            background: var(--primary);
            color: white;
            border: none;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }
        
        .fab-button:hover {
            transform: scale(1.1) rotate(90deg);
        }
        
        .fab-button i {
            font-size: 1.3rem;
        }
        
        .fab-button.success {
            background: var(--success);
        }
        
        .dropdown-fab {
            position: fixed;
            bottom: 15px;
            right: 75px;
            z-index: 9998;
        }
        
        .dropdown-fab .btn {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .dropdown-menu {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            padding: 0.4rem;
            min-width: 160px;
        }
        
        .dropdown-item {
            color: var(--text-primary);
            border-radius: var(--radius-sm);
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }
        
        .dropdown-item:hover {
            background: var(--bg-hover);
            color: var(--primary);
        }
        
        .dropdown-item i {
            font-size: 0.9rem;
        }
        
        /* ===== RESPONSIVE IMPROVEMENTS ===== */
        @media (max-width: 768px) {
            .supplier-name {
                font-size: 1.2rem;
            }
            
            .supplier-detail {
                font-size: 0.7rem;
                padding: 0.2rem 0.5rem;
            }
            
            .stat-value {
                font-size: 1rem;
            }
            
            .table-header h5 {
                font-size: 0.85rem;
            }
            
            .btn-view {
                width: 26px;
                height: 26px;
            }
            
            .btn-view i {
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 480px) {
            .container-fluid {
                padding: 0.5rem;
            }
            
            .supplier-info {
                padding: 1rem;
            }
            
            .supplier-name {
                font-size: 1.1rem;
            }
            
            .supplier-details {
                gap: 0.5rem;
            }
            
            .action-button {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
            .action-button {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                gap: 0.4rem;
            }
            
            .stat-card {
                padding: 0.7rem 0.6rem;
            }
            
            .stat-value {
                font-size: 0.95rem;
            }
            
            .stat-label {
                font-size: 0.6rem;
            }
            
            .table-header {
                padding: 0.6rem 0.75rem;
            }
            
            .table-header h5 {
                font-size: 0.8rem;
            }
            
            .table-header .badge {
                font-size: 0.6rem;
                padding: 0.2rem 0.4rem;
            }
            
            .pagination-wrapper {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .pagination-info {
                margin-bottom: 0.3rem;
            }
            
            .modal-body {
                padding: 1rem;
            }
            
            .fab-button {
                width: 44px;
                height: 44px;
            }
            
            .fab-button i {
                font-size: 1.2rem;
            }
            
            .dropdown-fab {
                right: 70px;
            }
            
            .dropdown-fab .btn {
                width: 36px;
                height: 36px;
            }
        }
        
        @media (max-width: 360px) {
            .stat-value {
                font-size: 0.9rem;
            }
            
            .stat-label {
                font-size: 0.55rem;
            }
            
            .badge-id {
                font-size: 0.6rem;
                padding: 0.15rem 0.4rem;
            }
            
            .status-badge {
                font-size: 0.55rem;
                padding: 0.15rem 0.4rem;
            }
            
            .payment-method-badge {
                font-size: 0.55rem;
                padding: 0.1rem 0.4rem;
            }
        }
        
        /* ===== ANIMATIONS ===== */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .fade-in {
            animation: fadeIn 0.4s ease forwards;
        }
        
        .slide-in-right {
            animation: slideInRight 0.3s ease forwards;
        }
        
        .pulse-hover:hover {
            animation: pulse 0.4s ease;
        }
        
        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-body);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--border-medium);
            border-radius: var(--radius-full);
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }
        
        /* ===== UTILITY CLASSES ===== */
        .glass-effect {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        [data-theme="dark"] .glass-effect {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .text-gradient {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .bg-danger-soft { background-color: var(--danger-light) !important; }
        .bg-success-soft { background-color: var(--success-light) !important; }
        .bg-primary-soft { background-color: var(--primary-soft) !important; }
        .bg-warning-soft { background-color: var(--warning-light) !important; }
        
        .smaller { font-size: 0.7rem; }
        
        /* ===== PRINT STYLES ===== */
        @media print {
            .theme-toggle,
            .action-button,
            .btn-view,
            .modal,
            .pagination,
            .breadcrumb,
            .fab-container,
            .dropdown-fab,
            .position-fixed {
                display: none !important;
            }
            
            .table-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                break-inside: avoid;
            }
            
            .stat-card {
                break-inside: avoid;
                border: 1px solid #ddd !important;
            }
            
            .supplier-info {
                border: 1px solid #ddd !important;
            }
        }
        
        /* ===== REDUCED MOTION ===== */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        
        /* ===== FOCUS VISIBLE ===== */
        :focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }
        
        /* ===== TOUCH OPTIMIZATIONS ===== */
        .touch-optimized {
            min-height: 44px;
            min-width: 44px;
        }
        
        /* ===== RIPPLE EFFECT ===== */
        .ripple-effect {
            position: relative;
            overflow: hidden;
        }
        
        .ripple-effect::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.4);
            transform: translate(-50%, -50%);
            transition: width 0.3s ease, height 0.3s ease;
        }
        
        .ripple-effect:active::after {
            width: 200px;
            height: 200px;
            opacity: 0;
        }
        
        /* ===== CUSTOM MODAL SCROLLBAR ===== */
        .modal-body::-webkit-scrollbar {
            width: 4px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: var(--bg-hover);
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: var(--text-muted);
            border-radius: var(--radius-full);
        }
        
        /* ===== LOADING SPINNER ===== */
        .spinner-border {
            width: 2rem;
            height: 2rem;
            border-width: 0.2rem;
        }
        
        /* ===== TOAST NOTIFICATION ===== */
        .toast-container {
            z-index: 9999;
        }
        
        .toast {
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
        }
        
        .toast-body {
            padding: 0.6rem 1rem;
        }
        
        /* ===== SAFE AREA SUPPORT ===== */
        @supports (padding: max(0px)) {
            .fab-container {
                bottom: max(15px, env(safe-area-inset-bottom));
                right: max(15px, env(safe-area-inset-right));
            }
            
            .dropdown-fab {
                bottom: max(15px, env(safe-area-inset-bottom));
                right: max(75px, env(safe-area-inset-right) + 60px);
            }
            
            .theme-toggle {
                bottom: max(15px, env(safe-area-inset-bottom));
                left: max(15px, env(safe-area-inset-left));
            }
        }
    </style>
</head>
<body>
    <!-- Theme Toggle Button -->
    <div class="theme-toggle ripple-effect" id="themeToggle" onclick="toggleTheme()">
        <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
    </div>

    <div class="container-fluid py-2 py-md-3">
        <!-- Header Section -->
        <div class="header-section fade-in">
            <div class="supplier-info">
                <div class="d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-1">
                                <li class="breadcrumb-item small"><a href="suppliers.php">الموردين</a></li>
                                <li class="breadcrumb-item active small">كشف حساب</li>
                            </ol>
                        </nav>
                        
                        <h1 class="supplier-name">
                            <i class="bi bi-building"></i>
                            <?= htmlspecialchars($supplier['name']) ?>
                        </h1>
                        
                        <div class="supplier-details">
                            <?php if (!empty($supplier['company_name'])): ?>
                                <span class="supplier-detail">
                                    <i class="bi bi-briefcase"></i>
                                    <?= htmlspecialchars($supplier['company_name']) ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if (!empty($supplier['phone'])): ?>
                                <span class="supplier-detail">
                                    <i class="bi bi-telephone"></i>
                                    <?= htmlspecialchars($supplier['phone']) ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if (!empty($supplier['email'])): ?>
                                <span class="supplier-detail">
                                    <i class="bi bi-envelope"></i>
                                    <?= htmlspecialchars($supplier['email']) ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if (!empty($supplier['address'])): ?>
                                <span class="supplier-detail">
                                    <i class="bi bi-geo-alt"></i>
                                    <?= htmlspecialchars($supplier['address']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mt-2 mt-sm-0">
                        <button class="action-button ripple-effect" data-bs-toggle="modal" data-bs-target="#payModal">
                            <i class="bi bi-cash-stack"></i>
                            <span>تسديد دفعة</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'paid'): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-3 py-2" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <div class="flex-grow-1 small">تم تسجيل الدفعة بنجاح وخصمها من حساب المورد</div>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Stats Cards (مصغرة) -->
        <div class="stats-grid fade-in">
            <!-- Balance Card -->
            <div class="stat-card bg-danger">
                <div class="stat-label">المديونية الحالية</div>
                <div class="stat-value">
                    <?= number_format($supplier['balance'], 2) ?> 
                    <small>ج.م</small>
                </div>
                <div class="stat-meta">
                    <i class="bi bi-calendar3"></i>
                    <?= date('Y/m/d') ?>
                </div>
            </div>
            
            <!-- Total Purchases -->
            <div class="stat-card">
                <div class="stat-label">إجمالي المشتريات</div>
                <div class="stat-value text-primary">
                    <?= number_format($total_purchases_sum, 2) ?> 
                    <small>ج.م</small>
                </div>
                <div class="stat-meta">
                    <i class="bi bi-cart-check"></i>
                    <?= $total_purchases_count ?> فاتورة
                </div>
            </div>
            
            <!-- Total Payments -->
            <div class="stat-card">
                <div class="stat-label">إجمالي المدفوعات</div>
                <div class="stat-value text-success">
                    <?= number_format($total_payments_sum, 2) ?> 
                    <small>ج.م</small>
                </div>
                <div class="stat-meta">
                    <i class="bi bi-cash"></i>
                    <?= $total_payments_count ?> دفعة
                </div>
            </div>
        </div>

        <!-- Purchases Table -->
        <div class="table-card fade-in">
            <div class="table-header">
                <h5>
                    <i class="bi bi-cart-check"></i>
                    سجل المشتريات
                </h5>
                <span class="badge bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-files me-1"></i> <?= $total_purchases_count ?>
                </span>
            </div>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="text-end">التاريخ والوقت</th>
                            <th class="text-center">نوع العملية</th>
                            <th class="text-center">القيمة</th>
                            <th class="text-center">رقم المرجع</th>
                            <th class="text-center">الحالة</th>
                            <th class="text-center"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($purchases_list)): ?>
                            <?php foreach ($purchases_list as $purchase): ?>
                                <tr>
                                    <td data-label="التاريخ">
                                        <div class="date-cell"><?= date('Y/m/d', strtotime($purchase['created_at'])) ?></div>
                                        <div class="time-cell"><?= date('h:i A', strtotime($purchase['created_at'])) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bold">فاتورة شراء</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="amount-invoice">
                                            + <?= number_format($purchase['amount'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-id">#<?= $purchase['id'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="status-badge invoice">مديونية</span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn-view view-details ripple-effect" data-id="<?= $purchase['id'] ?>" title="عرض التفاصيل">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <div class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        <p>لا توجد مشتريات مسجلة</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination for Purchases -->
            <?php if ($total_purchases_pages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    عرض <?= ($offset_purchases + 1) ?> - <?= min($offset_purchases + $limit, $total_purchases_count) ?> 
                    من <?= $total_purchases_count ?>
                </div>
                <nav>
                    <ul class="pagination">
                        <li class="page-item <?= ($page_purchases <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?id=<?= $supplier_id ?>&page_purchases=<?= $page_purchases - 1 ?>&page_payments=<?= $page_payments ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        
                        <?php
                        $start = max(1, $page_purchases - 2);
                        $end = min($total_purchases_pages, $page_purchases + 2);
                        
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <li class="page-item <?= ($i == $page_purchases) ? 'active' : '' ?>">
                                <a class="page-link" href="?id=<?= $supplier_id ?>&page_purchases=<?= $i ?>&page_payments=<?= $page_payments ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?= ($page_purchases >= $total_purchases_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?id=<?= $supplier_id ?>&page_purchases=<?= $page_purchases + 1 ?>&page_payments=<?= $page_payments ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>

   <!-- Payments Table -->
<div class="table-card fade-in">
    <div class="table-header">
        <h5>
            <i class="bi bi-cash-stack text-success"></i>
            سجل الدفعات النقدية
        </h5>
        <span class="badge bg-success bg-opacity-10 text-success">
            <i class="bi bi-cash me-1"></i> <?= $total_payments_count ?>
        </span>
    </div>
    
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th class="text-end">التاريخ والوقت</th>
                    <th class="text-center">نوع العملية</th>
                    <th class="text-center">القيمة</th>
                    <th class="text-center">رقم المرجع</th>
                    <th class="text-center">طريقة الدفع</th>
                    <th class="text-center">ملاحظات</th>
                    <th class="text-center">الحالة</th>
                </tr> 
            </thead>
            <tbody>
                <?php if (!empty($payments_list)): ?>
                    <?php foreach ($payments_list as $payment): ?>
                        <tr>
                            <td data-label="التاريخ">
                                <div class="date-cell"><?= date('Y/m/d', strtotime($payment['created_at'])) ?></div>
                                <div class="time-cell"><?= date('h:i A', strtotime($payment['created_at'])) ?></div>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold text-success">دفعة نقدية</span>
                            </td>
                            <td class="text-center">
                                <span class="amount-payment">
                                    - <?= number_format($payment['amount'], 2) ?> ج.م
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge-id">#<?= $payment['id'] ?></span>
                            </td>
                            <td class="text-center">
                                <span class="payment-method-badge">
                                    <?php 
                                    $method = $payment['payment_method'] ?? 'cash';
                                    if ($method == 'cash') echo '💰 نقدي';
                                    elseif ($method == 'vodafone') echo '📱 فودافون كاش';
                                    elseif ($method == 'bank') echo '🏦 تحويل بنكي';
                                    else echo $method;
                                    ?>
                                </span>
                            </td>
                            <td class="text-center" style="max-width: 200px;">
                                <?php 
                                // استخراج الملاحظات من حقل description
                                $notes = '';
                                if (!empty($payment['description'])) {
                                    // إزالة الجزء الثابت من الوصف
                                    $notes = str_replace("تسديد دفعة للمورد: " . $supplier['name'] . " - ", "", $payment['description']);
                                    $notes = str_replace("تسديد دفعة للمورد: ", "", $notes);
                                    $notes = str_replace($supplier['name'] . " - ", "", $notes);
                                }
                                
                                // إذا كان هناك ملاحظات مخزنة في حقل آخر (مثل notes) - اختياري
                                if (empty($notes) && isset($payment['notes'])) {
                                    $notes = $payment['notes'];
                                }
                                
                                if (!empty($notes)): 
                                ?>
                                    <span class="badge bg-info bg-opacity-10 text-info p-2" style="font-size: 0.75rem; white-space: normal; word-break: break-word;">
                                        <i class="bi bi-chat-dots me-1"></i>
                                        <?= htmlspecialchars($notes) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="status-badge payment">سداد</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <div class="empty-state">
                                <i class="bi bi-cash-stack"></i>
                                <p>لا توجد دفعات نقدية مسجلة</p>
                                <button class="btn btn-sm btn-success ripple-effect" data-bs-toggle="modal" data-bs-target="#payModal">
                                    <i class="bi bi-plus-circle me-1"></i>تسجيل أول دفعة
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
            
            <!-- Pagination for Payments -->
            <?php if ($total_payments_pages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    عرض <?= ($offset_payments + 1) ?> - <?= min($offset_payments + $limit, $total_payments_count) ?> 
                    من <?= $total_payments_count ?> دفعة
                </div>
                <nav>
                    <ul class="pagination">
                        <li class="page-item <?= ($page_payments <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?id=<?= $supplier_id ?>&page_purchases=<?= $page_purchases ?>&page_payments=<?= $page_payments - 1 ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        
                        <?php
                        $start = max(1, $page_payments - 2);
                        $end = min($total_payments_pages, $page_payments + 2);
                        
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <li class="page-item <?= ($i == $page_payments) ? 'active' : '' ?>">
                                <a class="page-link" href="?id=<?= $supplier_id ?>&page_purchases=<?= $page_purchases ?>&page_payments=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?= ($page_payments >= $total_payments_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?id=<?= $supplier_id ?>&page_purchases=<?= $page_purchases ?>&page_payments=<?= $page_payments + 1 ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Invoice Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-receipt me-2"></i>
                        تفاصيل الفاتورة #<span id="invoiceNumber"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="invoiceDetailsContent" class="p-3">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">جاري التحميل...</span>
                            </div>
                            <p class="mt-2 small text-muted">جاري تحميل تفاصيل الفاتورة...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">إغلاق</button>
                    <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>طباعة
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-success">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-cash-stack me-2"></i>
                        تسجيل دفعة نقدية
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-soft-primary d-flex align-items-center mb-3 p-2">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <small>هذه العملية ستخصم من حساب المورد فقط</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">المبلغ</label>
                        <div class="input-group">
                            <input type="number" step="0.01" name="amount" class="form-control text-success fw-bold" 
                                   required min="1" placeholder="0.00">
                            <span class="input-group-text">ج.م</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">طريقة الدفع</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="cash">💰 نقداً</option>
                            <option value="vodafone">📱 فودافون كاش</option>
                            <option value="bank">🏦 تحويل بنكي</option>
                        </select>
                    </div>
                    
                    <div class="mb-2">
                        <label class="form-label small fw-bold">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2" 
                                  placeholder="اختياري..."></textarea>
                    </div>
                    
                    <!-- Summary -->
                    <div class="summary-card">
                        <div class="summary-row">
                            <span class="text-muted">الرصيد الحالي:</span>
                            <span class="fw-bold text-danger"><?= number_format($supplier['balance'], 2) ?> ج.م</span>
                        </div>
                        <div class="summary-row">
                            <span class="text-muted">الرصيد بعد الدفع:</span>
                            <span class="fw-bold text-success" id="newBalancePreview">
                                <?= number_format($supplier['balance'], 2) ?> ج.م
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="pay_supplier" class="btn btn-sm btn-success px-4 fw-bold">
                        <i class="bi bi-check-circle me-1"></i>تأكيد
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Floating Action Buttons for Mobile -->
    <div class="fab-container d-md-none">
        <button class="fab-button success ripple-effect" data-bs-toggle="modal" data-bs-target="#payModal">
            <i class="bi bi-plus-lg"></i>
        </button>
    </div>

    <div class="dropdown-fab d-md-none">
        <div class="dropdown">
            <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-download"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">

                <li>
                    <a class="dropdown-item" href="#" onclick="window.print()">
                        <i class="bi bi-printer text-primary"></i>
                        طباعة
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <script>
        // ========== THEME TOGGLE ==========
        function toggleTheme() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme') || 'light';
            const target = current === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', target);
            localStorage.setItem('supplier_theme', target);
            
            const icon = document.getElementById('themeIcon');
            icon.className = target === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        }

        // Load saved theme
        document.addEventListener('DOMContentLoaded', () => {
            const saved = localStorage.getItem('supplier_theme') || 'light';
            document.documentElement.setAttribute('data-theme', saved);
            
            const icon = document.getElementById('themeIcon');
            icon.className = saved === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
            
            // Initialize
            initTooltips();
            animateNumbers();
            setupLiveBalanceUpdate();
        });

        // ========== TOOLTIPS ==========
        function initTooltips() {
            const tooltips = [].slice.call(document.querySelectorAll('[title]'));
            tooltips.map(el => new bootstrap.Tooltip(el, { placement: 'top', trigger: 'hover' }));
        }

        // ========== ANIMATE NUMBERS ==========
        function animateNumbers() {
            const stats = document.querySelectorAll('.stat-value');
            stats.forEach(stat => {
                const text = stat.innerText;
                const num = parseFloat(text.replace(/[^\d.]/g, ''));
                if (!isNaN(num) && num > 0) {
                    animateValue(stat, 0, num, 800);
                }
            });
        }

        function animateValue(element, start, end, duration) {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const current = Math.floor(progress * (end - start) + start);
                element.innerHTML = element.innerHTML.replace(/[\d,]+(\.\d+)?/g, 
                    current.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,'));
                if (progress < 1) window.requestAnimationFrame(step);
            };
            window.requestAnimationFrame(step);
        }

        // ========== LIVE BALANCE UPDATE ==========
        function setupLiveBalanceUpdate() {
            const amountInput = document.querySelector('input[name="amount"]');
            const currentBalance = <?= $supplier['balance'] ?>;
            const previewEl = document.getElementById('newBalancePreview');
            
            if (amountInput) {
                amountInput.addEventListener('input', function() {
                    const amount = parseFloat(this.value) || 0;
                    const newBalance = Math.max(0, currentBalance - amount);
                    previewEl.innerText = newBalance.toFixed(2) + ' ج.م';
                });
            }
        }

        // ========== VIEW INVOICE DETAILS ==========
        document.querySelectorAll('.view-details').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                document.getElementById('invoiceNumber').innerText = id;
                
                const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
                modal.show();
                
                const contentDiv = document.getElementById('invoiceDetailsContent');
                contentDiv.innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" style="width:2rem;height:2rem;"></div>
                        <p class="mt-2 small text-muted">جاري التحميل...</p>
                    </div>
                `;
                
                fetch(`get_purchase_details.php?id=${id}`)
                    .then(response => response.text())
                    .then(data => contentDiv.innerHTML = data)
                    .catch(() => contentDiv.innerHTML = '<div class="alert alert-danger m-3">حدث خطأ</div>');
            });
        });

        // ========== AUTO-DISMISS ALERTS ==========
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            });
        }, 5000);

        // ========== KEYBOARD SHORTCUTS ==========
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('payModal')).show();
            }
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    bootstrap.Modal.getInstance(modal)?.hide();
                });
            }
        });

        // ========== EXPORT TO CSV ==========
        function exportToCSV() {
            const purchasesRows = document.querySelectorAll('.table-card:first-child tbody tr');
            const paymentsRows = document.querySelectorAll('.table-card:last-child tbody tr');
            let csv = 'النوع,التاريخ,الوقت,القيمة,المرجع,طريقة الدفع,الحالة\n';
            
            purchasesRows.forEach(row => {
                if (!row.querySelector('td[colspan]')) {
                    const cells = row.querySelectorAll('td');
                    csv += `"فاتورة شراء","${cells[0]?.innerText.replace(/\n/g, ' ') || ''}","",` +
                           `"${cells[2]?.innerText || ''}","${cells[3]?.innerText || ''}",,` +
                           `"${cells[4]?.innerText || ''}"\n`;
                }
            });
            
            paymentsRows.forEach(row => {
                if (!row.querySelector('td[colspan]')) {
                    const cells = row.querySelectorAll('td');
                    csv += `"دفعة نقدية","${cells[0]?.innerText.replace(/\n/g, ' ') || ''}","",` +
                           `"${cells[2]?.innerText || ''}","${cells[3]?.innerText || ''}",` +
                           `"${cells[4]?.innerText || ''}","${cells[5]?.innerText || ''}"\n`;
                }
            });
            
            const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `supplier_<?= $supplier_id ?>_${new Date().toISOString().split('T')[0]}.csv`;
            link.click();
            
            showNotification('تم تصدير البيانات بنجاح', 'success');
        }

        // ========== NOTIFICATION SYSTEM ==========
        function showNotification(message, type = 'info') {
            const toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed top-0 start-0 p-3';
            toastContainer.style.zIndex = '9999';
            
            const toastId = 'toast-' + Date.now();
            const bgColor = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6');
            
            toastContainer.innerHTML = `
                <div id="${toastId}" class="toast align-items-center text-white border-0" style="background: ${bgColor};" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(toastContainer);
            const toast = new bootstrap.Toast(document.getElementById(toastId), { delay: 3000 });
            toast.show();
            
            setTimeout(() => {
                toastContainer.remove();
            }, 4000);
        }

        // ========== WINDOW RESIZE HANDLER ==========
        let resizeTimer;
        window.addEventListener('resize', () => {
            document.body.classList.add('resize-animation-stopper');
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                document.body.classList.remove('resize-animation-stopper');
            }, 400);
        });

        // ========== OFFLINE DETECTION ==========
        window.addEventListener('offline', () => {
            showNotification('أنت غير متصل بالإنترنت', 'warning');
        });
        
        window.addEventListener('online', () => {
            showNotification('تم استعادة الاتصال بالإنترنت', 'success');
        });

        // ========== TOUCH OPTIMIZATIONS ==========
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .btn-view, .action-button, .theme-toggle, .fab-button').forEach(btn => {
                btn.classList.add('touch-optimized');
            });
        }

        // ========== PRINT FUNCTION ==========
        window.printReport = function() {
            window.print();
        };

        // ========== ADD TO FAVORITES ==========
        window.addToFavorites = function() {
            if (window.sidebar && window.sidebar.addPanel) {
                window.sidebar.addPanel(document.title, window.location.href, '');
            } else if (window.external && ('AddFavorite' in window.external)) {
                window.external.AddFavorite(window.location.href, document.title);
            } else {
                showNotification('اضغط Ctrl+D لإضافة الصفحة للمفضلة', 'info');
            }
        };
    </script>

    <!-- Additional Styles -->
    <style>
        /* Animation stopper during resize */
        .resize-animation-stopper * {
            animation: none !important;
            transition: none !important;
        }
        
        /* Touch optimizations */
        .touch-optimized {
            min-height: 44px;
            min-width: 44px;
        }
        
        /* Toast container */
        .toast-container {
            z-index: 9999;
        }
        
        .toast {
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
        }
        
        /* Loading spinner */
        .spinner-border {
            width: 2rem;
            height: 2rem;
            border-width: 0.2rem;
        }
        
        /* Modal backdrop */
        .modal-backdrop {
            backdrop-filter: blur(4px);
            background: rgba(0, 0, 0, 0.5);
        }
        
        /* Safe area for modern phones */
        @supports (padding: max(0px)) {
            .fab-container {
                bottom: max(15px, env(safe-area-inset-bottom));
                right: max(15px, env(safe-area-inset-right));
            }
            
            .dropdown-fab {
                bottom: max(15px, env(safe-area-inset-bottom));
                right: max(75px, env(safe-area-inset-right) + 60px);
            }
            
            .theme-toggle {
                bottom: max(15px, env(safe-area-inset-bottom));
                left: max(15px, env(safe-area-inset-left));
            }
        }
        
        /* Print styles */
        @media print {
            .theme-toggle,
            .dropdown-fab,
            .fab-container,
            .action-button,
            .btn-view,
            .modal,
            .pagination,
            .breadcrumb {
                display: none !important;
            }
            
            .table-card {
                break-inside: avoid;
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .stat-card {
                break-inside: avoid;
                border: 1px solid #ddd !important;
            }
        }
        
        /* High contrast mode */
        @media (prefers-contrast: high) {
            :root {
                --border-light: #000000;
                --text-primary: #000000;
                --bg-card: #ffffff;
            }
            
            [data-theme="dark"] {
                --border-light: #ffffff;
                --text-primary: #ffffff;
                --bg-card: #000000;
            }
        }
        
        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        
        /* Focus visible */
        :focus-visible {
            outline: 3px solid var(--primary);
            outline-offset: 2px;
        }
        
        /* Custom scrollbar for modal */
        .modal-body::-webkit-scrollbar {
            width: 4px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: var(--bg-hover);
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: var(--text-muted);
            border-radius: var(--radius-full);
        }
        
        /* Success animation */
        @keyframes checkmark {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .check-animation {
            animation: checkmark 0.5s ease;
        }
        
        /* Hover effects */
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background: var(--bg-hover);
        }
        
        /* Card hover effects */
        .stat-card {
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        /* Badge animations */
        .badge {
            transition: all 0.2s ease;
        }
        
        .badge:hover {
            transform: scale(1.05);
        }
        
        /* Empty state animations */
        .empty-state i {
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        
        /* Responsive table improvements */
        @media (max-width: 768px) {
            .table td, .table th {
                white-space: nowrap;
            }
            
            .payment-method-badge {
                white-space: nowrap;
            }
            
            .action-button {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Dark mode adjustments */
        [data-theme="dark"] .bg-light {
            background-color: var(--bg-hover) !important;
        }
        
        [data-theme="dark"] .table-hover tbody tr:hover {
            background-color: var(--bg-hover);
        }
        
        /* Loading skeleton */
        .skeleton {
            background: linear-gradient(
                90deg,
                var(--bg-hover) 25%,
                var(--border-light) 50%,
                var(--bg-hover) 75%
            );
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Form validation */
        .form-control.is-invalid {
            border-color: var(--danger);
            background-image: none;
        }
        
        .form-control.is-valid {
            border-color: var(--success);
            background-image: none;
        }
        
        .invalid-feedback {
            color: var(--danger);
            font-size: 0.75rem;
            margin-top: 0.25rem;
            display: block;
        }
        
        /* Dropdown menu */
        .dropdown-menu {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            padding: 0.4rem;
        }
        
        .dropdown-item {
            color: var(--text-primary);
            border-radius: var(--radius-sm);
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }
        
        .dropdown-item:hover {
            background: var(--bg-hover);
            color: var(--primary);
        }
        
        .dropdown-item i {
            font-size: 0.9rem;
        }
        
        /* Tooltip */
        .tooltip-inner {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-md);
            border-radius: var(--radius-sm);
            padding: 0.3rem 0.8rem;
            font-size: 0.75rem;
        }
        
        .bs-tooltip-top .tooltip-arrow::before {
            border-top-color: var(--border-light);
        }
        
        /* Glass effect for modals */
        .modal-content {
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            background: var(--glass-bg);
        }
        
        [data-theme="dark"] .modal-content {
            background: rgba(30, 41, 59, 0.95);
        }
        
        /* Ultra-wide screens */
        @media (min-width: 1600px) {
            .container-fluid {
                max-width: 1600px;
                margin: 0 auto;
            }
        }
        
        /* Landscape mode on phones */
        @media (max-width: 768px) and (orientation: landscape) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .supplier-info {
                padding: 1rem;
            }
            
            .supplier-name {
                font-size: 1.2rem;
            }
        }
        
        /* High DPI screens */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .shadow-sm, .shadow-md, .shadow-lg {
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            }
        }
        
        /* RTL specific adjustments */
        [dir="rtl"] .me-1 { margin-left: 0.25rem !important; margin-right: 0 !important; }
        [dir="rtl"] .me-2 { margin-left: 0.5rem !important; margin-right: 0 !important; }
        [dir="rtl"] .ms-auto { margin-right: auto !important; margin-left: 0 !important; }
        
        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }
        
        /* Improved readability */
        .table td, .table th {
            font-size: 0.8rem;
        }
        
        /* Better spacing for small screens */
        @media (max-width: 360px) {
            .container-fluid {
                padding: 0.4rem;
            }
            
            .supplier-info {
                padding: 0.75rem;
            }
            
            .supplier-name {
                font-size: 1rem;
            }
            
            .supplier-detail {
                font-size: 0.65rem;
                padding: 0.15rem 0.4rem;
            }
            
            .stat-value {
                font-size: 0.9rem;
            }
            
            .stat-label {
                font-size: 0.55rem;
            }
            
            .table-header h5 {
                font-size: 0.8rem;
            }
            
            .badge {
                font-size: 0.6rem;
                padding: 0.2rem 0.4rem;
            }
        }
    </style>
</body>
</html>

<?php require_once '../includes/footer.php'; ?>