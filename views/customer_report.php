<?php 
/* File Path: http://localhost/project/ERP_System_V2/views/customer_report.php
Description: كشف حساب تفصيلي للعميل - Modern UI 2026
*/
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

$customer_id = $_GET['id'] ?? 0;

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    if ($action === 'invoice_items') {
        $invoice_id = (int)($_GET['invoice_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT ii.id, ii.product_id, ii.quantity, ii.price, p.name
            FROM invoice_items ii
            JOIN products p ON p.id = ii.product_id
            JOIN invoices i ON i.id = ii.invoice_id
            WHERE ii.invoice_id = ? AND i.customer_id = ?
        ");
        $stmt->execute([$invoice_id, $customer_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($items as &$item) {
            $retStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM customer_returns WHERE invoice_item_id = ? AND deleted_at IS NULL");
            $retStmt->execute([(int)$item['id']]);
            $returned = (float)$retStmt->fetchColumn();
            $item['returned_qty'] = $returned;
            $item['available_qty'] = max(0, (float)$item['quantity'] - $returned);
        }
        unset($item);

        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'process_customer_return') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $invoice_id = (int)($data['invoice_id'] ?? 0);
        $invoice_item_id = (int)($data['invoice_item_id'] ?? 0);
        $quantity = (float)($data['quantity'] ?? 0);
        $refund_method = (string)($data['refund_method'] ?? 'adjust_balance');
        $notes = trim((string)($data['notes'] ?? ''));
        $user_id = (int)($_SESSION['user_id'] ?? 0);

        if ($quantity <= 0 || $invoice_id <= 0 || $invoice_item_id <= 0 || $user_id <= 0) {
            echo json_encode(['success' => false, 'msg' => 'بيانات المرتجع غير صحيحة'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $allowed_methods = ['adjust_balance', 'cash', 'vodafone', 'bank'];
        if (!in_array($refund_method, $allowed_methods, true)) {
            $refund_method = 'adjust_balance';
        }

        try {
            $pdo->beginTransaction();

            $invStmt = $pdo->prepare("SELECT id, customer_id, payment_method FROM invoices WHERE id = ? LIMIT 1");
            $invStmt->execute([$invoice_id]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice || (int)$invoice['customer_id'] !== (int)$customer_id) {
                throw new Exception('الفاتورة لا تتبع هذا العميل');
            }

            $itemStmt = $pdo->prepare("
                SELECT ii.id, ii.product_id, ii.quantity, ii.price, p.name
                FROM invoice_items ii
                JOIN products p ON p.id = ii.product_id
                WHERE ii.id = ? AND ii.invoice_id = ?
                LIMIT 1
            ");
            $itemStmt->execute([$invoice_item_id, $invoice_id]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new Exception('الصنف غير موجود داخل الفاتورة');
            }

            $retSumStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM customer_returns WHERE invoice_item_id = ? AND deleted_at IS NULL");
            $retSumStmt->execute([$invoice_item_id]);
            $alreadyReturned = (float)$retSumStmt->fetchColumn();
            $availableQty = (float)$item['quantity'] - $alreadyReturned;
            if ($quantity > $availableQty + 0.0001) {
                throw new Exception('الكمية المرتجعة أكبر من المتاح');
            }

            $unit_price = (float)$item['price'];
            $total_amount = $unit_price * $quantity;
            $cash_tx_id = null;

            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")
                ->execute([$quantity, (int)$item['product_id']]);

            if ((string)$invoice['payment_method'] === 'credit') {
                $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?")
                    ->execute([$total_amount, $customer_id]);
            }

            if ($refund_method !== 'adjust_balance') {
                $desc = "مرتجع من فاتورة عميل #{$invoice_id} - " . $item['name'] . " (كمية: {$quantity})";
                $cash_tx_id = recordTransaction($pdo, [
                    'direction' => 'out',
                    'amount' => $total_amount,
                    'payment_method' => $refund_method,
                    'description' => $desc,
                    'user_id' => $user_id,
                    'related_type' => 'customer_return',
                    'related_id' => $invoice_id,
                ]);
            }

            $ins = $pdo->prepare("
                INSERT INTO customer_returns
                (customer_id, invoice_id, invoice_item_id, product_id, quantity, unit_price, total_amount, refund_method, cash_transaction_id, notes, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $customer_id, $invoice_id, $invoice_item_id, (int)$item['product_id'],
                $quantity, $unit_price, $total_amount, $refund_method, $cash_tx_id, $notes, $user_id
            ]);

            $pdo->commit();
            echo json_encode(['success' => true, 'msg' => 'تم تسجيل المرتجع وتحديث حساب العميل'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'delete_customer_return') {
        $return_id = (int)($_GET['return_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("
                SELECT cr.*, i.payment_method, p.name AS product_name
                FROM customer_returns cr
                JOIN invoices i ON i.id = cr.invoice_id
                JOIN products p ON p.id = cr.product_id
                WHERE cr.id = ? AND cr.customer_id = ? AND cr.deleted_at IS NULL
                LIMIT 1
            ");
            $st->execute([$return_id, $customer_id]);
            $ret = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ret) {
                throw new Exception('المرتجع غير موجود');
            }

            $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(stock_quantity - ?, 0) WHERE id = ?")
                ->execute([(float)$ret['quantity'], (int)$ret['product_id']]);

            if ((string)$ret['payment_method'] === 'credit') {
                $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")
                    ->execute([(float)$ret['total_amount'], $customer_id]);
            }

            if (!empty($ret['cash_transaction_id'])) {
                $pdo->prepare("DELETE FROM cash_transactions WHERE id = ?")->execute([(int)$ret['cash_transaction_id']]);
            }

            $pdo->prepare("UPDATE customer_returns SET deleted_at = NOW() WHERE id = ?")->execute([$return_id]);
            $pdo->commit();
            echo json_encode(['success' => true, 'msg' => 'تم حذف المرتجع وعكس الأثر المحاسبي'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

// 1. جلب بيانات العميل
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

if (!$customer) {
    die("العميل غير موجود أو تم حذفه.");
}

// 2. جلب تاريخ العمليات
$sales_list = [];
$collections_list = [];

try {
    $sales = $pdo->prepare("SELECT 'فاتورة مبيعات' as type, id, total_amount as amount, created_at, 'sale' as category 
                            FROM invoices WHERE customer_id = ?");
    $sales->execute([$customer_id]);
    $sales_list = $sales->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $collections = $pdo->prepare("SELECT 'دفعة محصلة' as type, id, amount, created_at, 'collection' as category, payment_method 
                                  FROM cash_transactions 
                                  WHERE related_type = 'customer_collection' 
                                  AND description LIKE ?");
    $collections->execute(["%العميل: " . $customer['name'] . "%"]);
    $collections_list = $collections->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $returns = $pdo->prepare("SELECT 'مرتجع من فاتورة' as type, id, total_amount as amount, created_at, 'customer_return' as category, refund_method as payment_method, invoice_id
                              FROM customer_returns WHERE customer_id = ? AND deleted_at IS NULL");
    $returns->execute([$customer_id]);
    $returns_list = $returns->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (PDOException $e) {
    $sales_list = [];
    $collections_list = [];
    $returns_list = [];
}

$all_history = array_merge($sales_list, $collections_list, $returns_list ?? []);
if (!empty($all_history)) {
    usort($all_history, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
}

require_once '../includes/header.php'; 
?>

<style>
    :root {
        --primary-color: #4f46e5;
        --primary-light: #818cf8;
        --bg-main: #f8fafc;
        --bg-card: #ffffff;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --glass-bg: rgba(255, 255, 255, 0.7);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --radius-lg: 16px;
        --radius-md: 12px;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
        --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
    }

    [data-theme="dark"] {
        --bg-main: #0f172a;
        --bg-card: #1e293b;
        --text-main: #f1f5f9;
        --text-muted: #94a3b8;
        --border-color: #334155;
        --glass-bg: rgba(30, 41, 59, 0.7);
    }

    body {
        background-color: var(--bg-main);
        color: var(--text-main);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
        transition: var(--transition);
        direction: rtl;
    }

    /* Animations */
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .animate-fade-in { animation: fadeInUp 0.5s ease-out forwards; }

    /* Header & Theme Toggle */
    .header-section { margin-bottom: 2rem; }
    
    .theme-switch {
        cursor: pointer;
        padding: 10px;
        border-radius: 50%;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-sm);
        color: var(--text-main);
        transition: var(--transition);
    }
    
    .theme-switch:hover { transform: rotate(15deg) scale(1.1); }

    /* Stats Cards */
    .stat-card {
        border: none;
        border-radius: var(--radius-lg);
        transition: var(--transition);
        overflow: hidden;
        position: relative;
    }

    .stat-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-md); }

    .stat-icon {
        position: absolute;
        left: -10px;
        bottom: -10px;
        font-size: 5rem;
        opacity: 0.1;
        transform: rotate(-15deg);
    }

    /* Table Design */
    .custom-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-sm);
    }

    .table thead th {
        background: var(--bg-main);
        color: var(--text-muted);
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    .table tbody td {
        padding: 1.25rem 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main);
    }

    .badge-soft {
        padding: 6px 12px;
        font-weight: 600;
        font-size: 0.75rem;
        border-radius: 30px;
    }

    .bg-danger-soft { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
    .bg-success-soft { background: rgba(34, 197, 94, 0.1); color: #22c55e; }

    /* Buttons */
    .btn-modern {
        border-radius: var(--radius-md);
        padding: 10px 20px;
        font-weight: 600;
        transition: var(--transition);
    }

    .btn-outline-primary-modern {
        border: 1px solid var(--primary-color);
        color: var(--primary-color);
        background: transparent;
    }

    .btn-outline-primary-modern:hover {
        background: var(--primary-color);
        color: white;
    }

    /* Modal Styling */
    .modal-content {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-color);
    }

    .modal-header { border-bottom: 1px solid var(--border-color); }

    /* Responsive Adjustments */
    @media (max-width: 576px) {
        .stat-card h2 { font-size: 1.25rem; }
        .table { font-size: 0.85rem; }
    }

    /* Print Optimization */
    @media print {
        .theme-switch, .btn-modern, .view-sale-details, .breadcrumb, .return-action-btn { display: none !important; }
        body { background: white; color: black; }
        .custom-card { border: none; box-shadow: none; }
    }
</style>

<div class="container-fluid py-4 animate-fade-in">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small fw-medium">
                    <li class="breadcrumb-item"><a href="customers.php" class="text-decoration-none text-muted">العملاء</a></li>
                    <li class="breadcrumb-item active text-primary">كشف الحساب</li>
                </ol>
            </nav>
            <h2 class="h3 mb-0 fw-bold">تحليل حساب: <?= htmlspecialchars($customer['name']) ?></h2>
            <p class="text-muted mb-0 small"><i class="bi bi-telephone-fill me-1"></i> <?= htmlspecialchars($customer['phone']) ?></p>
        </div>
        <div class="d-flex gap-2">
            <button class="theme-switch" id="themeToggler" title="تبديل الوضع">
                <i class="bi bi-moon-stars" id="themeIcon"></i>
            </button>
            <button class="btn btn-modern btn-light border shadow-sm" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> طباعة
            </button>
        </div>
    </div>

    <div class="row g-4 mb-4 text-center">
        <div class="col-lg-4 col-md-6">
            <div class="card stat-card bg-primary text-white shadow-lg">
                <div class="card-body py-4">
                    <i class="bi bi-wallet2 stat-icon"></i>
                    <div class="small opacity-75 mb-1 fw-medium">إجمالي المديونية المتبقية</div>
                    <h2 class="mb-0 fw-bold counter"><?= number_format($customer['balance'], 2) ?> <small class="fs-6">ج.م</small></h2>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card stat-card custom-card border-start border-primary border-4">
                <div class="card-body py-4">
                    <i class="bi bi-cart-check stat-icon text-primary"></i>
                    <div class="text-muted small mb-1 fw-medium">إجمالي المسحوبات</div>
                    <h2 class="mb-0 text-primary fw-bold"><?= number_format(array_sum(array_column($sales_list, 'amount')), 2) ?> <small class="fs-6 text-muted">ج.م</small></h2>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-12">
            <div class="card stat-card custom-card border-start border-success border-4">
                <div class="card-body py-4">
                    <i class="bi bi-cash-coin stat-icon text-success"></i>
                    <div class="text-muted small mb-1 fw-medium">إجمالي التحصيلات</div>
                    <h2 class="mb-0 text-success fw-bold"><?= number_format(array_sum(array_column($collections_list, 'amount')), 2) ?> <small class="fs-6 text-muted">ج.م</small></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="custom-card overflow-hidden">
        <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center bg-light-subtle">
            <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i>سجل العمليات المباشرة</h5>
            <span class="badge bg-primary-soft text-primary rounded-pill"><?= count($all_history) ?> عملية</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr class="text-center">
                        <th class="ps-4 text-start">التاريخ والوقت</th>
                        <th>نوع العملية</th>
                        <th>المبلغ</th>
                        <th>رقم المرجع</th>
                        <th>الحالة</th>
                        <th class="pe-4">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="text-center border-0">
                    <?php foreach($all_history as $op): ?>
                    <tr>
                        <td class="ps-4 text-start">
                            <div class="fw-bold"><?= date('Y/m/d', strtotime($op['created_at'])) ?></div>
                            <div class="text-muted small"><?= date('h:i A', strtotime($op['created_at'])) ?></div>
                        </td>
                        <td>
                            <span class="fw-semibold text-main"><?= $op['type'] ?></span>
                            <?php if(!empty($op['payment_method'])): ?>
                                <div class="text-muted smaller fs-11"><?= $op['payment_method'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold <?= $op['category'] == 'sale' ? 'text-danger' : 'text-success' ?>">
                            <span class="font-monospace">
                                <?= $op['category'] == 'sale' ? '+' : '-' ?> <?= number_format($op['amount'], 2) ?>
                            </span>
                        </td>
                        <td><span class="badge bg-light text-dark border font-monospace">#<?= $op['id'] ?></span></td>
                        <td>
                            <span class="badge-soft <?= $op['category'] == 'sale' ? 'bg-danger-soft' : ($op['category'] == 'customer_return' ? 'bg-danger-soft' : 'bg-success-soft') ?>">
                                <?= $op['category'] == 'sale' ? 'مديونية' : ($op['category'] == 'customer_return' ? 'مرتجع' : 'تحصيل') ?>
                            </span>
                        </td>
                        <td class="pe-4">
                            <?php if($op['category'] == 'sale'): ?>
                                <button class="btn btn-sm btn-outline-primary-modern view-sale-details" data-id="<?= $op['id'] ?>">
                                    <i class="bi bi-list-ul me-1"></i> الأصناف
                                </button>
                                <button class="btn btn-sm btn-outline-danger return-action-btn mt-1 mt-md-0" onclick="openReturnModal(<?= (int)$op['id'] ?>)">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> مرتجع
                                </button>
                            <?php elseif($op['category'] == 'customer_return'): ?>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteCustomerReturn(<?= (int)$op['id'] ?>)">
                                    <i class="bi bi-trash me-1"></i> حذف المرتجع
                                </button>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($all_history)): ?>
                    <tr>
                        <td colspan="6" class="py-5 text-center text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-3 opacity-25"></i>
                            لا توجد عمليات مسجلة لهذا العميل حتى الآن.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="saleDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-primary"><i class="bi bi-info-circle me-2"></i>تفاصيل الفاتورة #<span id="saleInvoiceNumber"></span></h5>
                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="saleDetailsContent">
                <div class="text-center py-5">
                    <div class="spinner-grow text-primary" role="status"></div>
                    <div class="mt-2 text-muted">جاري تحميل البيانات...</div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-modern btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="returnInvoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-arrow-counterclockwise me-2"></i>مرتجع من فاتورة عميل #<span id="returnInvoiceNumber"></span></h5>
                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-bold">اختر الصنف</label>
                    <select id="returnItemSelect" class="form-select"></select>
                    <div class="small text-muted mt-1" id="returnAvailableQty"></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">الكمية</label>
                        <input type="number" id="returnQtyInput" class="form-control" min="0.01" step="0.01" value="1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">طريقة التسوية</label>
                        <select id="refundMethodInput" class="form-select">
                            <option value="adjust_balance">خصم من حساب العميل</option>
                            <option value="cash">رد نقدي</option>
                            <option value="vodafone">رد فودافون كاش</option>
                            <option value="bank">رد بنكي</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">إجمالي المرتجع</label>
                        <input type="text" id="returnTotalPreview" class="form-control fw-bold text-danger" readonly value="0.00">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">ملاحظة</label>
                        <input type="text" id="returnNotesInput" class="form-control" placeholder="اختياري">
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-danger" onclick="submitCustomerReturn()"><i class="bi bi-check-circle me-1"></i>تأكيد المرتجع</button>
            </div>
        </div>
    </div>
</div>

<script>
// Logic for fetching sale details (Unchanged as requested)
document.querySelectorAll('.view-sale-details').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        document.getElementById('saleInvoiceNumber').innerText = id;
        const modal = new bootstrap.Modal(document.getElementById('saleDetailsModal'));
        modal.show();

        fetch(`get_sale_details.php?id=${id}`)
            .then(response => response.text())
            .then(data => {
                document.getElementById('saleDetailsContent').innerHTML = data;
            });
    });
});

// Theme Toggle Logic
const themeToggler = document.getElementById('themeToggler');
const themeIcon = document.getElementById('themeIcon');
const body = document.body;

const setEmoji = (isDark) => {
    themeIcon.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-stars';
};

themeToggler.addEventListener('click', () => {
    const isDark = body.getAttribute('data-theme') === 'dark';
    const newTheme = isDark ? 'light' : 'dark';
    body.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    setEmoji(!isDark);
});

// Load saved theme
const savedTheme = localStorage.getItem('theme') || 'light';
body.setAttribute('data-theme', savedTheme);
setEmoji(savedTheme === 'dark');

let activeReturnInvoiceId = null;
let returnInvoiceItems = [];

function renderReturnItemOptions() {
    const select = document.getElementById('returnItemSelect');
    select.innerHTML = '';
    returnInvoiceItems.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.id;
        opt.dataset.price = item.price;
        opt.dataset.available = item.available_qty;
        opt.textContent = `${item.name} | سعر: ${Number(item.price).toFixed(2)} | متاح للمرتجع: ${Number(item.available_qty).toFixed(2)}`;
        select.appendChild(opt);
    });
    updateReturnPreview();
}

function updateReturnPreview() {
    const select = document.getElementById('returnItemSelect');
    const qty = parseFloat(document.getElementById('returnQtyInput').value || '0');
    const selected = select.options[select.selectedIndex];
    if (!selected) return;

    const unitPrice = parseFloat(selected.dataset.price || '0');
    const available = parseFloat(selected.dataset.available || '0');
    document.getElementById('returnAvailableQty').innerText = `المتاح: ${available.toFixed(2)}`;
    document.getElementById('returnTotalPreview').value = (unitPrice * qty).toFixed(2);
}

function openReturnModal(invoiceId) {
    activeReturnInvoiceId = invoiceId;
    document.getElementById('returnInvoiceNumber').innerText = invoiceId;
    fetch(`customer_report.php?id=<?= (int)$customer_id ?>&action=invoice_items&invoice_id=${invoiceId}`)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                alert('تعذر تحميل أصناف الفاتورة');
                return;
            }
            returnInvoiceItems = (res.items || []).filter(i => Number(i.available_qty) > 0);
            if (returnInvoiceItems.length === 0) {
                alert('لا توجد كميات متاحة للمرتجع في هذه الفاتورة');
                return;
            }
            renderReturnItemOptions();
            const modal = new bootstrap.Modal(document.getElementById('returnInvoiceModal'));
            modal.show();
        })
        .catch(() => alert('حدث خطأ أثناء تحميل بيانات الفاتورة'));
}

function submitCustomerReturn() {
    const select = document.getElementById('returnItemSelect');
    const selected = select.options[select.selectedIndex];
    if (!selected || !activeReturnInvoiceId) return;

    const qty = parseFloat(document.getElementById('returnQtyInput').value || '0');
    const available = parseFloat(selected.dataset.available || '0');
    if (qty <= 0 || qty > available) {
        alert('كمية المرتجع غير صحيحة');
        return;
    }

    const payload = {
        invoice_id: activeReturnInvoiceId,
        invoice_item_id: parseInt(selected.value, 10),
        quantity: qty,
        refund_method: document.getElementById('refundMethodInput').value,
        notes: document.getElementById('returnNotesInput').value
    };

    fetch(`customer_report.php?id=<?= (int)$customer_id ?>&action=process_customer_return`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            alert('✅ ' + res.msg);
            location.reload();
        } else {
            alert('❌ ' + res.msg);
        }
    })
    .catch(() => alert('خطأ أثناء تنفيذ المرتجع'));
}

function deleteCustomerReturn(returnId) {
    if (!confirm('تأكيد حذف المرتجع؟ سيتم عكس المخزون والحسابات.')) return;
    fetch(`customer_report.php?id=<?= (int)$customer_id ?>&action=delete_customer_return&return_id=${returnId}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                alert('✅ ' + res.msg);
                location.reload();
            } else {
                alert('❌ ' + res.msg);
            }
        })
        .catch(() => alert('خطأ أثناء حذف المرتجع'));
}

document.getElementById('returnItemSelect')?.addEventListener('change', updateReturnPreview);
document.getElementById('returnQtyInput')?.addEventListener('input', updateReturnPreview);
</script>

<?php require_once '../includes/footer.php'; ?>