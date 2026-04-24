<?php 
/* File Path: http://localhost/project/ERP_System_V2/views/customers.php
Description: إدارة العملاء كاملة (CRUD) - واجهة عصرية 2026
*/
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// --- (نفس المنطق البرمجي بدون أي تعديل كما طلبت) ---
if (isset($_POST['add_customer'])) {
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $balance = (float)$_POST['balance'];
    $pdo->prepare("INSERT INTO customers (name, phone, balance) VALUES (?, ?, ?)")->execute([$name, $phone, $balance]);
    header("Location: customers.php?msg=added"); exit();
}

if (isset($_POST['edit_customer'])) {
    $id = $_POST['customer_id'];
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $pdo->prepare("UPDATE customers SET name = ?, phone = ? WHERE id = ?")->execute([$name, $phone, $id]);
    header("Location: customers.php?msg=updated"); exit();
}

if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    $pdo->prepare("UPDATE customers SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    header("Location: customers.php?msg=deleted"); exit();
}

if (isset($_POST['collect_payment'])) {
    $customer_id = $_POST['customer_id'];
    $amount = (float)$_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $user_id = $_SESSION['user_id'];

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?")->execute([$amount, $customer_id]);
        $cn = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
        $cn->execute([$customer_id]);
        $customer_name = $cn->fetchColumn();
        $desc = "تحصيل مديونية من العميل: " . $customer_name;
        recordTransaction($pdo, [
            'direction' => 'in',
            'amount' => $amount,
            'payment_method' => $payment_method,
            'description' => $desc,
            'user_id' => (int) $user_id,
            'related_type' => 'customer_collection',
            'related_id' => (int) $customer_id,
        ]);

        if ($payment_method !== 'cash') {
            $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE wallet_name = ?")->execute([$amount, $payment_method]);
        }
        
        $pdo->commit();
        header("Location: customers.php?msg=collected"); exit();
    } catch (Exception $e) { 
        $pdo->rollBack(); 
        die("خطأ في عملية التحصيل: " . $e->getMessage()); 
    }
}

$customers = $pdo->query("SELECT * FROM customers WHERE deleted_at IS NULL ORDER BY balance DESC")->fetchAll();
require_once '../includes/header.php'; 
?>

