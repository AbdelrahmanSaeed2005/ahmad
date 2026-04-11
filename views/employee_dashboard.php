<?php 
/* File Path: views/employee_dashboard.php */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php'; 

// حل مشكلة الخطأ: التأكد من وجود اسم المستخدم
$display_name = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'موظف الكاشير';

$view_date = date('Y-m-d');
if (isset($_GET['view']) && $_GET['view'] == 'yesterday') {
    $view_date = date('Y-m-d', strtotime("-1 day"));
    $is_yesterday = true;
} else {
    $is_yesterday = false;
}

$start_of_day = $view_date . " 00:00:00";
$end_of_day   = $view_date . " 23:59:59";

try {
    // ================ عدد الفواتير وإجمالي الآجل من invoices ================
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN payment_method = 'credit' THEN total_amount ELSE 0 END) as credit_total,
            COUNT(*) as total_invoices
        FROM invoices 
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$start_of_day, $end_of_day]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['credit_total'] = (float)($stats['credit_total'] ?? 0);
    $stats['total_invoices'] = (int)($stats['total_invoices'] ?? 0);

    // ================ حركة المحافظ (مثل لوحة الأدمن) ================
    $stmt = $pdo->prepare("
        SELECT 
            payment_method,
            SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS in_total,
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS out_total,
            SUM(CASE WHEN type='income' AND (related_type='customer_collection' OR description LIKE '%تحصيل%') THEN amount ELSE 0 END) AS collection_total,
            SUM(CASE WHEN type='expense' AND related_type='return' THEN amount ELSE 0 END) AS return_total
        FROM cash_transactions 
        WHERE created_at BETWEEN ? AND ?
          AND payment_method IN ('cash','vodafone','bank')
        GROUP BY payment_method
    ");
    $stmt->execute([$start_of_day, $end_of_day]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byMethod = [
        'cash' => ['in' => 0.0, 'out' => 0.0, 'collection' => 0.0, 'return' => 0.0],
        'vodafone' => ['in' => 0.0, 'out' => 0.0, 'collection' => 0.0, 'return' => 0.0],
        'bank' => ['in' => 0.0, 'out' => 0.0, 'collection' => 0.0, 'return' => 0.0],
    ];
    foreach ($rows as $r) {
        $m = $r['payment_method'];
        if (!isset($byMethod[$m])) continue;
        $byMethod[$m]['in'] = (float)($r['in_total'] ?? 0);
        $byMethod[$m]['out'] = (float)($r['out_total'] ?? 0);
        $byMethod[$m]['collection'] = (float)($r['collection_total'] ?? 0);
        $byMethod[$m]['return'] = (float)($r['return_total'] ?? 0);
    }

    $cash_total = $byMethod['cash']['in'] - $byMethod['cash']['out'];
    $vodafone_total = $byMethod['vodafone']['in'] - $byMethod['vodafone']['out'];
    $bank_total = $byMethod['bank']['in'] - $byMethod['bank']['out'];
    $cash_collection = $byMethod['cash']['collection'];
    $vodafone_collection = $byMethod['vodafone']['collection'];
    $bank_collection = $byMethod['bank']['collection'];
    $returns_total = $byMethod['cash']['return'] + $byMethod['vodafone']['return'] + $byMethod['bank']['return'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cash_transactions WHERE created_at BETWEEN ? AND ? AND type='income' AND (related_type='customer_collection' OR description LIKE '%تحصيل%')");
    $stmt->execute([$start_of_day, $end_of_day]);
    $collection_count = (int)($stmt->fetchColumn() ?: 0);

    // ================ إجمالي المديونية المستحقة على العملاء ================
    $stmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) as total_credit_balance FROM customers WHERE deleted_at IS NULL");
    $total_credit_balance = $stmt->fetchColumn() ?: 0;

    // ✅ حساب عدد المرتجعات
    $stmt = $pdo->prepare("SELECT COUNT(id) FROM returns WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$start_of_day, $end_of_day]);
    $returns_count = $stmt->fetchColumn() ?: 0;

    $stats['cash_total'] = $cash_total;
    $stats['vodafone_total'] = $vodafone_total;
    $stats['bank_total'] = $bank_total;
    $stats['grand_total'] = $cash_total + $vodafone_total + $bank_total + $stats['credit_total'];
    $net_cash = $cash_total;

} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

require_once '../includes/header.php'; 
?>

<style>
/* تصميم التقرير الملون للطباعة */
@media print {
    body * { visibility: hidden; }
    #printable-report, #printable-report * { visibility: visible; }
    #printable-report {
        position: absolute;
        left: 0; top: 0; width: 100%;
        border: 2px solid #007bff;
        padding: 20px;
        border-radius: 15px;
    }
    .no-print { display: none !important; }
}

#printable-report { display: none; } /* مخفي في العرض العادي */

.stat-card {
    transition: transform 0.2s;
}
.stat-card:hover {
    transform: translateY(-5px);
}

/* تحسين الألوان للوضع المظلم */
[data-theme="dark"] {
    --bs-light: #1e293b;
    --bs-dark: #f1f5f9;
}

.bg-light {
    background-color: var(--bs-light) !important;
}
</style>

<div class="container py-4 text-end" dir="rtl">
    
    <div id="printable-report" class="text-center">
        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
            <h3 class="text-primary fw-bold">Ark4n | اركان للحلول البرمجيه</h3>
            <div class="text-end small">
                <div>التاريخ: <?= $view_date ?></div>
                <div>وقت الطباعة: <?= date('h:i A') ?></div>
            </div>
        </div>
        <h4 class="mb-4 bg-light py-2">تقرير تقفيل الوردية الختامي</h4>
        
        <!-- ملخص طرق الدفع المختصر -->
        <table class="table table-bordered shadow-sm mb-4">
            <tr class="table-primary">
                <th colspan="2" class="text-center">ملخص الوردية</th>
            </tr>
            <tr>
                <th>نقدي 💵</th>
                <td class="fw-bold"><?= number_format($cash_total, 2) ?> ج.م</td>
            </tr>
            <tr>
                <th>فودافون كاش 📱</th>
                <td class="fw-bold"><?= number_format($vodafone_total, 2) ?> ج.م</td>
            </tr>
            <tr>
                <th>تحويل بنكي 🏦</th>
                <td class="fw-bold"><?= number_format($bank_total, 2) ?> ج.م</td>
            </tr>
            <tr class="table-success">
                <th>الإجمالي الكلي للمبيعات</th>
                <td class="fw-bold fs-5"><?= number_format($cash_total + $vodafone_total + $bank_total, 2) ?> ج.م</td>
            </tr>
        </table>

        <table class="table table-bordered shadow-sm">
            <tr class="table-primary">
                <th>موظف الكاشير</th>
                <td><?= htmlspecialchars($display_name) ?></td>
            </tr>
            <tr>
                <th>صافي النقدية بالدرج</th>
                <td class="fw-bold text-success fs-4"><?= number_format($net_cash, 2) ?> ج.م</td>
            </tr>
            <tr>
                <th>إجمالي الفواتير</th>
                <td><?= $stats['total_invoices'] ?> فاتورة</td>
            </tr>
            <tr>
                <th>عمليات التحصيل</th>
                <td><?= $collection_count ?> عملية</td>
            </tr>
            <tr>
                <th>إجمالي المرتجعات</th>
                <td class="text-danger"><?= $returns_count ?> عملية (قيمة: <?= number_format($returns_total, 2) ?> ج.م)</td>
            </tr>
        </table>
        
        <div class="mt-5 d-flex justify-content-around">
            <p class="small">توقيع الموظف: ....................</p>
            <p class="small">توقيع المدير: ....................</p>
        </div>
    </div>

    <div class="no-print">
        <?php if($is_yesterday): ?>
            <div class="alert alert-warning border-0 shadow-sm d-flex justify-content-between align-items-center rounded-4 mb-4">
                <span><strong>تنبيه:</strong> وردية أمس (تاريخ: <?= $view_date ?>)</span>
                <a href="employee_dashboard.php" class="btn btn-sm btn-dark rounded-pill">العودة للآن</a>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">لوحة الوردية</h2>
                <p class="text-muted small mb-0">الموظف: <?= htmlspecialchars($display_name) ?>  |  <span id="live-clock">--:--:--</span></p>
            </div>
            <?php if(date('H') < 6 && !$is_yesterday): ?>
                <a href="?view=yesterday" class="btn btn-outline-secondary rounded-pill shadow-sm btn-sm">تصفية أمس</a>
            <?php endif; ?>
        </div>

        <!-- بطاقات الإحصائيات الأساسية -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 border-start border-primary border-5 h-100 stat-card">
                    <div class="card-body p-4">
                        <h6 class="text-muted fw-bold small">النقدية الحالية</h6>
                        <h2 class="fw-bold"><?= number_format($net_cash, 2) ?> <small>ج.م</small></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 border-start border-success border-5 h-100 stat-card">
                    <div class="card-body p-4">
                        <h6 class="text-muted fw-bold small">عدد المبيعات</h6>
                        <h2 class="fw-bold"><?= $stats['total_invoices'] ?></h2>
                        <small class="text-muted">فاتورة</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 border-start border-danger border-5 h-100 stat-card">
                    <div class="card-body p-4">
                        <h6 class="text-muted fw-bold small">المرتجعات</h6>
                        <h2 class="fw-bold"><?= $returns_count ?></h2>
                        <small class="text-muted">عملية (قيمة: <?= number_format($returns_total, 2) ?> ج.م)</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- بطاقة المديونية المستحقة
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4 bg-warning bg-opacity-10 stat-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold small text-warning">إجمالي المديونية المستحقة على العملاء</h6>
                                <h2 class="fw-bold text-warning">?= number_format($total_credit_balance, 2) ?> <small>ج.م</small></h2>
                            </div>
                            <i class="bi bi-credit-card fs-1 text-warning opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div> -->

        <!-- تفاصيل طرق الدفع (مع إضافة التحصيلات) -->
        <div class="row g-3 mb-5">
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 bg-light stat-card">
                    <div class="card-body text-center p-3">
                        <h6 class="text-muted small mb-1">نقدي</h6>
                        <h5 class="fw-bold text-primary mb-0"><?= number_format($cash_total, 2) ?> <small>ج.م</small></h5>
                        <small class="text-muted">صافي بعد المرتجعات</small>
                    </div>
                </div>
            </div>
            <!-- <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 bg-light stat-card">
                    <div class="card-body text-center p-3">
                        <h6 class="text-muted small mb-1">آجل</h6>
                        <h5 class="fw-bold text-warning mb-0"><?= number_format($stats['credit_total'], 2) ?> <small>ج.م</small></h5>
                        <small class="text-muted">مبيعات آجلة</small><br>
                        <small class="text-warning">مستحق: <?= number_format($total_credit_balance, 2) ?></small>
                    </div>
                </div>
            </div> -->
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 bg-light stat-card">
                    <div class="card-body text-center p-3">
                        <h6 class="text-muted small mb-1">فودافون</h6>
                        <h5 class="fw-bold text-info mb-0"><?= number_format($vodafone_total, 2) ?> <small>ج.م</small></h5>
                        <small class="text-muted">صافي بعد المرتجعات</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 bg-light stat-card">
                    <div class="card-body text-center p-3">
                        <h6 class="text-muted small mb-1">بنكي</h6>
                        <h5 class="fw-bold text-secondary mb-0"><?= number_format($bank_total, 2) ?> <small>ج.م</small></h5>
                        <small class="text-muted">صافي بعد المرتجعات</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- إجمالي المبيعات -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4 bg-success bg-gradient text-white stat-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold small opacity-75">إجمالي المبيعات (جميع الطرق)</h6>
                                <h2 class="fw-bold mb-0"><?= number_format($cash_total + $vodafone_total + $bank_total, 2) ?> ج.م</h2>
                            </div>
                            <i class="bi bi-graph-up-arrow fs-1 opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-4">
                <a href="pos.php" class="btn btn-primary w-100 py-4 rounded-4 shadow-sm fw-bold stat-card">
                    <i class="bi bi-cart-plus fs-2 mb-2 d-block"></i> فاتورة جديدة
                </a>
            </div>
            <div class="col-6 col-md-4">
                <a href="returns.php" class="btn btn-danger w-100 py-4 rounded-4 shadow-sm fw-bold stat-card">
                    <i class="bi bi-arrow-counterclockwise fs-2 mb-2 d-block"></i> تسجيل مرتجع
                </a>
            </div>
            <div class="col-12 col-md-4">
                <button onclick="printReport()" class="btn btn-dark w-100 py-4 rounded-4 shadow-sm fw-bold stat-card">
                    <i class="bi bi-printer fs-2 mb-2 d-block"></i> طباعة تقرير الوردية
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// دالة الطباعة الاحترافية
function printReport() {
    // جعل التقرير مرئياً للطباعة فقط
    const report = document.getElementById('printable-report');
    report.style.display = 'block';
    window.print();
    report.style.display = 'none';
}

function updateClock() {
    const now = new Date();
    document.getElementById('live-clock').innerText = now.toLocaleTimeString('ar-EG');
    if (now.getHours() === 0 && now.getMinutes() === 0 && now.getSeconds() === 0) {
        window.location.reload();
    }
}
setInterval(updateClock, 1000);
updateClock();
</script>

<?php require_once '../includes/footer.php'; ?>