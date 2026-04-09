<?php
/* File Path: admin_dashboard.php */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// --- 1. إيداع / سحب — يُسجَّل في cash_transactions ليظهر في التقارير ويُحدّث أرصدة المحافظ ---
if (isset($_POST['adjust_balance'])) {
    $channel = trim((string) ($_POST['wallet_name'] ?? ''));
    $amount = (float) ($_POST['amount'] ?? 0);
    $adjType = $_POST['adj_type'] ?? '';

    $allowed = ['cash', 'vodafone', 'bank'];
    if (!in_array($channel, $allowed, true) || $amount <= 0 || !in_array($adjType, ['add', 'sub'], true)) {
        header('Location: admin_dashboard.php?error=invalid');
        exit();
    }

    $balStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0)
         FROM cash_transactions WHERE payment_method = ?"
    );
    $balStmt->execute([$channel]);
    $currentBalance = (float) $balStmt->fetchColumn();

    if ($adjType === 'sub' && $amount > $currentBalance + 0.0001) {
        header('Location: admin_dashboard.php?error=insufficient&wallet=' . urlencode($channel));
        exit();
    }

    $labels = [
        'cash' => 'الخزنة الرئيسية (كاش)',
        'vodafone' => 'فودافون كاش',
        'bank' => 'البنك',
    ];
    $label = $labels[$channel] ?? $channel;

    try {
        if ($adjType === 'add') {
            recordTransaction($pdo, [
                'direction' => 'in',
                'amount' => $amount,
                'payment_method' => $channel,
                'description' => 'إيداع يدوي — ' . $label,
                'user_id' => (int) $_SESSION['user_id'],
                'related_type' => 'wallet_adjustment',
            ]);
        } else {
            recordTransaction($pdo, [
                'direction' => 'out',
                'amount' => $amount,
                'payment_method' => $channel,
                'description' => 'سحب يدوي — ' . $label,
                'user_id' => (int) $_SESSION['user_id'],
                'related_type' => 'wallet_adjustment',
            ]);
        }

        $pdo->exec(
            "UPDATE wallets w SET balance = (
                SELECT COALESCE(SUM(CASE WHEN ct.type = 'income' THEN ct.amount ELSE -ct.amount END), 0)
                FROM cash_transactions ct WHERE ct.payment_method = 'vodafone'
            ) WHERE w.wallet_name = 'vodafone'"
        );
        $pdo->exec(
            "UPDATE wallets w SET balance = (
                SELECT COALESCE(SUM(CASE WHEN ct.type = 'income' THEN ct.amount ELSE -ct.amount END), 0)
                FROM cash_transactions ct WHERE ct.payment_method = 'bank'
            ) WHERE w.wallet_name = 'bank'"
        );
    } catch (Throwable $e) {
        header('Location: admin_dashboard.php?error=failed');
        exit();
    }

    header('Location: admin_dashboard.php?msg=updated');
    exit();
}

// جلب البيانات الإحصائية (Logic - لم يتم التغيير)
$last_withdraw = $pdo->query("SELECT MAX(created_at) FROM profit_withdrawals")->fetchColumn();
$filter_date = $last_withdraw ? $last_withdraw : '2000-01-01 00:00:00';
$today = date('Y-m-d');

// مبيعات اليوم (مع خصم المرتجعات)
$stmt = $pdo->prepare("SELECT (SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE type='income' AND related_type='sale' AND DATE(created_at) = ?) - (SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE related_type='return' AND DATE(created_at) = ?)");
$stmt->execute([$today, $today]);
$today_sales = $stmt->fetchColumn() ?: 0;

// المصاريف
$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE created_at > ?");
$stmt->execute([$filter_date]);
$total_expenses = $stmt->fetchColumn() ?: 0;

// أرباح المبيعات (يشمل الكريد لأن الربح مرتبط ببيع البضاعة وليس بوقت التحصيل)
$stmt = $pdo->prepare("SELECT SUM((ii.price - p.cost_price) * ii.quantity) FROM invoice_items ii JOIN products p ON ii.product_id = p.id JOIN invoices i ON ii.invoice_id = i.id WHERE i.created_at > ?");
$stmt->execute([$filter_date]);
$sales_profit = $stmt->fetchColumn() ?: 0;

