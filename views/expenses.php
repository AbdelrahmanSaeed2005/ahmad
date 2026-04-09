<?php 
/* File Path: views/expenses.php 
   Description: إدارة المصروفات - واجهة عصرية Modern UI 2026
*/
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS expense_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(191) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_expense_type_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if ((int) $pdo->query('SELECT COUNT(*) FROM expense_types')->fetchColumn() === 0) {
    $seed = ['إيجار', 'كهرباء/مياه', 'مرتبات', 'بضاعة', 'سلف عمال', 'أخرى'];
    $insSeed = $pdo->prepare('INSERT IGNORE INTO expense_types (name) VALUES (?)');
    foreach ($seed as $n) {
        $insSeed->execute([$n]);
    }
}

if (isset($_POST['add_expense_type']) && isset($_POST['type_name'])) {
    $n = trim((string) $_POST['type_name']);
    if ($n !== '') {
        try {
            $pdo->prepare('INSERT INTO expense_types (name) VALUES (?)')->execute([$n]);
            header('Location: expenses.php?msg=type_added');
        } catch (Throwable $e) {
            header('Location: expenses.php?msg=type_dup');
        }
    }
    exit;
}

if (isset($_POST['edit_expense_type']) && isset($_POST['type_id'], $_POST['type_name'])) {
    $id = (int) $_POST['type_id'];
    $n = trim((string) $_POST['type_name']);
    if ($id > 0 && $n !== '') {
        $old = $pdo->prepare('SELECT name FROM expense_types WHERE id = ?');
        $old->execute([$id]);
        $prev = $old->fetchColumn();
        try {
            $pdo->prepare('UPDATE expense_types SET name = ? WHERE id = ?')->execute([$n, $id]);
            if ($prev && $prev !== $n) {
                $pdo->prepare('UPDATE expenses SET category = ?, description = ? WHERE category = ?')
                    ->execute([$n, $n, $prev]);
            }
            header('Location: expenses.php?msg=type_edited');
        } catch (Throwable $e) {
            header('Location: expenses.php?msg=type_dup');
        }
    }
    exit;
}

if (isset($_GET['delete_type_id'])) {
    $tid = (int) $_GET['delete_type_id'];
    if ($tid > 0) {
        $pdo->prepare('DELETE FROM expense_types WHERE id = ?')->execute([$tid]);
    }
    header('Location: expenses.php?msg=type_deleted');
    exit;
}

// --- (المنطق البرمجي: الإضافة، التعديل، الحذف - بدون أي تغيير) ---
if (isset($_POST['add_expense'])) {
    $amount = (float)$_POST['amount'];
    $category = $_POST['category'];
    $notes = $_POST['notes']; 
    $payment_method = $_POST['payment_method']; 
    $user_id = $_SESSION['user_id'];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO expenses (category, amount, description, notes, user_id, payment_method) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$category, $amount, $category, $notes, $user_id, $payment_method]);
        $expense_id = $pdo->lastInsertId();

        $desc_for_cash = "مصروف: " . $category . " - " . $notes;
        recordTransaction($pdo, [
            'direction' => 'out',
            'amount' => $amount,
            'payment_method' => $payment_method,
            'description' => $desc_for_cash,
            'user_id' => (int) $user_id,
            'related_type' => 'general_expense',
            'related_id' => (int) $expense_id,
        ]);

        if ($payment_method !== 'cash') {
            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE wallet_name = ?")->execute([$amount, $payment_method]);
        }
        $pdo->commit();
        header("Location: expenses.php?msg=added"); exit();
    } catch (Exception $e) { $pdo->rollBack(); die("خطأ: " . $e->getMessage()); }
}

if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    try {
        $pdo->beginTransaction();
        $ex = $pdo->prepare("SELECT amount, payment_method FROM expenses WHERE id = ?");
        $ex->execute([$id]);
        $data = $ex->fetch();
        
        if ($data && $data['payment_method'] !== 'cash') {
            $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE wallet_name = ?")->execute([$data['amount'], $data['payment_method']]);
        }

        $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM cash_transactions WHERE related_type = 'general_expense' AND related_id = ?")->execute([$id]);
        
        $pdo->commit();
        header("Location: expenses.php?msg=deleted"); exit();
    } catch (Exception $e) { $pdo->rollBack(); die("خطأ في الحذف: " . $e->getMessage()); }
}

$expense_types = $pdo->query('SELECT * FROM expense_types ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$filter_category = trim((string) ($_GET['filter_category'] ?? ''));
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));

$expWhere = ['1=1'];
$expParams = [];
if ($filter_category !== '') {
    $expWhere[] = 'category = ?';
    $expParams[] = $filter_category;
}
if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $expWhere[] = 'DATE(created_at) >= ?';
    $expParams[] = $date_from;
}
if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $expWhere[] = 'DATE(created_at) <= ?';
    $expParams[] = $date_to;
}
$expSql = 'SELECT * FROM expenses WHERE ' . implode(' AND ', $expWhere) . ' ORDER BY created_at DESC LIMIT 500';
$expStmt = $pdo->prepare($expSql);
$expStmt->execute($expParams);
$expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);

