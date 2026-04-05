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
    // ================ المبيعات من invoices ================
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END) as cash_total,
            SUM(CASE WHEN payment_method = 'credit' THEN total_amount ELSE 0 END) as credit_total,
            SUM(CASE WHEN payment_method = 'vodafone' THEN total_amount ELSE 0 END) as vodafone_total,
            SUM(CASE WHEN payment_method = 'bank' THEN total_amount ELSE 0 END) as bank_total,
            COUNT(*) as total_invoices,
            SUM(total_amount) as grand_total
        FROM invoices 
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$start_of_day, $end_of_day]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // التأكد من عدم وجود قيم NULL
    foreach ($stats as $key => $value) {
        $stats[$key] = $value ?: 0;
    }

    // ================ التحصيلات من cash_transactions ================
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN payment_method = 'cash' AND type = 'income' AND description LIKE '%تحصيل%' THEN amount ELSE 0 END) as cash_collection,
            SUM(CASE WHEN payment_method = 'vodafone' AND type = 'income' AND description LIKE '%تحصيل%' THEN amount ELSE 0 END) as vodafone_collection,
            SUM(CASE WHEN payment_method = 'bank' AND type = 'income' AND description LIKE '%تحصيل%' THEN amount ELSE 0 END) as bank_collection,
            COUNT(CASE WHEN description LIKE '%تحصيل%' THEN 1 END) as collection_count
        FROM cash_transactions 
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$start_of_day, $end_of_day]);
    $collections = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $cash_collection = $collections['cash_collection'] ?? 0;
    $vodafone_collection = $collections['vodafone_collection'] ?? 0;
    $bank_collection = $collections['bank_collection'] ?? 0;
    $collection_count = $collections['collection_count'] ?? 0;

    // ================ إجمالي المديونية المستحقة على العملاء ================
    $stmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) as total_credit_balance FROM customers WHERE deleted_at IS NULL");
    $total_credit_balance = $stmt->fetchColumn() ?: 0;

    // ✅ حساب المرتجعات
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(return_price), 0) FROM returns WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$start_of_day, $end_of_day]);
    $returns_total = $stmt->fetchColumn() ?: 0;

    // ✅ حساب عدد المرتجعات
    $stmt = $pdo->prepare("SELECT COUNT(id) FROM returns WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$start_of_day, $end_of_day]);
    $returns_count = $stmt->fetchColumn() ?: 0;

    // ✅ صافي النقدية = المبيعات النقدية + التحصيلات النقدية - المرتجعات
    $net_cash = $stats['cash_total'] + $cash_collection - $returns_total;

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
            <h3 class="text-primary fw-bold">إمبراطورية التجارة (اسم المحل)</h3>
            <div class="text-end small">
                <div>التاريخ: <?= $view_date ?></div>
                <div>وقت الطباعة: <?= date('h:i A') ?></div>
            </div>
        </div>
        <h4 class="mb-4 bg-light py-2">تقرير تقفيل الوردية الختامي</h4>
        
        <!-- ملخص طرق الدفع في التقرير -->
        <table class="table table-bordered shadow-sm mb-4">
            <tr class="table-info">
                <th colspan="2" class="text-center">تفاصيل المبيعات والتحصيلات</th>
            </tr>
            <tr>
                <th>نقدي 💵</th>
                <td class="fw-bold"><?= number_format($stats['cash_total'], 2) ?> ج.م</td>
            </tr>
            <tr>
                <th>تحصيل نقدي 💰</th>
                <td class="fw-bold text-success">+ <?= number_format($cash_collection, 2) ?> ج.م</td>
            </tr>
            <tr>
                <th>فودافون كاش 📱</th>
                <td class="fw-bold"><?= number_format($stats['vodafone_total'], 2) ?> ج.م</td>
            </tr>
            <tr>
                <th>تحصيل فودافون 📱</th>
                <td class="fw-bold text-success">+ <?= number_format($vodafone_collection, 2) ?> ج.م</td>
            </tr>
            <tr>
                <th>تحويل بنكي 🏦</th>
                <td class="fw-bold"><?= number_format($stats['bank_total'], 2) ?> ج.م</td>
            </tr>
            <tr>
                <th>تحصيل بنكي 🏦</th>
                <td class="fw-bold text-success">+ <?= number_format($bank_collection, 2) ?> ج.م</td>
            </tr>
            <tr>
                <th>آجل (على الحساب) 📝</th>
                <td class="fw-bold"><?= number_format($stats['credit_total'], 2) ?> ج.م</td>
            </tr>
            <tr class="table-warning">
                <th>إجمالي المديونية المستحقة</th>
                <td class="fw-bold"><?= number_format($total_credit_balance, 2) ?> ج.م</td>
            </tr>
            <tr class="table-success">
                <th>الإجمالي الكلي للمبيعات</th>
                <td class="fw-bold fs-5"><?= number_format($stats['grand_total'], 2) ?> ج.م</td>
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

        <!-- بطاقة المديونية المستحقة -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4 bg-warning bg-opacity-10 stat-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold small text-warning">إجمالي المديونية المستحقة على العملاء</h6>
                                <h2 class="fw-bold text-warning"><?= number_format($total_credit_balance, 2) ?> <small>ج.م</small></h2>
                            </div>
                            <i class="bi bi-credit-card fs-1 text-warning opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- تفاصيل طرق الدفع (مع إضافة التحصيلات) -->
        <div class="row g-3 mb-5">
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 bg-light stat-card">
                    <div class="card-body text-center p-3">
                        <h6 class="text-muted small mb-1">نقدي</h6>
                        <h5 class="fw-bold text-primary mb-0"><?= number_format($stats['cash_total'] + $cash_collection, 2) ?> <small>ج.م</small></h5>
                        <small class="text-muted">مبيعات: <?= number_format($stats['cash_total'], 2) ?></small><br>
                        <small class="text-success">تحصيل: <?= number_format($cash_collection, 2) ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 bg-light stat-card">
                    <div class="card-body text-center p-3">
                        <h6 class="text-muted small mb-1">آجل</h6>
                        <h5 class="fw-bold text-warning mb-0"><?= number_format($stats['credit_total'], 2) ?> <small>ج.م</small></h5>
                        <small class="text-muted">مبيعات آجلة</small><br>
                        <small class="text-warning">مستحق: <?= number_format($total_credit_balance, 2) ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 bg-light stat-card">
                    <div class="card-body text-center p-3">
                        <h6 class="text-muted small mb-1">فودافون</h6>
                        <h5 class="fw-bold text-info mb-0"><?= number_format($stats['vodafone_total'] + $vodafone_collection, 2) ?> <small>ج.م</small></h5>
                        <small class="text-muted">مبيعات: <?= number_format($stats['vodafone_total'], 2) ?></small><br>
                        <small class="text-success">تحصيل: <?= number_format($vodafone_collection, 2) ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 bg-light stat-card">
                    <div class="card-body text-center p-3">
                        <h6 class="text-muted small mb-1">بنكي</h6>
                        <h5 class="fw-bold text-secondary mb-0"><?= number_format($stats['bank_total'] + $bank_collection, 2) ?> <small>ج.م</small></h5>
                        <small class="text-muted">مبيعات: <?= number_format($stats['bank_total'], 2) ?></small><br>
                        <small class="text-success">تحصيل: <?= number_format($bank_collection, 2) ?></small>
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
                                <h2 class="fw-bold mb-0"><?= number_format($stats['grand_total'], 2) ?> ج.م</h2>
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