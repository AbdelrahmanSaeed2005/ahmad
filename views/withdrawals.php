<?php
/* File Path: views/withdrawals.php 
   Description: التصفية الشاملة - نسخة محسنة لتباين الألوان في الدارك مود
*/
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

if (($_SESSION['role_name'] ?? '') !== 'admin') {
    die("عذراً، لا تمتلك صلاحية الوصول.");
}

// المنطق البرمجي (لم يتم تعديله)
$last_withdraw = $pdo->query("SELECT MAX(created_at) FROM profit_withdrawals")->fetchColumn();
$filter_date = $last_withdraw ? $last_withdraw : '2000-01-01 00:00:00';

$stmt = $pdo->prepare("SELECT SUM((ii.price - p.cost_price) * ii.quantity) FROM invoice_items ii JOIN products p ON ii.product_id = p.id JOIN invoices i ON ii.invoice_id = i.id WHERE i.created_at > ?");
$stmt->execute([$filter_date]);
$sales_profit = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT SUM((r.return_price - (p.cost_price * r.quantity))) FROM returns r JOIN products p ON r.product_id = p.id WHERE r.created_at > ?");
$stmt->execute([$filter_date]);
$returns_loss = $stmt->fetchColumn() ?: 0;
$gross_profit = $sales_profit - $returns_loss;

$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE created_at > ?");
$stmt->execute([$filter_date]);
$total_expenses = $stmt->fetchColumn() ?: 0;
$net_profit = $gross_profit - $total_expenses;