// خسائر المرتجعات
$stmt = $pdo->prepare("SELECT SUM((r.return_price - (p.cost_price * r.quantity))) FROM returns r JOIN products p ON r.product_id = p.id WHERE r.created_at > ?");
$stmt->execute([$filter_date]);
$returns_loss = $stmt->fetchColumn() ?: 0;
$gross_profit = $sales_profit - $returns_loss;
$net_profit = $gross_profit - $total_expenses;

// ديون العملاء
$total_customer_debts = $pdo->query("SELECT SUM(balance) FROM customers")->fetchColumn() ?: 0;

// الخزنة الرئيسية
$cash_in_hand = (float) ($pdo->query("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM cash_transactions WHERE payment_method='cash'")->fetchColumn() ?: 0);

// نواقص المخزن
$low_stock_count = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= 5 AND deleted_at IS NULL")->fetchColumn();

// ✅ التأكد من وجود جدول wallets بالهيكل الصحيح
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `wallets` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `wallet_name` varchar(50) NOT NULL,
        `balance` decimal(10,2) DEFAULT 0.00,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `wallet_name` (`wallet_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ✅ التأكد من وجود سجلات الفودافون والبنك في جدول wallets
$pdo->exec("INSERT INTO wallets (wallet_name, balance) SELECT 'vodafone', 0 WHERE NOT EXISTS (SELECT 1 FROM wallets WHERE wallet_name = 'vodafone')");
$pdo->exec("INSERT INTO wallets (wallet_name, balance) SELECT 'bank', 0 WHERE NOT EXISTS (SELECT 1 FROM wallets WHERE wallet_name = 'bank')");

// ✅ تحديث أرصدة الفودافون والبنك من cash_transactions
$pdo->exec("
    UPDATE wallets w 
    SET balance = (
        SELECT COALESCE(SUM(CASE WHEN ct.type = 'income' THEN ct.amount ELSE -ct.amount END), 0)
        FROM cash_transactions ct 
        WHERE ct.payment_method = 'vodafone'
    )
    WHERE w.wallet_name = 'vodafone'
");

$pdo->exec("
    UPDATE wallets w 
    SET balance = (
        SELECT COALESCE(SUM(CASE WHEN ct.type = 'income' THEN ct.amount ELSE -ct.amount END), 0)
        FROM cash_transactions ct 
        WHERE ct.payment_method = 'bank'
    )
    WHERE w.wallet_name = 'bank'
");

$wallets = $pdo->query("SELECT * FROM wallets")->fetchAll();

require_once '../includes/header.php'; 
?>

<style>
    :root {
        --erp-bg: #f8fafc;
        --erp-card: #ffffff;
        --erp-text-main: #1e293b;
        --erp-text-muted: #64748b;
        --erp-border: #e2e8f0;
        --erp-primary: #4f46e5;
        --erp-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    /* Dark Mode Class */
    [data-theme="dark"] {
        --erp-bg: #0f172a;
        --erp-card: #1e293b;
        --erp-text-main: #f1f5f9;
        --erp-text-muted: #94a3b8;
        --erp-border: #334155;
        --erp-primary: #818cf8;
        --erp-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
    }

    body { background-color: var(--erp-bg); color: var(--erp-text-main); font-family: 'Inter', system-ui, sans-serif; transition: all 0.3s ease; }
    
    /* Fix Visibility Issues */
    .text-dark, h2, h3, h4, h5, .card-title { color: var(--erp-text-main) !important; }
    .text-muted { color: var(--erp-text-muted) !important; }
    
    .card { background-color: var(--erp-card); border: 1px solid var(--erp-border); box-shadow: var(--erp-shadow); border-radius: 12px; }
    .list-group-item { background-color: var(--erp-card); border-color: var(--erp-border); color: var(--erp-text-main); }
    .list-group-item:hover { background-color: var(--erp-bg); }

    /* Inputs Fix for Dark Mode */
    .form-control, .form-select { background-color: var(--erp-bg); border-color: var(--erp-border); color: var(--erp-text-main); }
    .form-control:focus { background-color: var(--erp-bg); color: var(--erp-text-main); border-color: var(--erp-primary); }
    .modal-content { background-color: var(--erp-card); border: 1px solid var(--erp-border); color: var(--erp-text-main); }

    /* Buttons Contrast */
    .btn-primary { background-color: var(--erp-primary); border-color: var(--erp-primary); }
    .theme-toggle { cursor: pointer; padding: 8px 15px; border-radius: 50px; border: 1px solid var(--erp-border); background: var(--erp-card); color: var(--erp-text-main); }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h4 fw-bold mb-0 text-dark"><i class="bi bi-speedometer2 text-primary"></i> لوحة الإدارة</h2>
            <div class="text-muted small">آخر تصفية: <span class="badge bg-secondary opacity-75"><?= $last_withdraw ?: 'لا يوجد' ?></span></div>
        </div>
        <button onclick="toggleTheme()" class="theme-toggle shadow-sm">
            <i id="theme-icon" class="bi bi-moon-stars"></i> <span class="d-none d-md-inline ms-2">تبديل الوضع</span>
        </button>
    </div>

    <?php
    $dashMsg = $_GET['msg'] ?? '';
    $dashErr = $_GET['error'] ?? '';
    $dashWallet = $_GET['wallet'] ?? '';
    $walletAr = ['cash' => 'الخزنة الرئيسية', 'vodafone' => 'فودافون كاش', 'bank' => 'البنك'];
    if ($dashMsg === 'updated') {
        echo '<div class="alert alert-success border-0 shadow-sm rounded-3 mb-4"><i class="bi bi-check-circle me-2"></i>تم تسجيل العملية في حركة النقدية والتقارير.</div>';
    }
    if ($dashErr === 'insufficient') {
        $wn = $walletAr[$dashWallet] ?? $dashWallet;
        echo '<div class="alert alert-danger border-0 shadow-sm rounded-3 mb-4"><i class="bi bi-exclamation-triangle me-2"></i>الرصيد غير كافٍ للسحب من: ' . htmlspecialchars($wn) . '</div>';
    }
    if ($dashErr === 'invalid' || $dashErr === 'failed') {
        echo '<div class="alert alert-warning border-0 shadow-sm rounded-3 mb-4"><i class="bi bi-info-circle me-2"></i>تعذر تنفيذ العملية. تحقق من البيانات.</div>';
    }
    ?>

    <div class="row g-3 mb-4">
        <?php 
        $stats = [
            ['title' => 'ربح البضاعة', 'value' => $gross_profit, 'bg' => 'primary', 'icon' => 'graph-up-arrow'],
            ['title' => 'إجمالي المصاريف', 'value' => $total_expenses, 'bg' => 'danger', 'icon' => 'cash-stack'],
            ['title' => 'صافي الربح', 'value' => $net_profit, 'bg' => 'success', 'icon' => 'wallet2'],
            ['title' => 'مبيعات اليوم', 'value' => $today_sales, 'bg' => 'info', 'icon' => 'cart-check']
        ];
        foreach($stats as $stat): ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-right: 4px solid var(--bs-<?= $stat['bg'] ?>) !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted small fw-bold mb-2"><?= $stat['title'] ?></h6>
                            <h3 class="mb-0 fw-bold"><?= number_format($stat['value'], 2) ?></h3>
                        </div>
                        <div class="text-<?= $stat['bg'] ?> fs-3 opacity-50"><i class="bi bi-<?= $stat['icon'] ?>"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-md-8">
            <h5 class="fw-bold mb-3"><i class="bi bi-bank me-2"></i> السيولة والمحافظ</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body d-flex justify-content-between align-items-center py-4">
                            <div class="text-center text-md-start flex-grow-1">
                                <p class="text-muted mb-1 small">الخزنة الرئيسية (كاش)</p>
                                <h4 class="text-success fw-bold mb-0"><?= number_format($cash_in_hand, 2) ?> <small class="fs-6">ج.م</small></h4>
                            </div>
                            <button type="button" class="btn btn-sm btn-light border shadow-sm" data-bs-toggle="modal" data-bs-target="#adjModalCash" title="إيداع أو سحب كاش">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </div>
                    </div>
                    <div class="modal fade" id="adjModalCash" tabindex="-1">
                        <div class="modal-dialog modal-sm modal-dialog-centered">
                            <form method="POST" class="modal-content border-0 shadow-lg">
                                <div class="modal-header border-0 pb-0">
                                    <h5 class="modal-title fw-bold">الخزنة الرئيسية (كاش)</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-start">
                                    <input type="hidden" name="wallet_name" value="cash">
                                    <label class="small mb-1">العملية</label>
                                    <select name="adj_type" class="form-select mb-3" required>
                                        <option value="add">إيداع (+)</option>
                                        <option value="sub">سحب (−)</option>
                                    </select>
                                    <label class="small mb-1">المبلغ (ج.م)</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
                                    <p class="text-muted small mt-2 mb-0">يُسجَّل في حركة النقدية ويظهر في التقارير ضمن الفترة.</p>
                                </div>
                                <div class="modal-footer border-0 pt-0">
                                    <button type="submit" name="adjust_balance" value="1" class="btn btn-success w-100">تنفيذ</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php foreach ($wallets as $w):
                    $wn = $w['wallet_name'];
                    if (!in_array($wn, ['vodafone', 'bank'], true)) {
                        continue;
                    }
                    $wlabel = $wn === 'vodafone' ? 'فودافون كاش' : 'حساب البنك';
                ?>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1 small"><?= htmlspecialchars($wlabel) ?></p>
                                <h5 class="mb-0 fw-bold"><?= number_format((float) $w['balance'], 2) ?> <small class="text-muted fs-6">ج.م</small></h5>
                            </div>
                            <button type="button" class="btn btn-sm btn-light border shadow-sm" data-bs-toggle="modal" data-bs-target="#adjModal<?= (int) $w['id'] ?>"><i class="bi bi-pencil-square"></i></button>
                        </div>
                    </div>
                    <div class="modal fade" id="adjModal<?= (int) $w['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-sm modal-dialog-centered">
                            <form method="POST" class="modal-content border-0 shadow-lg">
                                <div class="modal-header border-0 pb-0">
                                    <h5 class="modal-title fw-bold"><?= htmlspecialchars($wlabel) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-start">
                                    <input type="hidden" name="wallet_name" value="<?= htmlspecialchars($wn) ?>">
                                    <label class="small mb-1">العملية</label>
                                    <select name="adj_type" class="form-select mb-3" required>
                                        <option value="add">إيداع (+)</option>
                                        <option value="sub">سحب (−)</option>
                                    </select>
                                    <label class="small mb-1">المبلغ (ج.م)</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
                                    <p class="text-muted small mt-2 mb-0">يُحدَّث رصيد المحفظة ويظهر في التقارير.</p>
                                </div>
                                <div class="modal-footer border-0 pt-0">
                                    <button type="submit" name="adjust_balance" value="1" class="btn btn-primary w-100">تنفيذ</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <div class="card bg-warning bg-opacity-10 border-warning border-opacity-25 shadow-none">
                        <div class="card-body">
                            <p class="text-muted mb-1 small">ديون العملاء</p>
                            <h5 class="mb-0 fw-bold text-warning-emphasis"><?= number_format($total_customer_debts, 2) ?> ج.م</h5>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-danger bg-opacity-10 border-danger border-opacity-25 shadow-none">
                        <div class="card-body">
                            <p class="text-muted mb-1 small">نواقص المخزن</p>
                            <h5 class="mb-0 fw-bold text-danger"><?= $low_stock_count ?> أصناف</h5>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <h5 class="fw-bold mb-3">إجراءات سريعة</h5>
            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="list-group list-group-flush">
                    <a href="pos.php" class="list-group-item list-group-item-action py-3 border-0"><i class="bi bi-cart-plus text-primary me-3"></i> شاشة البيع</a>
                    <a href="expenses.php" class="list-group-item list-group-item-action py-3 border-0"><i class="bi bi-receipt text-danger me-3"></i> تسجيل مصاريف</a>
                    <a href="customers.php" class="list-group-item list-group-item-action py-3 border-0"><i class="bi bi-people text-info me-3"></i> حسابات العملاء</a>
                    <a href="withdrawals.php" class="list-group-item list-group-item-action py-4 bg-primary text-white text-center fw-bold border-0">
                        تصفية الأرباح النهائية <i class="bi bi-arrow-left ms-2"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleTheme() {
        const body = document.documentElement;
        const icon = document.getElementById('theme-icon');
        const currentTheme = body.getAttribute('data-theme');
        
        if (currentTheme === 'dark') {
            body.setAttribute('data-theme', 'light');
            icon.className = 'bi bi-moon-stars';
            localStorage.setItem('theme', 'light');
        } else {
            body.setAttribute('data-theme', 'dark');
            icon.className = 'bi bi-sun';
            localStorage.setItem('theme', 'dark');
        }
    }

    // حفظ الوضع المفضل للمستخدم
    document.addEventListener('DOMContentLoaded', () => {
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        document.getElementById('theme-icon').className = savedTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
    });
</script>

<?php require_once '../includes/footer.php'; ?>