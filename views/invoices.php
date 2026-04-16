<?php 
/* File Path: http://localhost/project/ERP_System_V2/views/invoices.php */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// --- 1. إعدادات الترقيم الصفحي (بدون تغيير المنطق) ---
$limit = 10; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$searchParam = "%$search%";

// ✅ تعديل استعلام COUNT ليشمل العملاء المؤقتين
$countQuery = "SELECT COUNT(*) FROM invoices i 
               LEFT JOIN customers c ON i.customer_id = c.id 
               WHERE i.id LIKE ? OR c.name LIKE ? OR i.walkin_customer_name LIKE ?";
$stmtCount = $pdo->prepare($countQuery);
$stmtCount->execute([$searchParam, $searchParam, $searchParam]);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// ✅ تعديل استعلام SELECT ليشمل العملاء المؤقتين
$query = "SELECT i.*, u.username, 
          CASE 
              WHEN i.customer_id IS NOT NULL THEN c.name 
              ELSE i.walkin_customer_name 
          END as customer_name,
          CASE
              WHEN i.customer_id IS NOT NULL THEN COALESCE(c.phone_number, c.phone)
              ELSE i.walkin_customer_phone
          END as customer_phone
          FROM invoices i 
          LEFT JOIN users u ON i.user_id = u.id 
          LEFT JOIN customers c ON i.customer_id = c.id 
          WHERE i.id LIKE ? OR c.name LIKE ? OR i.walkin_customer_name LIKE ? 
          ORDER BY i.created_at DESC 
          LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute([$searchParam, $searchParam, $searchParam]);
$invoices = $stmt->fetchAll();

// --- 3. منطق الحذف (بدون تغيير) ---
if (isset($_GET['delete_id'])) {
    $inv_id = $_GET['delete_id'];
    try {
        $pdo->beginTransaction();
        $items = $pdo->prepare("SELECT product_id, quantity FROM invoice_items WHERE invoice_id = ?");
        $items->execute([$inv_id]);
        while ($item = $items->fetch()) {
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?")
                ->execute([$item['quantity'], $item['product_id']]);
        }
        $pdo->prepare("DELETE FROM cash_transactions WHERE related_type = 'sale' AND related_id = ?")->execute([$inv_id]);
        $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$inv_id]);
        $pdo->commit();
        header("Location: invoices.php?msg=deleted"); exit();
    } catch (Exception $e) { $pdo->rollBack(); die("خطأ: " . $e->getMessage()); }
}

require_once '../includes/header.php'; 
?>

