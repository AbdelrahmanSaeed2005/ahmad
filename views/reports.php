<?php
/* File Path: views/reports.php */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php'; 

if (($_SESSION['role_name'] ?? '') !== 'admin') {
    die("عذراً، لا تمتلك صلاحية الوصول لهذه التقارير.");
}

// 1. تعريف قيم افتراضية
$gross_sales = $total_returns = $net_sales = $total_expenses = $total_cost = $total_purchase_money = $net_balance = $real_profit = 0;
$vodafone_balance = $bank_balance = $cash_in_hand = 0;
$error_msg = null;
$product_profit_rows = [];

// 2. معالجة تواريخ البحث
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$query_start = $start_date . " 00:00:00";
$query_end   = $end_date . " 23:59:59";

try {
    // [1] إجمالي المبيعات قبل المرتجعات
    $stmt = $pdo->prepare("SELECT SUM(total_amount) FROM invoices WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$query_start, $query_end]);
    $gross_sales = $stmt->fetchColumn() ?: 0;
    
    // [2] ✅ إجمالي المرتجعات للفترة
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COALESCE(SUM(return_price), 0) FROM returns WHERE created_at BETWEEN ? AND ?)
            +
            (SELECT COALESCE(SUM(total_amount), 0) FROM customer_returns WHERE created_at BETWEEN ? AND ? AND deleted_at IS NULL)
    ");
    $stmt->execute([$query_start, $query_end, $query_start, $query_end]);
    $total_returns = $stmt->fetchColumn() ?: 0;
    
    // [3] ✅ صافي المبيعات بعد خصم المرتجعات
    $net_sales = $gross_sales - $total_returns;

    // [4] إجمالي المصاريف للفترة
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$query_start, $query_end]);
    $total_expenses = $stmt->fetchColumn() ?: 0;

    // [5] فلوس المشتريات (مدفوعات للموردين) للفترة
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM cash_transactions
        WHERE created_at BETWEEN ? AND ?
          AND related_type = 'supplier_payment'
          AND type = 'expense'
    ");
    $stmt->execute([$query_start, $query_end]);
    $total_purchase_money = $stmt->fetchColumn() ?: 0;

    // [6] تكلفة البضاعة المباعة للفترة (صافي بعد المرتجعات)
    $stmt = $pdo->prepare("
        SELECT SUM(ii.quantity * p.cost_price) AS cogs_sales
        FROM invoice_items ii
        JOIN products p ON ii.product_id = p.id
        JOIN invoices i ON ii.invoice_id = i.id
        WHERE i.created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$query_start, $query_end]);
    $total_cost_sales = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(x.cogs_returns), 0) FROM (
            SELECT (r.quantity * p.cost_price) AS cogs_returns
            FROM returns r
            JOIN products p ON r.product_id = p.id
            WHERE r.created_at BETWEEN ? AND ?
              AND p.deleted_at IS NULL
            UNION ALL
            SELECT (cr.quantity * p.cost_price) AS cogs_returns
            FROM customer_returns cr
            JOIN products p ON cr.product_id = p.id
            WHERE cr.created_at BETWEEN ? AND ?
              AND cr.deleted_at IS NULL
              AND p.deleted_at IS NULL
        ) x
    ");
    $stmt->execute([$query_start, $query_end, $query_start, $query_end]);
    $total_cost_returns = $stmt->fetchColumn() ?: 0;

    $total_cost = $total_cost_sales - $total_cost_returns;

    // [7] الحسابات المالية
    $net_balance = $net_sales - $total_expenses; 
    $real_profit = $net_sales - $total_cost - $total_expenses; 

    // [7] حساب أرصدة المحافظ والخزنة للفترة المحددة
    $stmt = $pdo->prepare("
        SELECT 
            payment_method,
            SUM(CASE WHEN type='income' THEN amount ELSE 0 END) - 
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS balance
        FROM cash_transactions 
        WHERE created_at BETWEEN ? AND ?
        GROUP BY payment_method
    ");
    $stmt->execute([$query_start, $query_end]);
    $balances = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $cash_in_hand     = $balances['cash'] ?? 0;
    $vodafone_balance = $balances['vodafone'] ?? 0;
    $bank_balance     = $balances['bank'] ?? 0;

    // ربح المنتجات (حسب الفواتير) مع خصم المرتجعات داخل نفس الفترة
    $stmt = $pdo->prepare("
        SELECT
            s.product_id,
            s.product_name AS name,
            (s.total_sold_qty - COALESCE(r.ret_qty,0)) AS total_sold_qty,
            (s.revenue - COALESCE(r.ret_revenue,0)) AS revenue,
            (s.cost - COALESCE(r.ret_cost,0)) AS cost,
            (s.profit - COALESCE(r.ret_profit,0)) AS profit
        FROM (
            SELECT
                p.id AS product_id,
                p.name AS product_name,
                SUM(ii.quantity) AS total_sold_qty,
                SUM(ii.quantity * ii.price) AS revenue,
                SUM(ii.quantity * p.cost_price) AS cost,
                SUM(ii.quantity * (ii.price - p.cost_price)) AS profit
            FROM invoice_items ii
            JOIN products p ON ii.product_id = p.id
            JOIN invoices i ON ii.invoice_id = i.id
            WHERE i.created_at BETWEEN ? AND ?
              AND p.deleted_at IS NULL
            GROUP BY p.id, p.name
        ) s
        LEFT JOIN (
            SELECT
                x.product_id,
                SUM(x.ret_qty) AS ret_qty,
                SUM(x.ret_revenue) AS ret_revenue,
                SUM(x.ret_cost) AS ret_cost,
                SUM(x.ret_profit) AS ret_profit
            FROM (
                SELECT
                    p.id AS product_id,
                    SUM(r.quantity) AS ret_qty,
                    SUM(r.return_price) AS ret_revenue,
                    SUM(r.quantity * p.cost_price) AS ret_cost,
                    SUM(r.return_price - (p.cost_price * r.quantity)) AS ret_profit
                FROM returns r
                JOIN products p ON r.product_id = p.id
                WHERE r.created_at BETWEEN ? AND ?
                  AND p.deleted_at IS NULL
                GROUP BY p.id
                UNION ALL
                SELECT
                    p.id AS product_id,
                    SUM(cr.quantity) AS ret_qty,
                    SUM(cr.total_amount) AS ret_revenue,
                    SUM(cr.quantity * p.cost_price) AS ret_cost,
                    SUM(cr.total_amount - (p.cost_price * cr.quantity)) AS ret_profit
                FROM customer_returns cr
                JOIN products p ON cr.product_id = p.id
                WHERE cr.created_at BETWEEN ? AND ?
                  AND cr.deleted_at IS NULL
                  AND p.deleted_at IS NULL
                GROUP BY p.id
            ) x
            GROUP BY x.product_id
        ) r ON r.product_id = s.product_id
        WHERE (s.total_sold_qty - COALESCE(r.ret_qty,0)) <> 0
        ORDER BY profit DESC
    ");
    $stmt->execute([$query_start, $query_end, $query_start, $query_end, $query_start, $query_end]);
    $product_profit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (PDOException $e) {
    $error_msg = "خطأ تقني في الحسابات: " . $e->getMessage();
}

require_once '../includes/header.php'; 
?>

<style>
    :root {
        --primary-color: #4f46e5;
        --primary-light: #e0e7ff;
        --bg-body: #f8fafc;
        --card-bg: #ffffff;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    [data-bs-theme="dark"] {
        --bg-body: #0f172a;
        --card-bg: #1e293b;
        --text-main: #f1f5f9;
        --text-muted: #94a3b8;
        --border-color: #334155;
        --primary-light: rgba(79, 70, 229, 0.1);
    }

    body {
        background-color: var(--bg-body);
        color: var(--text-main);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
        transition: var(--transition);
    }

    .report-container { max-width: 1400px; margin: 0 auto; }

    /* Glass Effect Cards */
    .custom-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 1.25rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: var(--transition);
        overflow: hidden;
    }

    .custom-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }

    /* Floating Theme Switcher */
    #theme-toggle {
        position: fixed;
        bottom: 2rem;
        left: 2rem;
        z-index: 1050;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        border: none;
        background: var(--primary-color);
        color: white;
    }

    /* Metrics Styling */
    .metric-value { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.025em; }
    .metric-label { font-size: 0.875rem; font-weight: 500; opacity: 0.9; }

    /* Animation */
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in { animation: fadeInUp 0.6s ease-out forwards; }

    /* Date Input Styling */
    .form-control-custom {
        background: var(--bg-body);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        padding: 0.75rem 1rem;
        border-radius: 0.75rem;
    }

    /* Mobile Adjustments */
    @media (max-width: 768px) {
        .metric-value { font-size: 1.4rem; }
        .d-flex-mobile { flex-direction: column; gap: 1rem; }
    }
