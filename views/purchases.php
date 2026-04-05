<?php 
/* File Path: http://localhost/project/ERP_System_V2/views/purchases.php */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// 1. جلب الموردين والبيانات المساعدة (بدون تغيير)
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE deleted_at IS NULL")->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories WHERE deleted_at IS NULL")->fetchAll();
$first_cat_id = !empty($categories) ? $categories[0]['id'] : 1;
$products_for_datalist = $pdo->query("SELECT name FROM products WHERE deleted_at IS NULL ORDER BY name ASC LIMIT 200")->fetchAll();

// ✅ 2. AJAX endpoint للتحقق من الباركود
if (isset($_GET['action']) && $_GET['action'] === 'check_barcode') {
    header('Content-Type: application/json');
    $barcode = $_GET['barcode'] ?? '';
    
    if (!empty($barcode)) {
        $stmt = $pdo->prepare("SELECT id, name, barcode FROM products WHERE barcode = ? AND deleted_at IS NULL");
        $stmt->execute([$barcode]);
        $product = $stmt->fetch();
        
        if ($product) {
            echo json_encode([
                'exists' => true,
                'product' => $product['name'],
                'barcode' => $product['barcode'],
                'message' => "⚠️ الباركود '{$barcode}' مستخدم بالفعل للمنتج: {$product['name']}"
            ]);
        } else {
            echo json_encode(['exists' => false]);
        }
    } else {
        echo json_encode(['exists' => false]);
    }
    exit;
}

// 3. معالجة حفظ الفاتورة (مع تحسين التحقق)
if (isset($_POST['save_purchase'])) {
    $supplier_id = $_POST['supplier_id'];
    $items = $_POST['items']; 
    $paid_amount = max(0, (float) ($_POST['paid_amount'] ?? 0));
    $payment_method_purchase = $_POST['payment_method_purchase'] ?? 'cash';
    if (!in_array($payment_method_purchase, ['cash', 'vodafone', 'bank'], true)) {
        $payment_method_purchase = 'cash';
    }
    $total_bill = 0;
    $barcode_errors = [];

    try {
        $pdo->beginTransaction();

        // ✅ التحقق المسبق من الباركود المكرر
        foreach ($items as $index => $item) {
            if (!empty($item['barcode'])) {
                $check = $pdo->prepare("SELECT id, name FROM products WHERE barcode = ? AND deleted_at IS NULL");
                $check->execute([$item['barcode']]);
                $existing = $check->fetch();
                
                if ($existing) {
                    // الباركود موجود بالفعل، وهذا مقصود (سنقوم بتحديث نفس المنتج)
                }
            }
        }

        if (!empty($barcode_errors)) {
            $_SESSION['toast'] = [
                'message' => implode("<br>", $barcode_errors),
                'type' => 'error'
            ];
            header("Location: purchases.php");
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO purchases (supplier_id, total_amount, paid_amount) VALUES (?, 0, ?)");
        $stmt->execute([$supplier_id, $paid_amount]);
        $purchase_id = $pdo->lastInsertId();

        foreach ($items as $item) {
            $line_total = (float)$item['qty'] * (float)$item['price'];
            $total_bill += $line_total;

            $barcode_input = trim((string)($item['barcode'] ?? ''));

            // ✅ نحدد المنتج الموجود (بالباركود أولاً ثم بالاسم)
            $existing_product = null;
            if ($barcode_input !== '') {
                $check = $pdo->prepare("SELECT id, name FROM products WHERE barcode = ? AND deleted_at IS NULL");
                $check->execute([$barcode_input]);
                $existing_product = $check->fetch();
            }

            if (!$existing_product) {
                $check = $pdo->prepare("SELECT id, name FROM products WHERE name = ? AND deleted_at IS NULL");
                $check->execute([$item['name']]);
                $existing_product = $check->fetch();
            }

            $product_name_for_record = $existing_product ? $existing_product['name'] : $item['name'];

            // تسجيل سطر فاتورة الشراء
            $stmt = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_name, quantity, purchase_price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$purchase_id, $product_name_for_record, $item['qty'], $item['price']]);

            if ($existing_product) {
                // ✅ إضافة الكمية للمخزون بدون تكرار منتج جديد
                $sql = "UPDATE products SET 
                        stock_quantity = stock_quantity + ?, 
                        cost_price = ?, 
                        selling_price = ?, 
                        min_selling_price = ? 
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    (float)$item['qty'],
                    (float)$item['price'],
                    (float)($item['sell_p'] ?? 0),
                    (float)($item['min_p'] ?? 0),
                    (int)$existing_product['id']
                ]);
            } else {
                // توليد باركود عشوائي إذا كان المدخل فارغاً
                if ($barcode_input === '') {
                    $barcode = time() . rand(100, 999);
                } else {
                    $barcode = $barcode_input;
                }

                $sql = "INSERT INTO products (name, barcode, category_id, cost_price, selling_price, min_selling_price, stock_quantity) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $item['name'],
                    $barcode,
                    $first_cat_id,
                    (float)$item['price'],
                    (float)($item['sell_p'] ?? 0),
                    (float)($item['min_p'] ?? 0),
                    (float)$item['qty']
                ]);
            }
        }

        if ($paid_amount > $total_bill) {
            $paid_amount = $total_bill;
        }

        // ✅ فحص رصيد المحفظة قبل الخصم (كاش/فودافون/بنك)
        if ($paid_amount > 0 && $payment_method_purchase !== 'cash') {
            $wstmt = $pdo->prepare("SELECT balance FROM wallets WHERE wallet_name = ?");
            $wstmt->execute([$payment_method_purchase]);
            $wallet_balance = (float)($wstmt->fetchColumn() ?: 0);

            if ($paid_amount > $wallet_balance + 0.01) {
                throw new Exception('الرصيد لا يسمح: رصيد ' . $payment_method_purchase . ' أقل من المبلغ المدفوع');
            }
        }
        $remaining = $total_bill - $paid_amount;
        $status = ($remaining <= 0) ? 'paid' : (($paid_amount > 0) ? 'partial' : 'pending');
        $stmt = $pdo->prepare("UPDATE purchases SET total_amount = ?, paid_amount = ?, remaining_amount = ?, payment_status = ? WHERE id = ?");
        $stmt->execute([$total_bill, $paid_amount, $remaining, $status, $purchase_id]);

        $stmt = $pdo->prepare("UPDATE suppliers SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$remaining, $supplier_id]);

        if ($paid_amount > 0) {
            recordTransaction($pdo, [
                'direction' => 'out',
                'amount' => $paid_amount,
                'payment_method' => $payment_method_purchase,
                'description' => 'دفعة عند شراء — فاتورة مشتريات #' . $purchase_id,
                'user_id' => (int) $_SESSION['user_id'],
                'related_type' => 'supplier_payment',
                'related_id' => (int) $purchase_id,
                'supplier_id' => (int) $supplier_id,
                'payment_type' => 'cash',
            ]);
            if ($payment_method_purchase !== 'cash') {
                $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE wallet_name = ?")
                    ->execute([$paid_amount, $payment_method_purchase]);
            }
        }

        $pdo->commit();
        $_SESSION['toast'] = ['message' => '✅ تم حفظ فاتورة المشتريات بنجاح', 'type' => 'success'];
        header("Location: purchases.php"); exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['toast'] = ['message' => '❌ خطأ في النظام: ' . $e->getMessage(), 'type' => 'error'];
        header("Location: purchases.php"); exit();
    }
}