<style>
    /* 🎨 Modern UI 2026 Variables */
    :root {
        --primary-indigo: #6366f1;
        --primary-hover: #4f46e5;
        --whatsapp: #25D366;
        --whatsapp-dark: #128C7E;
        --bg-glass: rgba(255, 255, 255, 0.85);
        --bg-body: #f8fafc;
        --card-bg: #ffffff;
        --text-main: #1e293b;
        --border-subtle: #e2e8f0;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    [data-theme="dark"] {
        --bg-body: #020617;
        --card-bg: #0f172a;
        --bg-glass: rgba(15, 23, 42, 0.85);
        --text-main: #f1f5f9;
        --border-subtle: #1e293b;
    }

    body {
        background: var(--bg-body);
        color: var(--text-main);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
        transition: var(--transition);
        overflow-x: hidden;
    }

    /* ✨ Glassmorphism Header */
    .page-header {
        background: var(--bg-glass);
        backdrop-filter: blur(12px);
        border-bottom: 1px solid var(--border-subtle);
        position: sticky;
        top: 0;
        z-index: 100;
        padding: 1.2rem 0;
        margin-bottom: 2rem;
    }

    /* 🏷️ Card & Table SaaS Style */
    .modern-card {
        background: var(--card-bg);
        border: 1px solid var(--border-subtle);
        border-radius: 20px;
        box-shadow: 0 4px 20px -5px rgba(0,0,0,0.05);
        overflow: hidden;
        animation: fadeInSlide 0.6s ease-out;
    }

    .table-hover tbody tr:hover {
        background: rgba(99, 102, 241, 0.03);
        transform: scale(1.002);
    }

    .table th {
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.8rem;
        letter-spacing: 0.5px;
        color: #64748b;
        background: var(--bg-body);
        padding: 1rem;
    }

    /* 🔍 Search Input */
    .search-group {
        position: relative;
        max-width: 450px;
    }
    .search-input {
        border-radius: 12px;
        border: 2px solid var(--border-subtle);
        padding: 0.7rem 1rem 0.7rem 3rem;
        background: var(--card-bg);
        color: var(--text-main);
        transition: var(--transition);
    }
    .search-input:focus {
        border-color: var(--primary-indigo);
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        outline: none;
    }

    /* 🔢 Modern Pagination */
    .pagination .page-link {
        border: none;
        margin: 0 3px;
        border-radius: 10px;
        color: var(--text-main);
        background: var(--card-bg);
        font-weight: 500;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .pagination .page-item.active .page-link {
        background: var(--primary-indigo);
        color: white;
    }

    /* 🌓 Dark Mode Toggle */
    .theme-switch {
        position: fixed;
        bottom: 2rem;
        left: 2rem;
        width: 50px;
        height: 50px;
        background: var(--primary-indigo);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2);
        z-index: 1000;
        border: none;
    }

    /* ✅ تصميم خاص لشارات نوع العميل */
    .customer-badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        font-weight: 600;
        margin-right: 0.5rem;
    }
    
    .customer-badge.registered {
        background: rgba(99, 102, 241, 0.1);
        color: var(--primary-indigo);
    }
    
    .customer-badge.walkin {
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
    }

    .customer-phone {
        font-size: 0.75rem;
        color: var(--text-muted);
        display: block;
    }

    .btn-invoice-card {
        background: linear-gradient(135deg, #0f172a, #1d4ed8);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        transition: all 0.2s ease;
    }

    .btn-invoice-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 18px rgba(29, 78, 216, 0.3);
    }

    /* 📱 Mobile UI Fixes */
    @media (max-width: 576px) {
        .page-header h2 { font-size: 1.2rem; }
        .search-group { width: 100%; max-width: 100%; }
        .table-responsive { border: 0; }
        .badge { font-size: 0.7rem; }
        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.3rem;
        }
    }

    @keyframes fadeInSlide {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<div class="page-header shadow-sm">
    <div class="container-fluid d-flex flex-wrap justify-content-between align-items-center gap-3">
        <h2 class="h4 mb-0 fw-bold d-flex align-items-center">
            <i class="bi bi-stack text-primary me-2"></i> أرشيف الفواتير
        </h2>
        <form class="search-group w-100" method="GET">
            <input type="text" name="search" class="search-input w-100" placeholder="ابحث برقم الفاتورة أو اسم العميل..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn p-0 position-absolute top-50 translate-middle-y me-3 end-0" style="left: 15px;">
                <i class="bi bi-search text-muted"></i>
            </button>
        </form>
    </div>
</div>

<div class="container-fluid py-2 text-end" dir="rtl">
    <div class="modern-card animate-in">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>التاريخ والوقت</th>
                        <th>العميل</th>
                        <th>رقم الهاتف</th>
                        <th>الإجمالي</th>
                        <th>طريقة الدفع</th>
                        <th>المستخدم</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($invoices) > 0): ?>
                        <?php foreach($invoices as $inv): ?>
                        <tr>
                            <td class="fw-bold text-primary">#<?= $inv['id'] ?></td>
                            <td>
                                <div class="small fw-semibold"><?= date('Y/m/d', strtotime($inv['created_at'])) ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;"><?= date('h:i A', strtotime($inv['created_at'])) ?></div>
                            </td>
                            <td>
                                <span class="d-block fw-bold">
                                    <?= htmlspecialchars($inv['customer_name'] ?? 'عميل نقدي') ?>
                                </span>
                                <?php if ($inv['customer_id']): ?>
                                    <span class="customer-badge registered">
                                        <i class="bi bi-person-badge me-1"></i>مسجل
                                    </span>
                                <?php else: ?>
                                    <span class="customer-badge walkin">
                                        <i class="bi bi-person-plus me-1"></i>مؤقت
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($inv['customer_phone'])): ?>
                                    <span class="customer-phone">
                                        <i class="bi bi-telephone me-1"></i>
                                        <?= htmlspecialchars($inv['customer_phone']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">--</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold fs-6"><?= number_format($inv['total_amount'], 2) ?> <small>ج.م</small></td>
                            <td>
                                <?php 
                                $pm = $inv['payment_method'];
                                if ($pm === 'cash') {
                                    $pm_class = 'bg-success-subtle text-success';
                                    $pm_icon = 'bi-cash-coin';
                                    $pm_text = 'نقدي';
                                } elseif ($pm === 'credit') {
                                    $pm_class = 'bg-warning-subtle text-warning';
                                    $pm_icon = 'bi-journal-text';
                                    $pm_text = 'آجل';
                                } elseif ($pm === 'vodafone') {
                                    $pm_class = 'bg-primary-subtle text-primary';
                                    $pm_icon = 'bi-phone';
                                    $pm_text = 'فودافون';
                                } else {
                                    $pm_class = 'bg-primary-subtle text-primary';
                                    $pm_icon = 'bi-bank';
                                    $pm_text = 'بنكي';
                                }
                                ?>
                                <span class="badge rounded-pill <?= $pm_class ?> px-3 border border-opacity-10">
                                    <i class="bi <?= $pm_icon ?> me-1"></i> <?= $pm_text ?>
                                </span>
                            </td>
                            <td class="small text-muted">
                                <i class="bi bi-person-circle me-1"></i> <?= $inv['username'] ?>
                            </td>
                            <td>
                                <div class="btn-group gap-1" style="display: flex; flex-wrap: wrap; justify-content: center;">
                                    <button class="btn btn-sm btn-outline-primary rounded-8 shadow-sm" onclick="viewDetails(<?= $inv['id'] ?>)" title="عرض التفاصيل">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    
                                    <a href="print_invoice.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark rounded-8 shadow-sm" title="طباعة">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    
                                    <a href="print_invoice.php?id=<?= $inv['id'] ?>" target="_blank"
                                       class="btn btn-sm btn-invoice-card rounded-8 shadow-sm" title="كارت الفاتورة">
                                        <i class="bi bi-postcard"></i>
                                        <span class="d-none d-md-inline">كارت</span>
                                    </a>
                                    
                                    <a href="?delete_id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-danger rounded-8 shadow-sm" 
                                       onclick="return confirm('⚠️ هل تريد حذف هذه الفاتورة نهائياً؟ سيتم إعادة الكميات للمخزن.')" 
                                       title="حذف">
                                        <i class="bi bi-trash3"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="py-5 text-muted">لا توجد فواتير مطابقة لعملية البحث..</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="mt-5">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= $search ?>"><i class="bi bi-chevron-right"></i></a>
            </li>

            <?php 
            $range = 2; 
            for ($i = 1; $i <= $totalPages; $i++): 
                if ($i == 1 || $i == $totalPages || ($i >= $page - $range && $i <= $page + $range)): ?>
                    <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= $search ?>"><?= $i ?></a>
                    </li>
                <?php elseif ($i == $page - $range - 1 || $i == $page + $range + 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endfor; ?>

            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= $search ?>"><i class="bi bi-chevron-left"></i></a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>



<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-info-circle me-2"></i> تفاصيل الفاتورة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="detailsContent" style="min-height: 200px; background: var(--bg-body);">
                <div class="text-center py-5"><div class="spinner-grow text-primary"></div></div>
            </div>
        </div>
    </div>
</div>

<script>
// --- JavaScript Logic ---
function toggleTheme() {
    const body = document.documentElement;
    const icon = document.getElementById('themeIcon');
    if (body.getAttribute('data-theme') === 'dark') {
        body.removeAttribute('data-theme');
        localStorage.setItem('theme', 'light');
        icon.className = 'bi bi-moon-stars-fill';
    } else {
        body.setAttribute('data-theme', 'dark');
        localStorage.setItem('theme', 'dark');
        icon.className = 'bi bi-sun-fill';
    }
}

// Load Preference
window.onload = () => {
    if (localStorage.getItem('theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.getElementById('themeIcon').className = 'bi bi-sun-fill';
    }
};

function viewDetails(invoiceId) {
    var modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();
    document.getElementById('detailsContent').innerHTML = '<div class="text-center py-5"><div class="spinner-grow text-primary"></div></div>';
    fetch('get_invoice_details.php?id=' + invoiceId)
        .then(response => response.text())
        .then(html => { document.getElementById('detailsContent').innerHTML = html; })
        .catch(error => {
            document.getElementById('detailsContent').innerHTML = '<div class="alert alert-danger m-4">حدث خطأ في تحميل التفاصيل</div>';
        });
}

// ✅ دالة معاينة PDF
function previewInvoice(invoiceId) {
    window.open(`print_invoice.php?id=${invoiceId}`, '_blank');
}

// Keyboard shortcut (Ctrl + W لفتح WhatsApp)
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'w') {
        e.preventDefault();
        // يمكن إضافة منطق هنا
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>