</style>

<div class="report-container container-fluid py-4 text-end" dir="rtl" id="main-content">
    
    <button id="theme-toggle" title="تبديل الوضع">
        <i class="bi bi-moon-stars-fill" id="theme-icon"></i>
    </button>

    <?php if($error_msg): ?>
        <div class="alert alert-warning border-0 shadow-sm mb-4 animate-fade-in">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_msg ?>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-5 d-flex-mobile animate-fade-in">
        <div>
            <h2 class="h2 fw-bold text-main mb-1">التقارير المالية</h2>
            <p class="text-muted small mb-0">نظرة عامة على الأداء المالي والسيولة النقدية</p>
        </div>
        <div class="badge-date py-2 px-4 rounded-pill" style="background: var(--primary-light); color: var(--primary-color); border: 1px solid var(--primary-color);">
            <i class="bi bi-calendar3 me-2"></i>
            من <strong><?= $start_date ?></strong> إلى <strong><?= $end_date ?></strong>
        </div>
    </div>

    <div class="custom-card p-4 mb-5 animate-fade-in">
        <form method="GET" class="row g-4 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-bold text-muted mb-2">تاريخ البداية</label>
                <input type="date" name="start_date" class="form-control form-control-custom" value="<?= $start_date ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-muted mb-2">تاريخ النهاية</label>
                <input type="date" name="end_date" class="form-control form-control-custom" value="<?= $end_date ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100 fw-bold py-3 rounded-3 shadow-sm transition-hover" style="background: var(--primary-color); border: none;">
                    <i class="bi bi-arrow-repeat me-2"></i> تحديث البيانات
                </button>
            </div>
        </form>
    </div>

    <!-- ✅ صف الإحصائيات الأول مع إضافة المرتجعات -->
    <div class="row g-4 mb-5">
        <div class="col-sm-6 col-xl-3 animate-fade-in" style="animation-delay: 0.1s;">
            <div class="custom-card p-4 h-100 bg-primary text-white" style="background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%) !important; border: none;">
                <p class="metric-label opacity-75 mb-2">إجمالي المبيعات (قبل المرتجعات)</p>
                <h3 class="metric-value mb-0"><?= number_format($gross_sales, 2) ?> <small style="font-size: 1rem;">ج.م</small></h3>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3 animate-fade-in" style="animation-delay: 0.15s;">
            <div class="custom-card p-4 h-100 bg-warning text-white" style="background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%) !important; border: none;">
                <p class="metric-label opacity-75 mb-2">إجمالي المرتجعات</p>
                <h3 class="metric-value mb-0"><?= number_format($total_returns, 2) ?> <small style="font-size: 1rem;">ج.م</small></h3>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3 animate-fade-in" style="animation-delay: 0.2s;">
            <div class="custom-card p-4 h-100 bg-info text-white" style="background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%) !important; border: none;">
                <p class="metric-label opacity-75 mb-2">صافي المبيعات (بعد المرتجعات)</p>
                <h3 class="metric-value mb-0"><?= number_format($net_sales, 2) ?> <small style="font-size: 1rem;">ج.م</small></h3>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3 animate-fade-in" style="animation-delay: 0.3s;">
            <div class="custom-card p-4 h-100 bg-danger text-white" style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%) !important; border: none;">
                <p class="metric-label opacity-75 mb-2">إجمالي المصاريف</p>
                <h3 class="metric-value mb-0"><?= number_format($total_expenses, 2) ?> <small style="font-size: 1rem;">ج.م</small></h3>
            </div>
        </div>
    </div>

    <!-- ✅ صف الإحصائيات لفلوس المشتريات -->
    <div class="row g-4 mb-5">
        <div class="col-12 animate-fade-in" style="animation-delay: 0.33s;">
            <div class="custom-card p-4 h-100 bg-secondary text-white" style="background: linear-gradient(135deg, #64748b 0%, #334155 100%) !important; border: none;">
                <p class="metric-label opacity-75 mb-2">فلوس المشتريات (مدفوعات للموردين)</p>
                <h3 class="metric-value mb-0"><?= number_format($total_purchase_money, 2) ?> <small style="font-size: 1rem;">ج.م</small></h3>
            </div>
        </div>
    </div>

    <!-- ✅ صف الإحصائيات الثاني -->
    <div class="row g-4 mb-5">
        <div class="col-sm-6 col-xl-6 animate-fade-in" style="animation-delay: 0.35s;">
            <div class="custom-card p-4 h-100" style="border: 2px solid var(--primary-color);">
                <p class="metric-label text-muted mb-2">صافي الرصيد (السيولة)</p>
                <h3 class="metric-value mb-0 text-primary"><?= number_format($net_balance, 2) ?> <small style="font-size: 1rem;">ج.م</small></h3>
            </div>
        </div>
        <div class="col-sm-6 col-xl-6 animate-fade-in" style="animation-delay: 0.4s;">
            <div class="custom-card p-4 h-100 bg-success text-white" style="background: linear-gradient(135deg, #10b981 0%, #047857 100%) !important; border: none;">
                <p class="metric-label opacity-75 mb-2">صافي الربح الحقيقي 💸</p>
                <h3 class="metric-value mb-0"><?= number_format($real_profit, 2) ?> <small style="font-size: 1rem;">ج.م</small></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7 animate-fade-in" style="animation-delay: 0.5s;">
            <div class="custom-card h-100">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0">تحليل توزيع الميزانية</h5>
                    <i class="bi bi-pie-chart text-muted"></i>
                </div>
                <div class="card-body py-5 d-flex justify-content-center">
                    <div style="width: 100%; max-width: 320px;"><canvas id="modernChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-lg-5 animate-fade-in" style="animation-delay: 0.6s;">
            <h5 class="fw-bold mb-4 px-2">
                <i class="bi bi-wallet2 text-primary me-2"></i> حركة النقدية
            </h5>
            
            <div class="d-flex flex-column gap-3">
                <div class="custom-card p-4 border-start border-success border-5">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small fw-bold mb-1">الخزنة الرئيسية (كاش)</p>
                            <h4 class="mb-0 fw-bold text-success"><?= number_format($cash_in_hand, 2) ?> <small class="fs-6">ج.م</small></h4>
                        </div>
                        <div class="rounded-circle p-3" style="background: rgba(16, 185, 129, 0.1);">
                            <i class="bi bi-cash-stack text-success fs-3"></i>
                        </div>
                    </div>
                </div>

                <div class="custom-card p-4 border-start border-danger border-5">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small fw-bold mb-1">رصيد فودافون كاش</p>
                            <h4 class="mb-0 fw-bold text-danger"><?= number_format($vodafone_balance, 2) ?> <small class="fs-6">ج.م</small></h4>
                        </div>
                        <div class="rounded-circle p-3" style="background: rgba(239, 68, 68, 0.1);">
                            <i class="bi bi-phone-fill text-danger fs-3"></i>
                        </div>
                    </div>
                </div>

                <div class="custom-card p-4 border-start border-primary border-5">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small fw-bold mb-1">رصيد حساب البنك</p>
                            <h4 class="mb-0 fw-bold text-primary"><?= number_format($bank_balance, 2) ?> <small class="fs-6">ج.م</small></h4>
                        </div>
                        <div class="rounded-circle p-3" style="background: rgba(79, 70, 229, 0.1);">
                            <i class="bi bi-bank text-primary fs-3"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="custom-card p-4 mt-5 animate-fade-in">
        <div class="p-2 border-bottom mb-3">
            <h5 class="fw-bold mb-0"><i class="bi bi-box-seam text-primary me-2"></i> ربح المنتجات (حسب الفواتير)</h5>
            <p class="text-muted small mb-0">كمية مباعة، إيراد، تكلفة، وربح للفترة المحددة</p>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle text-end mb-0">
                <thead class="table-light">
                    <tr>
                        <th>المنتج</th>
                        <th>كمية مباعة</th>
                        <th>إيراد</th>
                        <th>تكلفة</th>
                        <th>ربح</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($product_profit_rows)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">لا توجد مبيعات في هذه الفترة</td></tr>
                    <?php else: ?>
                        <?php foreach ($product_profit_rows as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= number_format((float) $row['total_sold_qty'], 2) ?></td>
                            <td><?= number_format((float) $row['revenue'], 2) ?></td>
                            <td><?= number_format((float) $row['cost'], 2) ?></td>
                            <td class="fw-bold text-success"><?= number_format((float) $row['profit'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
/** * UI Script: Theme Toggle & Persistence
 */
const themeToggle = document.getElementById('theme-toggle');
const themeIcon = document.getElementById('theme-icon');
const htmlEl = document.documentElement;

// Initialize Theme
const currentTheme = localStorage.getItem('reports_theme') || 'light';
htmlEl.setAttribute('data-bs-theme', currentTheme);
updateThemeIcon(currentTheme);

themeToggle.addEventListener('click', () => {
    const newTheme = htmlEl.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    htmlEl.setAttribute('data-bs-theme', newTheme);
    localStorage.setItem('reports_theme', newTheme);
    updateThemeIcon(newTheme);
    
    // Smooth Re-render of chart for colors
    location.reload(); 
});

function updateThemeIcon(theme) {
    themeIcon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
}

/** * Financial Chart Logic (Updated with Returns)
 */
const ctx = document.getElementById('modernChart').getContext('2d');
const isDark = htmlEl.getAttribute('data-bs-theme') === 'dark';

new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: ['تكلفة بضاعة', 'مصاريف', 'مرتجعات', 'ربح حقيقي'],
        datasets: [{
            data: [<?= $total_cost ?>, <?= $total_expenses ?>, <?= $total_returns ?>, <?= max(0, $real_profit) ?>],
            backgroundColor: ['#f59e0b', '#ef4444', '#f97316', '#10b981'],
            hoverOffset: 25,
            borderWidth: isDark ? 0 : 4,
            borderColor: '#ffffff'
        }]
    },
    options: {
        cutout: '70%',
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { 
                position: 'bottom', 
                labels: { 
                    padding: 30, 
                    color: isDark ? '#f1f5f9' : '#1e293b',
                    font: { size: 14, weight: '600', family: 'Inter' } 
                } 
            },
            tooltip: {
                backgroundColor: isDark ? '#334155' : '#1e293b',
                padding: 12,
                titleFont: { size: 14 },
                bodyFont: { size: 14 },
                cornerRadius: 8
            }
        }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>