require_once '../includes/header.php'; 
?>

<style>
    :root {
        --primary-color: #6366f1; /* Indigo */
        --dark-bg: #0f172a;
        --soft-gray: #f8fafc;
        --border-color: #e2e8f0;
        --accent-success: #10b981;
        --accent-danger: #ef4444;
        --accent-warning: #f59e0b;
    }

    [data-theme="dark"] {
        --soft-gray: #1e293b;
        --border-color: #334155;
    }

    body {
        background-color: var(--soft-gray);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
        color: #1e293b;
        transition: all 0.3s ease;
    }

    /* ✨ Card & Layout */
    .purchase-card {
        border-radius: 20px;
        background: #ffffff;
        border: 1px solid var(--border-color);
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
        overflow: hidden;
        animation: fadeIn 0.6s ease;
    }

    [data-theme="dark"] .purchase-card {
        background: #0f172a;
    }

    .card-header-gradient {
        background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        padding: 1.5rem;
    }

    /* 📝 Table Styling */
    .modern-table {
        margin-bottom: 0;
    }

    .modern-table thead th {
        background: var(--soft-gray);
        color: #64748b;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        padding: 1rem;
        border-bottom: 2px solid var(--border-color);
    }

    .modern-table tbody td {
        padding: 0.75rem;
        vertical-align: middle;
        border-bottom: 1px solid var(--border-color);
    }

    .form-control-modern {
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        transition: all 0.2s;
        background: transparent;
        color: inherit;
    }

    .form-control-modern:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        background: #fff;
    }

    .form-control-modern.is-invalid {
        border-color: var(--accent-danger);
        background-color: rgba(239, 68, 68, 0.05);
    }

    /* 📊 Total Panel */
    .total-panel {
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        border-radius: 16px;
        padding: 1.5rem;
        border: 1px solid var(--border-color);
    }

    [data-theme="dark"] .total-panel {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    }

    .grand-total-display {
        font-size: 2rem;
        font-weight: 800;
        color: var(--primary-color);
    }

    .btn-add-row {
        border: 2px dashed var(--primary-color);
        color: var(--primary-color);
        background: transparent;
        border-radius: 10px;
        padding: 0.5rem 1.5rem;
        font-weight: 600;
        transition: all 0.3s;
    }

    .btn-add-row:hover {
        background: var(--primary-color);
        color: white;
    }

    /* 🍞 Toast Notification System */
    .toast-container {
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 10px;
        width: 90%;
        max-width: 400px;
        pointer-events: none;
    }

    .toast {
        background: white;
        border-radius: 12px;
        padding: 1rem 1.25rem;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        display: flex;
        align-items: center;
        gap: 12px;
        border: 1px solid var(--border-color);
        animation: toastSlideIn 0.3s ease;
        pointer-events: auto;
        backdrop-filter: blur(10px);
    }

    [data-theme="dark"] .toast {
        background: #1e293b;
        border-color: #334155;
    }

    .toast.success {
        border-right: 4px solid var(--accent-success);
    }
    .toast.error {
        border-right: 4px solid var(--accent-danger);
    }
    .toast.warning {
        border-right: 4px solid var(--accent-warning);
    }
    .toast.info {
        border-right: 4px solid var(--primary-color);
    }

    .toast-icon {
        font-size: 1.5rem;
    }
    .toast.success .toast-icon { color: var(--accent-success); }
    .toast.error .toast-icon { color: var(--accent-danger); }
    .toast.warning .toast-icon { color: var(--accent-warning); }
    .toast.info .toast-icon { color: var(--primary-color); }

    .toast-content {
        flex: 1;
        font-size: 0.95rem;
        font-weight: 500;
    }

    .toast-close {
        background: none;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        font-size: 1.2rem;
        padding: 0 4px;
    }

    @keyframes toastSlideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes toastSlideOut {
        to {
            opacity: 0;
            transform: translateY(-20px);
        }
    }

    /* 🌙 Theme Toggle */
    .theme-switch-btn {
        position: fixed;
        bottom: 20px;
        left: 20px;
        z-index: 1000;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: var(--primary-color);
        color: white;
        border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .theme-switch-btn:hover {
        transform: scale(1.1) rotate(15deg);
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Barcode warning */
    .barcode-warning {
        font-size: 0.7rem;
        color: var(--accent-warning);
        margin-top: 2px;
    }
</style>

<div class="container-fluid py-4" dir="rtl">
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 fw-bold mb-0"><i class="bi bi-bag-plus-fill text-primary me-2"></i> إنشاء فاتورة مشتريات</h2>
        <div class="text-muted small">تاريخ اليوم: <?= date('Y/m/d') ?></div>
    </div>

    <?php if (isset($_SESSION['toast'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast(<?= json_encode($_SESSION['toast']['message']) ?>, '<?= $_SESSION['toast']['type'] ?>');
            });
        </script>
        <?php unset($_SESSION['toast']); ?>
    <?php endif; ?>

    <div class="purchase-card">
        <div class="card-header-gradient d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-white"><i class="bi bi-file-earmark-text me-2"></i> بيانات الفاتورة والمورد</h5>
            <span class="badge bg-primary text-uppercase">Smart Purchase V2.6</span>
        </div>

        <div class="card-body p-4">
            <form method="POST" id="purchaseForm">
                <datalist id="products_datalist">
                    <?php foreach($products_for_datalist as $p): ?>
                        <option value="<?= htmlspecialchars($p['name']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="row mb-5 g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">اختر المورد</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0"><i class="bi bi-person-badge"></i></span>
                            <select name="supplier_id" class="form-select form-control-modern border-0 bg-light" required>
                                <option value="">-- ابحث عن مورد --</option>
                                <?php foreach($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="table-responsive mb-4 rounded-3 border">
                    <table class="table modern-table table-hover align-middle" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="min-width: 200px;">اسم الصنف</th>
                                <th>الباركود</th>
                                <th style="width: 120px;">الكمية</th>
                                <th style="width: 150px;">سعر التكلفة</th>
                                <th style="width: 150px;">سعر البيع</th>
                                <th style="width: 150px;">أدنى بيع</th>
                                <th style="width: 150px;">الإجمالي</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="item-row">
                                <td><input type="text" name="items[0][name]" class="form-control-modern w-100" placeholder="مثال: آيفون 15" required list="products_datalist"></td>
                                <td>
                                    <input type="text" name="items[0][barcode]" class="form-control-modern w-100 barcode-input" placeholder="اختياري">
                                    <div class="barcode-warning"></div>
                                </td>
                                <td><input type="number" name="items[0][qty]" class="form-control-modern w-100 qty text-center" value="1" min="1"></td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number" step="0.01" name="items[0][price]" class="form-control-modern w-100 price" placeholder="0.00" required>
                                    </div>
                                </td>
                                <td><input type="number" step="0.01" name="items[0][sell_p]" class="form-control-modern w-100" placeholder="0.00"></td>
                                <td><input type="number" step="0.01" name="items[0][min_p]" class="form-control-modern w-100" placeholder="0.00"></td>
                                <td><input type="text" class="form-control-modern w-100 row-total fw-bold text-dark bg-light" readonly value="0.00"></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-start mb-5">
                    <button type="button" class="btn btn-add-row" onclick="addRow()">
                        <i class="bi bi-plus-lg me-2"></i> إضافة صنف جديد (F2)
                    </button>
                </div>

                <hr class="my-4 opacity-50">

                <div class="row justify-content-end">
                    <div class="col-xl-4 col-lg-5 col-md-6">
                        <div class="total-panel shadow-sm">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <span class="text-muted fw-bold">إجمالي الفاتورة:</span>
                                <div class="text-end">
                                    <span class="grand-total-display" id="grandTotal">0.00</span>
                                    <small class="text-muted ms-1">ج.م</small>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-primary">المبلغ المدفوع الآن:</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-cash-stack text-success"></i></span>
                                    <input type="number" name="paid_amount" id="paidAmount" class="form-control form-control-lg border-start-0 fw-bold" value="0" step="0.01">
                                </div>
                                <label class="form-label small fw-bold text-muted mt-3">طريقة الدفع للمورد:</label>
                                <select name="payment_method_purchase" id="paymentMethodPurchase" class="form-select form-control-modern">
                                    <option value="cash">كاش</option>
                                    <option value="vodafone">فودافون كاش</option>
                                    <option value="bank">بنك</option>
                                </select>
                                <div id="remainingText" class="small mt-2 text-muted"></div>
                            </div>

                            <button type="submit" name="save_purchase" class="btn btn-primary btn-lg w-100 py-3 fw-bold shadow" onclick="return validateForm()">
                                <i class="bi bi-cloud-check me-2"></i> ترحيل الفاتورة للمخزن
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<button class="theme-switch-btn" onclick="toggleLocalTheme()"><i class="bi bi-moon-stars"></i></button>

<script>
let rowIdx = 1;
let checkBarcodeTimeout;

// ========== Toast Notification System ==========
function showToast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toastContainer');
    const toastId = 'toast-' + Date.now();
    
    const icons = {
        success: 'bi-check-circle-fill',
        error: 'bi-exclamation-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill'
    };
    
    const toast = document.createElement('div');
    toast.id = toastId;
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <div class="toast-icon"><i class="bi ${icons[type] || icons.info}"></i></div>
        <div class="toast-content">${message}</div>
        <button class="toast-close" onclick="this.closest('.toast').remove()">&times;</button>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        const toastEl = document.getElementById(toastId);
        if (toastEl) {
            toastEl.style.animation = 'toastSlideOut 0.3s ease';
            setTimeout(() => toastEl.remove(), 300);
        }
    }, duration);
}

// ========== Barcode Check Function ==========
function checkBarcode(input, row) {
    const barcode = input.value.trim();
    const warningDiv = row.querySelector('.barcode-warning');
    
    if (barcode.length < 2) {
        input.classList.remove('is-invalid');
        input.classList.remove('is-valid');
        warningDiv.innerHTML = '';
        return;
    }
    
    clearTimeout(checkBarcodeTimeout);
    checkBarcodeTimeout = setTimeout(() => {
        fetch(`purchases.php?action=check_barcode&barcode=${encodeURIComponent(barcode)}`)
            .then(res => res.json())
            .then(data => {
                if (data.exists) {
                    // هذا الباركود موجود مسبقاً -> نسمح بالعملية (سيتم تحديث نفس المنتج)
                    input.classList.remove('is-invalid');
                    input.classList.add('is-valid');
                    warningDiv.innerHTML = `⚠️ ${data.message}`;
                    showToast(data.message, 'warning');
                } else {
                    input.classList.remove('is-invalid');
                    input.classList.remove('is-valid');
                    warningDiv.innerHTML = '';
                }
            })
            .catch(err => console.error('Error checking barcode:', err));
    }, 500);
}

// ========== Add Row Function ==========
function addRow() {
    const tbody = document.querySelector('#itemsTable tbody');
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.innerHTML = `
                        <td><input type="text" name="items[${rowIdx}][name]" class="form-control-modern w-100" required list="products_datalist"></td>
        <td>
            <input type="text" name="items[${rowIdx}][barcode]" class="form-control-modern w-100 barcode-input" placeholder="اختياري">
            <div class="barcode-warning"></div>
        </td>
        <td><input type="number" name="items[${rowIdx}][qty]" class="form-control-modern w-100 qty text-center" value="1" min="1"></td>
        <td><input type="number" step="0.01" name="items[${rowIdx}][price]" class="form-control-modern w-100 price" required></td>
        <td><input type="number" step="0.01" name="items[${rowIdx}][sell_p]" class="form-control-modern w-100"></td>
        <td><input type="number" step="0.01" name="items[${rowIdx}][min_p]" class="form-control-modern w-100"></td>
        <td><input type="text" class="form-control-modern w-100 row-total fw-bold text-dark bg-light" readonly value="0.00"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="this.closest('tr').remove(); calc();"><i class="bi bi-trash3"></i></button></td>
    `;
    tbody.appendChild(row);
    rowIdx++;
    
    // Add barcode check listener
    const barcodeInput = row.querySelector('.barcode-input');
    barcodeInput.addEventListener('input', function() { checkBarcode(this, row); });
    
    row.querySelector('input').focus();
}

// ========== Form Validation ==========
function validateForm() {
    // نسمح بالباركودات الموجودة لأن النظام سيستخدم نفس المنتج بدل إنشاء منتج جديد
    return true;
}

// ========== Calculations ==========
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('qty') || e.target.classList.contains('price') || e.target.id === 'paidAmount') {
        calc();
    }
    if (e.target.classList.contains('barcode-input')) {
        const row = e.target.closest('tr');
        checkBarcode(e.target, row);
    }
});