$cash_in_hand = (float) ($pdo->query("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM cash_transactions WHERE payment_method='cash'")->fetchColumn() ?: 0);
$wallets = $pdo->query("SELECT * FROM wallets")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_POST['process_clearance'])) {
    try {
        $pdo->beginTransaction();
        $uid = (int) $_SESSION['user_id'];
        $withdraw_cash = max(0, (float) ($_POST['withdraw_amount']['cash'] ?? 0));
        if ($withdraw_cash > $cash_in_hand + 0.01) {
            throw new Exception('مبلغ سحب الكاش يتجاوز الرصيد المتاح');
        }
        $wallets_chk = $pdo->query("SELECT * FROM wallets")->fetchAll(PDO::FETCH_ASSOC);
        $wallet_sum = 0.0;
        foreach ($wallets_chk as $w) {
            $nm = $w['wallet_name'];
            $am = max(0, (float) ($_POST['withdraw_amount'][$nm] ?? 0));
            if ($am > (float) $w['balance'] + 0.01) {
                throw new Exception('مبلغ السحب يتجاوز رصيد: ' . $nm);
            }
            $wallet_sum += $am;
        }
        $grand = $withdraw_cash + $wallet_sum;
        if ($grand <= 0) {
            throw new Exception('أدخل مبلغ سحب أكبر من صفر');
        }
        $pdo->prepare("INSERT INTO profit_withdrawals (amount, description, user_id) VALUES (?, ?, ?)")
            ->execute([$grand, 'تصفية أرباح — سحب سيولة', $uid]);
        $pw_id = (int) $pdo->lastInsertId();

        if ($withdraw_cash > 0) {
            recordTransaction($pdo, [
                'direction' => 'out',
                'amount' => $withdraw_cash,
                'payment_method' => 'cash',
                'description' => 'تصفية أرباح — كاش #' . $pw_id,
                'user_id' => $uid,
                'related_type' => 'profit_withdrawal',
                'related_id' => $pw_id,
            ]);
        }
        foreach ($wallets_chk as $w) {
            $nm = $w['wallet_name'];
            $am = max(0, (float) ($_POST['withdraw_amount'][$nm] ?? 0));
            if ($am <= 0) {
                continue;
            }
            recordTransaction($pdo, [
                'direction' => 'out',
                'amount' => $am,
                'payment_method' => $nm,
                'description' => 'تصفية أرباح — ' . $nm . ' #' . $pw_id,
                'user_id' => $uid,
                'related_type' => 'profit_withdrawal',
                'related_id' => $pw_id,
            ]);
            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE wallet_name = ?")->execute([$am, $nm]);
        }
        $pdo->commit();
        header('Location: withdrawals.php?msg=cleared');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: withdrawals.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

require_once '../includes/header.php'; 
?>

<style>
    :root {
        --primary-indigo: #4f46e5;
        --secondary-emerald: #10b981;
        --danger-rose: #f43f5e;
        --bg-main: #f8fafc;
        --card-bg: #ffffff;
        --text-heading: #1e293b; /* اللون الغامق الأساسي */
        --text-body: #475569;
        --border-color: #e2e8f0;
        --stat-box-bg: #f1f5f9;
        --transition: all 0.3s ease;
    }

    /* تعديل الألوان عند تفعيل الدارك مود */
    [data-bs-theme="dark"] {
        --bg-main: #0f172a;
        --card-bg: #1e293b;
        --text-heading: #f1f5f9; /* يصبح النص فاتحاً جداً */
        --text-body: #94a3b8;
        --border-color: #334155;
        --stat-box-bg: #1e293b;
    }

    body { 
        background-color: var(--bg-main); 
        color: var(--text-body);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif; 
        transition: var(--transition);
    }

    .summary-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 1.5rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        transition: var(--transition);
        overflow: hidden;
    }

    .stat-box {
        padding: 1.5rem;
        border-radius: 1rem;
        background-color: var(--stat-box-bg);
        border: 1px solid var(--border-color);
        transition: var(--transition);
    }

    .text-heading { color: var(--text-heading) !important; }
    
    .btn-clearance {
        background: linear-gradient(135deg, var(--danger-rose), #be123c);
        border: none;
        padding: 1.25rem;
        border-radius: 1rem;
        font-weight: bold;
        transition: var(--transition);
    }

    .withdraw-input {
        background-color: var(--card-bg) !important;
        border: 2px solid var(--border-color) !important;
        color: var(--text-heading) !important;
        border-radius: 0.75rem;
    }

    .withdraw-input:focus {
        border-color: var(--primary-indigo) !important;
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
    }

    .table { color: var(--text-body) !important; }
    .table thead th {
        background-color: var(--stat-box-bg);
        color: var(--text-body);
        border-bottom: 1px solid var(--border-color);
        padding: 1.25rem;
    }

    #theme-toggle {
        position: fixed; bottom: 20px; left: 20px; z-index: 9999;
        width: 45px; height: 45px; border-radius: 50%;
        background: var(--primary-indigo); color: white; border: none;
    }

    /* لإصلاح خلفية الهيدر في الجدول */
    .card-header-custom {
        background-color: var(--card-bg);
        border-bottom: 1px solid var(--border-color);
    }
</style>

<div class="container py-5 text-end animate-fade-in" dir="rtl">
    
    <button id="theme-toggle" type="button" onclick="toggleDarkMode()"><i class="bi bi-moon-stars"></i></button>

    <div class="mb-5">
        <h2 class="fw-bold text-heading"><i class="bi bi-shield-check-fill text-primary me-2"></i> تصفية الأرباح النهائية</h2>
        <p class="text-body">مراجعة الأرقام المستحقة وسحب السيولة من النظام</p>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'cleared'): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 mb-4">تم تسجيل التصفية وسحب السيولة بنجاح.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-3 mb-4"><?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <div class="summary-card p-4 mb-5">
        <div class="row align-items-center g-4">
            <div class="col-md-4">
                <div class="stat-box text-center">
                    <span class="text-body small fw-bold d-block mb-1">ربح البضاعة (كاش)</span>
                    <h3 class="fw-bold mb-0" style="color: var(--primary-indigo)"><?= number_format($gross_profit, 2) ?> <small class="fs-6">ج.م</small></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box text-center">
                    <span class="text-body small fw-bold d-block mb-1">إجمالي المصاريف</span>
                    <h3 class="text-danger fw-bold mb-0"><?= number_format($total_expenses, 2) ?> <small class="fs-6">ج.م</small></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-box text-center" style="background: rgba(16, 185, 129, 0.1); border-color: var(--secondary-emerald);">
                    <span class="text-success small fw-bold d-block mb-1">صافي الربح القابل للسحب</span>
                    <h2 class="text-success fw-bold mb-0"><?= number_format($net_profit, 2) ?> <small class="fs-5">ج.م</small></h2>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" class="summary-card p-0 shadow-lg">
        <div class="p-4 card-header-custom d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-heading">توزيع سحب السيولة</h5>
            <span class="badge bg-opacity-10 bg-primary text-primary px-3 py-2 rounded-pill border border-primary border-opacity-25">آخر تصفية: <?= date('Y-m-d', strtotime($filter_date)) ?></span>
        </div>
        
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">المصدر</th>
                        <th>الرصيد المتاح</th>
                        <th>المبلغ المراد سحبه</th>
                        <th class="pe-4 text-start">المتبقي بالمحل</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ps-4 py-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-success bg-opacity-10 p-2 rounded-3 me-3">
                                    <i class="bi bi-cash text-success fs-4"></i>
                                </div>
                                <span class="fw-bold text-heading">الخزنة الرئيسية (كاش)</span>
                            </div>
                        </td>
                        <td><span class="badge bg-secondary bg-opacity-10 text-heading fs-6 px-3"><?= number_format($cash_in_hand, 2) ?></span></td>
                        <td style="width: 220px;">
                            <div class="input-group">
                                <input type="number" step="0.01" name="withdraw_amount[cash]" class="form-control text-center withdraw-input" data-max="<?= $cash_in_hand ?>" value="0">
                                <span class="input-group-text bg-transparent border-0 fw-bold text-body">ج.م</span>
                            </div>
                        </td>
                        <td class="pe-4 text-start">
                            <span class="remaining-balance fw-bold text-success fs-5"><?= number_format($cash_in_hand, 2) ?> ج.م</span>
                        </td>
                    </tr>

                    <?php foreach($wallets as $w): ?>
                    <tr>
                        <td class="ps-4 py-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary bg-opacity-10 p-2 rounded-3 me-3">
                                    <i class="bi bi-wallet2 text-primary fs-4"></i>
                                </div>
                                <span class="fw-bold text-heading"><?= strtoupper($w['wallet_name']) ?></span>
                            </div>
                        </td>
                        <td><span class="badge bg-secondary bg-opacity-10 text-heading fs-6 px-3"><?= number_format($w['balance'], 2) ?></span></td>
                        <td>
                            <div class="input-group">
                                <input type="number" step="0.01" name="withdraw_amount[<?= $w['wallet_name'] ?>]" class="form-control text-center withdraw-input" data-max="<?= $w['balance'] ?>" value="0">
                                <span class="input-group-text bg-transparent border-0 fw-bold text-body">ج.م</span>
                            </div>
                        </td>
                        <td class="pe-4 text-start">
                            <span class="remaining-balance fw-bold text-success fs-5"><?= number_format($w['balance'], 2) ?> ج.م</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="p-4 bg-secondary bg-opacity-10 border-top">
            <button type="submit" name="process_clearance" class="btn btn-clearance text-white w-100 py-3 shadow-lg">
                تأكيد سحب الأرباح وتصفير الدورة الحالية <i class="bi bi-check-all ms-2"></i>
            </button>
        </div>
    </form>
</div>

<script>
    function toggleDarkMode() {
        const theme = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', theme);
        localStorage.setItem('theme', theme);
    }

    document.querySelectorAll('.withdraw-input').forEach(input => {
        input.addEventListener('input', function() {
            const max = parseFloat(this.getAttribute('data-max'));
            const withdraw = parseFloat(this.value) || 0;
            const remaining = max - withdraw;
            const remainingTd = this.closest('tr').querySelector('.remaining-balance');
            remainingTd.innerText = remaining.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ج.م';
            
            if (remaining < 0) {
                remainingTd.classList.replace('text-success', 'text-danger');
            } else {
                remainingTd.classList.replace('text-danger', 'text-success');
            }
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>