$sumFiltered = 0.0;
foreach ($expenses as $ex) {
    $sumFiltered += (float) $ex['amount'];
}

require_once '../includes/header.php'; 
?>

<style>
    :root {
        --primary-red: #ef4444;
        --bg-main: #f8fafc;
        --bg-card: #ffffff;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    [data-theme="dark"] {
        --bg-main: #0f172a;
        --bg-card: #1e293b;
        --text-main: #f1f5f9;
        --text-muted: #94a3b8;
        --border-color: #334155;
    }

    body {
        background-color: var(--bg-main);
        color: var(--text-main);
        transition: var(--transition);
        direction: rtl;
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
    }

    /* Page Header Styles */
    .page-header {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        padding: 1.5rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    }

    /* Theme Toggle */
    .theme-toggle-btn {
        background: var(--bg-main);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: var(--transition);
    }
    .theme-toggle-btn:hover { transform: scale(1.1); }

    /* Expenses Table Design */
    .custom-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        overflow: hidden;
        animation: fadeIn 0.5s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .table thead th {
        background: var(--bg-main);
        color: var(--text-muted);
        text-transform: uppercase;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    .table tbody td {
        padding: 1.25rem 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    /* Badges & UI Elements */
    .badge-expense {
        background: rgba(239, 68, 68, 0.1);
        color: var(--primary-red);
        padding: 0.5rem 0.75rem;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.85rem;
    }

    .payment-badge {
        background: var(--bg-main);
        border: 1px solid var(--border-color);
        padding: 0.35rem 0.6rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
    }

    /* Action Buttons */
    .btn-delete-soft {
        color: var(--text-muted);
        background: transparent;
        border: none;
        width: 35px;
        height: 35px;
        border-radius: 8px;
        transition: var(--transition);
    }
    .btn-delete-soft:hover {
        background: rgba(239, 68, 68, 0.1);
        color: var(--primary-red);
    }

    /* Form Styles in Modals */
    .modal-content {
        background: var(--bg-card);
        border-radius: 16px;
        border: none;
    }
    .form-control, .form-select {
        border-radius: 10px;
        border: 1.5px solid var(--border-color);
        padding: 0.75rem;
        background-color: var(--bg-main);
        color: var(--text-main);
    }
    .form-control:focus {
        border-color: var(--primary-red);
        box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
    }

    @media (max-width: 768px) {
        .page-header { flex-direction: column; gap: 1rem; text-align: center; }
        .table-responsive { border: 0; }
    }
</style>

<div class="container-fluid py-4">
    <div class="page-header">
        <div class="d-flex align-items-center gap-3">
            <button class="theme-toggle-btn" id="themeBtn" title="تبديل الوضع">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
            <div>
                <h2 class="h4 mb-0 fw-bold">إدارة المصروفات العامة</h2>
                <p class="text-muted mb-0 small">تتبع وخصم النفقات التشغيلية للمؤسسة</p>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-secondary btn-lg rounded-pill shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#manageExpenseTypesModal">
                <i class="bi bi-tags me-2"></i> أنواع المصروفات
            </button>
            <button class="btn btn-danger btn-lg rounded-pill shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                <i class="bi bi-plus-circle me-2"></i> تسجيل مصروف
            </button>
        </div>
    </div>

    <?php
    $expFlash = $_GET['msg'] ?? '';
    if ($expFlash === 'type_added') {
        echo '<div class="alert alert-success border-0 rounded-3 shadow-sm">تم إضافة نوع المصروف.</div>';
    } elseif ($expFlash === 'type_edited') {
        echo '<div class="alert alert-success border-0 rounded-3 shadow-sm">تم تحديث نوع المصروف والسجلات المرتبطة.</div>';
    } elseif ($expFlash === 'type_deleted') {
        echo '<div class="alert alert-info border-0 rounded-3 shadow-sm">تم حذف نوع المصروف من القائمة (سجلات المصروفات القديمة تبقى كما هي).</div>';
    } elseif ($expFlash === 'type_dup') {
        echo '<div class="alert alert-warning border-0 rounded-3 shadow-sm">اسم النوع مكرر أو غير صالح.</div>';
    }
    ?>

    <form method="get" class="custom-card shadow-sm p-3 mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted mb-1">نوع المصروف</label>
                <select name="filter_category" class="form-select">
                    <option value="">— كل الأنواع —</option>
                    <?php foreach ($expense_types as $t): ?>
                        <option value="<?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>"<?= $filter_category === $t['name'] ? ' selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted mb-1">من تاريخ</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted mb-1">إلى تاريخ</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-3 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i> تطبيق</button>
                <a href="expenses.php" class="btn btn-light border flex-grow-1">إعادة ضبط</a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="custom-card p-3 d-flex align-items-center gap-3 border-start border-danger border-4">
                <div class="bg-danger bg-opacity-10 p-3 rounded-circle text-danger">
                    <i class="bi bi-cash-stack fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">إجمالي النتائج المعروضة</div>
                    <div class="h5 fw-bold mb-0"><?= number_format($sumFiltered, 2) ?> ج.م</div>
                </div>
            </div>
        </div>
    </div>

    <div class="custom-card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead>
                    <tr>
                        <th class="text-end">تاريخ الصرف</th>
                        <th>التصنيف</th>
                        <th class="text-end">البيان / الملاحظات</th>
                        <th>القيمة</th>
                        <th>طريقة الدفع</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($expenses as $ex): ?>
                    <tr>
                        <td class="text-end">
                            <div class="fw-bold"><?= date('Y/m/d', strtotime($ex['created_at'])) ?></div>
                            <div class="text-muted smaller"><?= date('h:i A', strtotime($ex['created_at'])) ?></div>
                        </td>
                        <td><span class="badge-expense"><?= htmlspecialchars($ex['category']) ?></span></td>
                        <td class="text-end">
                            <div class="text-main small fw-medium"><?= htmlspecialchars($ex['notes']) ?></div>
                        </td>
                        <td class="fw-bold text-danger font-monospace">
                            <?= number_format($ex['amount'], 2) ?> <small>ج.م</small>
                        </td>
                        <td><span class="payment-badge"><?= strtoupper($ex['payment_method']) ?></span></td>
                        <td>
                            <a href="?delete_id=<?= $ex['id'] ?>" class="btn-delete-soft" 
                               onclick="return confirm('هل أنت متأكد؟ سيتم حذف المصروف وإرجاع القيمة للرصيد.')">
                                <i class="bi bi-trash3-fill"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($expenses)): ?>
                    <tr>
                        <td colspan="6" class="py-5 text-muted">لا يوجد سجل مصروفات حالياً</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-receipt me-2"></i>تسجيل حركة صرف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-end">
                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted">نوع المصروف</label>
                    <select name="category" class="form-select form-select-lg" required>
                        <?php foreach ($expense_types as $t): ?>
                            <option value="<?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="row g-3 mb-4">
                    <div class="col-6 text-end">
                        <label class="form-label small fw-bold text-muted">المبلغ (ج.م)</label>
                        <input type="number" step="0.01" name="amount" class="form-control form-control-lg fw-bold text-danger" required placeholder="0.00">
                    </div>
                    <div class="col-6 text-end">
                        <label class="form-label small fw-bold text-muted">مصدر الصرف</label>
                        <select name="payment_method" class="form-select form-select-lg" required>
                            <option value="cash">الخزنة (كاش)</option>
                            <option value="vodafone">فودافون كاش</option>
                            <option value="bank">البنك</option>
                        </select>
                    </div>
                </div>

                <div class="mb-0 text-end">
                    <label class="form-label small fw-bold text-muted">البيان / تفاصيل الملاحظة</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="اكتب تفاصيل المصروف هنا..."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="submit" name="add_expense" class="btn btn-danger btn-lg w-100 fw-bold rounded-pill">
                    <i class="bi bi-check2-circle me-1"></i> تأكيد وخصم المبلغ
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="manageExpenseTypesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0" style="border-radius:16px;">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-tags me-2 text-danger"></i> إدارة أنواع المصروفات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="post" class="row g-2 align-items-end mb-4 pb-3 border-bottom">
                    <div class="col-md-8">
                        <label class="form-label small fw-bold text-muted">اسم نوع جديد</label>
                        <input type="text" name="type_name" class="form-control" required maxlength="191" placeholder="مثال: مواصلات">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" name="add_expense_type" value="1" class="btn btn-danger w-100 rounded-pill fw-bold">إضافة</button>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>النوع</th><th class="text-center" style="width:200px">إجراءات</th></tr></thead>
                        <tbody>
                            <?php foreach ($expense_types as $t): ?>
                            <tr>
                                <td>
                                    <form method="post" class="d-flex gap-2 flex-wrap align-items-center">
                                        <input type="hidden" name="type_id" value="<?= (int) $t['id'] ?>">
                                        <input type="text" name="type_name" class="form-control form-control-sm" value="<?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>" required maxlength="191">
                                        <button type="submit" name="edit_expense_type" value="1" class="btn btn-sm btn-outline-primary rounded-pill">حفظ</button>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <a href="?delete_type_id=<?= (int) $t['id'] ?>" class="btn btn-sm btn-outline-danger rounded-pill" onclick="return confirm('حذف هذا النوع من القائمة؟');">حذف</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($expense_types)): ?>
                            <tr><td colspan="2" class="text-muted text-center py-3">لا توجد أنواع بعد</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Theme Switch Logic
    const themeBtn = document.getElementById('themeBtn');
    const themeIcon = document.getElementById('themeIcon');
    const body = document.body;

    function toggleTheme() {
        const currentTheme = body.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        body.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        
        themeIcon.className = newTheme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    }

    themeBtn.addEventListener('click', toggleTheme);

    // Initial Load
    const savedTheme = localStorage.getItem('theme') || 'light';
    body.setAttribute('data-theme', savedTheme);
    themeIcon.className = savedTheme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
</script>

<?php require_once '../includes/footer.php'; ?>