function calc() {
    let grand = 0;
    document.querySelectorAll('.item-row').forEach(tr => {
        const q = parseFloat(tr.querySelector('.qty').value) || 0;
        const p = parseFloat(tr.querySelector('.price').value) || 0;
        const total = q * p;
        tr.querySelector('.row-total').value = total.toLocaleString('en-US', {minimumFractionDigits: 2});
        grand += total;
    });

    document.getElementById('grandTotal').innerText = grand.toLocaleString('en-US', {minimumFractionDigits: 2});
    
    const paid = parseFloat(document.getElementById('paidAmount').value) || 0;
    const remaining = grand - paid;
    const remElement = document.getElementById('remainingText');
    
    if (remaining > 0) {
        remElement.innerHTML = `⚠️ سيتم تسجيل <b>${remaining.toFixed(2)}</b> كمديونية للمورد`;
        remElement.className = "small mt-2 text-danger";
    } else if (remaining < 0) {
        remElement.innerHTML = "⚠️ المبلغ المدفوع أكبر من إجمالي الفاتورة";
        remElement.className = "small mt-2 text-warning";
    } else {
        remElement.innerHTML = "✅ الفاتورة مدفوعة بالكامل";
        remElement.className = "small mt-2 text-success";
    }
}

// ========== Theme Toggle ==========
function toggleLocalTheme() {
    const html = document.documentElement;
    const isDark = html.getAttribute('data-theme') === 'dark';
    html.setAttribute('data-theme', isDark ? 'light' : 'dark');
    localStorage.setItem('theme', isDark ? 'light' : 'dark');
    
    const icon = document.querySelector('.theme-switch-btn i');
    icon.className = isDark ? 'bi bi-moon-stars' : 'bi bi-sun';
}

// ========== Keyboard Shortcuts ==========
document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        addRow();
    }
});

// ========== Initialize Theme ==========
document.addEventListener('DOMContentLoaded', function() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    
    const icon = document.querySelector('.theme-switch-btn i');
    icon.className = savedTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
    
    // Add barcode check to existing rows
    document.querySelectorAll('.item-row').forEach(row => {
        const barcodeInput = row.querySelector('.barcode-input');
        if (barcodeInput) {
            barcodeInput.addEventListener('input', function() { checkBarcode(this, row); });
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>