<style>
    /* 2026 Modern UI Variables */
    :root {
        --primary-indigo: #6366f1;
        --secondary-soft: #f1f5f9;
        --accent-success: #22c55e;
        --accent-danger: #ef4444;
        --glass-effect: rgba(255, 255, 255, 0.75);
        --card-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02);
        --transition-soft: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Dark Mode variables */
    [data-theme="dark"] {
        --bg-main: #0f172a;
        --bg-card: #1e293b;
        --text-main: #f8fafc;
        --text-muted: #94a3b8;
        --border-color: #334155;
    }

    body {
        background-color: var(--bg-main, #f8fafc);
        color: var(--text-main, #1e293b);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
        transition: var(--transition-soft);
    }

    /* Animation Keyframes */
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(15px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .customer-card {
        animation: slideUp 0.4s ease-out backwards;
        border: 1px solid var(--border-color, #e2e8f0);
        background: var(--bg-card, #ffffff);
        border-radius: 1.25rem;
        transition: var(--transition-soft);
        overflow: hidden;
    }

    .customer-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        border-color: var(--primary-indigo);
    }

    /* Header Styling */
    .page-header {
        background: var(--glass-effect);
        backdrop-filter: blur(10px);
        border-radius: 1.25rem;
        padding: 1.5rem;
        margin-bottom: 2.5rem;
        border: 1px solid var(--border-color, #e2e8f0);
    }

    /* Balance Badge */
    .balance-badge {
        padding: 1.25rem;
        border-radius: 1rem;
        background: var(--secondary-soft, #f8fafc);
        margin-bottom: 1.5rem;
        transition: var(--transition-soft);
    }

    [data-theme="dark"] .balance-badge { background: #0f172a; }

    /* Buttons Modern Look */
    .btn-modern {
        border-radius: 0.75rem;
        padding: 0.6rem 1.2rem;
        font-weight: 600;
        transition: var(--transition-soft);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-collect { background-color: var(--accent-success); color: white; border: none; }
    .btn-collect:hover { background-color: #16a34a; transform: scale(1.02); }
    .btn-collect:disabled { background-color: #d1d5db; opacity: 0.6; }

    /* Floating Action Button for Theme */
    .theme-toggle {
        position: fixed;
        bottom: 2rem;
        left: 2rem;
        z-index: 999;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: var(--primary-indigo);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 10px 15px rgba(99, 102, 241, 0.4);
        transition: var(--transition-soft);
    }

    /* Alert Styling */
    .custom-alert {
        border-radius: 1rem;
        border: none;
        background: #dcfce7;
        color: #166534;
        padding: 1rem 1.5rem;
        box-shadow: var(--card-shadow);
    }

    /* Form Controls */
    .form-control, .form-select {
        border-radius: 0.75rem;
        padding: 0.75rem 1rem;
        border: 1px solid var(--border-color, #cbd5e1);
        background-color: var(--bg-card, #fff);
        color: var(--text-main, #1e293b);
    }

    .form-control:focus {
        border-color: var(--primary-indigo);
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    }

    /* Modal Styling */
    .modal-content {
        border-radius: 1.5rem;
        border: none;
        background-color: var(--bg-card, #fff);
    }
</style>

<!-- <div class="theme-toggle" id="themeBtn" onclick="toggleTheme()">
    <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
</div> -->

<div class="container-fluid py-4">
    <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
        <div>
            <h2 class="h3 mb-1 text-dark fw-extrabold"><i class="bi bi-people-fill text-primary me-2"></i>إدارة شركاء النجاح</h2>
            <p class="text-muted mb-0 small">إدارة مديونيات العملاء والتحصيل المالي الفوري</p>
        </div>
        <button class="btn btn-modern btn-primary px-4 py-2" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
            <i class="bi bi-person-plus-fill"></i> إضافة عميل جديد
        </button>
    </div>

    <?php if(isset($_GET['msg'])): ?>
        <div class="alert custom-alert d-flex align-items-center mb-4 animate__animated animate__fadeIn">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
            <span>تم تنفيذ العملية المطلوبة بنجاح.</span>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="input-group border-0 shadow-sm rounded-pill overflow-hidden bg-white">
                <span class="input-group-text bg-white border-0 ps-3"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="customerSearch" class="form-control border-0 shadow-none py-2" placeholder="بحث عن عميل...">
            </div>
        </div>
    </div>

    <div class="row" id="customersGrid">
        <?php foreach($customers as $index => $c): ?>
        <div class="col-xl-3 col-lg-4 col-md-6 mb-4 customer-item" style="animation-delay: <?= $index * 0.05 ?>s">
            <div class="card customer-card h-100 border-0 shadow-sm">
                <div class="position-absolute top-0 end-0 p-3">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light rounded-circle shadow-sm" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4">
                            <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#editModal<?= $c['id'] ?>"><i class="bi bi-pencil me-2"></i> تعديل البيانات</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger" href="?delete_id=<?= $c['id'] ?>" onclick="return confirm('هل أنت متأكد من حذف هذا العميل نهائياً؟')"><i class="bi bi-trash me-2"></i> حذف العميل</a></li>
                        </ul>
                    </div>
                </div>

                <div class="card-body pt-5">
                    <div class="text-center mb-3">
                        <div class="avatar-placeholder mx-auto mb-2 d-flex align-items-center justify-content-center bg-primary text-white rounded-circle fw-bold" style="width: 60px; height: 60px; font-size: 1.5rem;">
                            <?= mb_substr($c['name'], 0, 1, 'utf-8') ?>
                        </div>
                        <h5 class="fw-bold mb-0 customer-name"><?= htmlspecialchars($c['name']) ?></h5>
                        <p class="text-muted small"><i class="bi bi-telephone-outbound me-1"></i> <?= htmlspecialchars($c['phone'] ?: '—') ?></p>
                    </div>
                    
                    <div class="balance-badge text-center">
                        <span class="small text-muted d-block mb-1">صافي الرصيد الحالي</span>
                        <h3 class="mb-0 fw-bold <?= $c['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= number_format($c['balance'], 2) ?> <small class="fs-6 fw-normal">ج.م</small>
                        </h3>
                    </div>

                    <div class="d-grid gap-2">
                        <button class="btn btn-modern btn-collect w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#payModal<?= $c['id'] ?>" <?= $c['balance'] <= 0 ? 'disabled' : '' ?>>
                            <i class="bi bi-cash-stack"></i> تحصيل مالي
                        </button>
                        <a href="customer_report.php?id=<?= $c['id'] ?>" class="btn btn-modern btn-outline-dark w-100 justify-content-center">
                            <i class="bi bi-file-earmark-bar-graph"></i> كشف الحساب
                        </a>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="editModal<?= $c['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <form method="POST" class="modal-content overflow-hidden">
                        <div class="modal-header bg-dark text-white p-4">
                            <h5 class="modal-title fw-bold">تعديل الملف الشخصي</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <div class="mb-3">
                                <label class="small fw-bold mb-2">الاسم الكامل للعميل</label>
                                <input type="text" name="name" class="form-control shadow-sm" value="<?= htmlspecialchars($c['name']) ?>" required>
                            </div>
                            <div class="mb-0">
                                <label class="small fw-bold mb-2">رقم التواصل المعتمد</label>
                                <input type="text" name="phone" class="form-control shadow-sm" value="<?= htmlspecialchars($c['phone']) ?>">
                            </div>
                        </div>
                        <div class="modal-footer border-0 p-4 pt-0">
                            <button name="edit_customer" class="btn btn-modern btn-primary w-100 justify-content-center py-3">حفظ التغييرات الجديدة</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="modal fade" id="payModal<?= $c['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered modal-sm">
                    <form method="POST" class="modal-content overflow-hidden">
                        <div class="modal-header bg-success text-white p-3">
                            <h6 class="modal-title fw-bold"><i class="bi bi-shield-check"></i> عملية تحصيل سريعة</h6>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <div class="mb-4">
                                <label class="small fw-bold text-muted mb-2">أدخل القيمة المحصلة</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" name="amount" class="form-control text-center fs-3 fw-bold border-success text-success" max="<?= $c['balance'] ?>" placeholder="0.00" required>
                                </div>
                                <div class="text-muted smaller mt-1">أقصى مبلغ مسموح: <?= number_format($c['balance'], 2) ?></div>
                            </div>
                            <div class="mb-0">
                                <label class="small fw-bold text-muted mb-2">قناة الإيداع</label>
                                <select name="payment_method" class="form-select shadow-sm">
                                    <option value="cash">💵 كاش (الخزنة)</option>
                                    <option value="vodafone">📱 فودافون كاش</option>
                                    <option value="bank">🏦 تحويل بنكي</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer border-0 p-4 pt-0">
                            <button name="collect_payment" class="btn btn-modern btn-success w-100 justify-content-center py-3 fs-6">تأكيد عملية التحصيل</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content shadow-lg border-0">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title fw-bold">إضافة شريك عمل جديد</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-4">
                    <label class="form-label small fw-bold">الاسم التجاري أو الشخصي</label>
                    <input type="text" name="name" class="form-control" required placeholder="مثال: شركة النور للتوريدات">
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-bold">رقم الهاتف</label>
                    <input type="text" name="phone" class="form-control" placeholder="01XXXXXXXXX">
                </div>
                <div class="mb-0 p-3 rounded-4 bg-light border">
                    <label class="form-label small fw-bold text-primary">الرصيد الافتتاحي (مديونية سابقة)</label>
                    <div class="input-group">
                        <input type="number" step="0.01" name="balance" class="form-control fw-bold border-0 bg-transparent fs-5" value="0.00">
                        <span class="input-group-text bg-transparent border-0 fw-bold text-muted">ج.م</span>
                    </div>
                    <small class="text-muted">اتركه 0 إذا كان العميل جديد بدون ديون</small>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="submit" name="add_customer" class="btn btn-modern btn-primary w-100 justify-content-center py-3 fs-6">تسجيل العميل في النظام</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Theme Toggle Functionality
    function toggleTheme() {
        const body = document.body;
        const icon = document.getElementById('themeIcon');
        const currentTheme = body.getAttribute('data-theme');
        
        if (currentTheme === 'dark') {
            body.removeAttribute('data-theme');
            icon.className = 'bi bi-moon-stars-fill';
            localStorage.setItem('theme', 'light');
        } else {
            body.setAttribute('data-theme', 'dark');
            icon.className = 'bi bi-sun-fill';
            localStorage.setItem('theme', 'dark');
        }
    }

    // Load saved theme
    document.addEventListener('DOMContentLoaded', () => {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.body.setAttribute('data-theme', 'dark');
            document.getElementById('themeIcon').className = 'bi bi-sun-fill';
        }

        // Search Filter UX
        const searchInput = document.getElementById('customerSearch');
        searchInput.addEventListener('input', function() {
            const val = this.value.toLowerCase();
            document.querySelectorAll('.customer-item').forEach(item => {
                const name = item.querySelector('.customer-name').textContent.toLowerCase();
                item.style.display = name.includes(val) ? 'block' : 'none';
            });
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>