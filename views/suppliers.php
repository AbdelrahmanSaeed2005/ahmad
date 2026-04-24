<?php 
/* File Path: http://localhost/project/ERP_System_V2/views/suppliers.php */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// --- 1. معالجة العمليات (Add, Update, Delete) - بدون أي تعديل في المنطق ---
if (isset($_POST['save_supplier'])) {
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $company = $_POST['company_name'];
    
    try {
        if (isset($_POST['id']) && !empty($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE suppliers SET name=?, phone=?, company_name=? WHERE id=?");
            $stmt->execute([$name, $phone, $company, $_POST['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO suppliers (name, phone, company_name, balance) VALUES (?, ?, ?, 0)");
            $stmt->execute([$name, $phone, $company]);
        }
        header("Location: suppliers.php?success=1"); exit();
    } catch (PDOException $e) {
        die("خطأ في قاعدة البيانات: تأكد من وجود الأعمدة المطلوبة. <br> التفاصيل: " . $e->getMessage());
    }
}

if (isset($_GET['delete'])) {
    $pdo->prepare("UPDATE suppliers SET deleted_at = NOW() WHERE id = ?")->execute([$_GET['delete']]);
    header("Location: suppliers.php?success=1"); exit();
}

$suppliers = $pdo->query("SELECT * FROM suppliers WHERE deleted_at IS NULL ORDER BY id DESC")->fetchAll();

require_once '../includes/header.php'; 
?>

<style>
    /* 🎨 Modern UI 2026 Variables */
    :root {
        --primary-color: #4f46e5;
        --primary-hover: #4338ca;
        --bg-body: #f8fafc;
        --bg-card: #ffffff;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --accent-success: #10b981;
        --accent-danger: #ef4444;
        --accent-info: #0ea5e9;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.12);
        --shadow-md: 0 10px 15px -3px rgba(0,0,0,0.05);
        --radius-lg: 16px;
        --radius-md: 12px;
    }

    /* 🌙 Dark Mode Variables */
    [data-theme="dark"] {
        --bg-body: #0f172a;
        --bg-card: #1e293b;
        --text-main: #f1f5f9;
        --text-muted: #94a3b8;
        --border-color: #334155;
        --shadow-md: 0 20px 25px -5px rgba(0, 0, 0, 0.3);
    }

    body { 
        background-color: var(--bg-body); 
        color: var(--text-main);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif; 
        transition: all 0.3s ease;
        overflow-x: hidden;
    }

    /* ✨ Animations */
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .animate-in { animation: fadeInUp 0.5s ease forwards; }

    /* 🌓 Theme Toggle Button */
    .theme-switch {
        position: fixed;
        bottom: 30px;
        left: 30px;
        z-index: 9999;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: var(--primary-color);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(79, 70, 229, 0.4);
        border: none;
        transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .theme-switch:hover { transform: scale(1.1) rotate(15deg); }

    /* 📊 Financial Cards */
    .stat-card {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-color);
        transition: all 0.3s ease;
        overflow: hidden;
        position: relative;
    }
    .stat-card.gradient-bg {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        color: white;
        border: none;
    }
    .stat-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-md); }

    /* 📋 Modern Table Styles */
    .supplier-table-card {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-md);
    }
    .table { color: var(--text-main); }
    .table thead th {
        background-color: var(--bg-body);
        color: var(--text-muted);
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
    }
    .table tbody td { padding: 1.2rem; border-bottom: 1px solid var(--border-color); }
    
    .supplier-avatar {
        width: 42px; height: 42px;
        background: linear-gradient(135deg, var(--primary-color), #818cf8);
        color: white;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-weight: bold; margin-left: 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    /* 🎨 UI & Controls */
    .badge-debt {
        background-color: rgba(239, 68, 68, 0.1);
        color: var(--accent-danger);
        font-weight: 700;
        border-radius: 8px;
        padding: 6px 12px;
    }
    [data-theme="dark"] .badge-debt { background-color: rgba(239, 68, 68, 0.2); }

    .btn-action {
        width: 38px; height: 38px;
        display: inline-flex; align-items: center; justify-content: center;
        border-radius: 10px; margin: 0 4px;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        border: none;
        text-decoration: none;
    }
    .btn-action:hover { transform: scale(1.1); filter: brightness(1.1); }
    
    .search-control {
        background-color: var(--bg-body);
        border-radius: var(--radius-md);
        padding: 0.7rem 1.2rem;
        border: 1px solid var(--border-color);
        color: var(--text-main);
    }
    .search-control:focus {
        background-color: var(--bg-card);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        color: var(--text-main);
    }

    /* 📱 Responsive Adjustments */
    @media (max-width: 768px) {
        .search-control.w-25 { width: 100% !important; margin-top: 15px; }
        .stat-card h2 { font-size: 1.5rem; }
    }

    .modal-content {
        background-color: var(--bg-card);
        color: var(--text-main);
    }
</style>

<!-- <button class="theme-switch" id="themeToggle" title="تبديل الوضع">
    <i class="bi bi-sun-fill" id="themeIcon"></i>
</button> -->

<div class="container-fluid py-4" dir="rtl">
    <div class="row align-items-center mb-5 g-3 animate-in">
        <div class="col-md-6">
            <h2 class="fw-extrabold mb-1" style="letter-spacing: -1px;">نظام مديونيات الموردين</h2>
            <p class="text-muted mb-0">إدارة ذكية للموردين، الأرصدة، والشركات لعام 2026</p>
        </div>
        <div class="col-md-6 text-md-start text-center">
            <button class="btn btn-primary px-4 py-3 fw-bold shadow-lg border-0" style="border-radius: var(--radius-md);" 
                    data-bs-toggle="modal" data-bs-target="#supplierModal" onclick="resetModal()">
                <i class="bi bi-plus-circle-fill me-2"></i> إضافة مورد جديد
            </button>
        </div>
    </div>

    <div class="row mb-5 g-4 animate-in" style="animation-delay: 0.1s;">
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card gradient-bg shadow-lg">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="opacity-75 small fw-bold">إجمالي المديونية الحالية</div>
                        <div class="bg-white bg-opacity-20 p-2 rounded-circle">
                            <i class="bi bi-currency-exchange fs-5"></i>
                        </div>
                    </div>
                    <?php 
                        try {
                            $total_debts = $pdo->query("SELECT SUM(balance) FROM suppliers WHERE deleted_at IS NULL")->fetchColumn() ?: 0;
                        } catch(Exception $e) { $total_debts = 0; }
                    ?>
                    <h2 class="fw-bold mb-0" id="totalDebtsDisplay"><?= number_format($total_debts, 2) ?> <span class="fs-6 opacity-75">ج.م</span></h2>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-4 me-3">
                        <i class="bi bi-people-fill text-primary fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-bold">عدد الموردين النشطين</div>
                        <h4 class="fw-bold mb-0 mt-1"><?= count($suppliers) ?> مورد</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card supplier-table-card animate-in" style="animation-delay: 0.2s;">
        <div class="card-header bg-transparent py-4 border-0 d-flex flex-column flex-md-row justify-content-between align-items-md-center px-4">
            <h5 class="mb-0 fw-bold d-flex align-items-center">
                <span class="bg-primary p-1 rounded-2 me-2" style="width: 8px; height: 24px; display: inline-block;"></span>
                قائمة الموردين المسجلين
            </h5>
            <div class="position-relative w-25 search-container">
                <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                <input type="text" id="tableSearch" class="form-control search-control ps-5" placeholder="بحث باسم المورد أو الشركة...">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="suppTable">
                    <thead>
                        <tr>
                            <th class="text-end">المورد / المندوب</th>
                            <th>الشركة / النشاط</th>
                            <th>رقم التواصل</th>
                            <th class="text-center">الرصيد المستحق</th>
                            <th class="text-center">خيارات الإدارة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($suppliers as $s): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="supplier-avatar">
                                        <?= mb_substr($s['name'], 0, 1, 'utf-8') ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($s['name']) ?></div>
                                        <span class="text-muted" style="font-size: 0.75rem;">رقم التعريف: #<?= $s['id'] ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="px-3 py-1 bg-light rounded-pill small fw-bold text-primary">
                                    <i class="bi bi-building me-1"></i> <?= htmlspecialchars($s['company_name'] ?? 'نشاط عام') ?>
                                </span>
                            </td>
                            <td>
                                <a href="tel:<?= $s['phone'] ?>" class="text-decoration-none text-muted small hover-primary">
                                    <i class="bi bi-telephone-fill me-1 text-success"></i> <?= htmlspecialchars($s['phone'] ?? '---') ?>
                                </a>
                            </td>
                            <td class="text-center">
                                <span class="badge-debt fs-6">
                                    <?= number_format($s['balance'] ?? 0, 2) ?> <small>ج.م</small>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center">
                                    <a href="supplier_report.php?id=<?= $s['id'] ?>" class="btn-action shadow-sm" style="background: rgba(14, 165, 233, 0.1); color: var(--accent-info);" title="كشف حساب">
                                        <i class="bi bi-file-earmark-bar-graph-fill"></i>
                                    </a>
                                    <button class="btn-action shadow-sm" style="background: rgba(79, 70, 229, 0.1); color: var(--primary-color);" onclick='editSupplier(<?= json_encode($s) ?>)' title="تعديل">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <a href="suppliers.php?delete=<?= $s['id'] ?>" class="btn-action shadow-sm" style="background: rgba(239, 68, 68, 0.1); color: var(--accent-danger);" onclick="return confirm('هل أنت متأكد من حذف هذا المورد؟ سيتم نقله للأرشيف.')" title="حذف">
                                        <i class="bi bi-trash3-fill"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($suppliers)): ?>
                            <tr>
                                <td colspan="5" class="py-5 text-center">
                                    <img src="https://cdn-icons-png.flaticon.com/512/7486/7486744.png" width="80" class="opacity-25 mb-3">
                                    <p class="text-muted">لا يوجد موردين مسجلين حالياً</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="supplierModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 24px;">
            <div class="modal-header border-0 p-4">
                <h4 class="fw-extrabold mb-0" id="modalTitle">إضافة مورد جديد</h4>
                <button type="button" class="btn-close ms-0 bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-0">
                <input type="hidden" name="id" id="supp_id">
                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted px-1">الاسم الكامل للمورد</label>
                    <input type="text" name="name" id="supp_name" class="form-control search-control py-3" required placeholder="مثال: المهندس أحمد محمد">
                </div>
                <div class="row g-3 mb-2">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted px-1">اسم الشركة</label>
                        <input type="text" name="company_name" id="supp_company" class="form-control search-control py-3" placeholder="شركة التوريدات">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted px-1">رقم الهاتف</label>
                        <input type="text" name="phone" id="supp_phone" class="form-control search-control py-3" placeholder="01XXXXXXXXX">
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button type="button" class="btn btn-light px-4 py-2 fw-bold text-muted" data-bs-dismiss="modal" style="border-radius: 12px;">إغلاق</button>
                <button type="submit" name="save_supplier" class="btn btn-primary px-5 py-2 fw-bold shadow-lg" style="border-radius: 12px; background: var(--primary-color);">حفظ المورد</button>
            </div>
        </form>
    </div>
</div>

<script>
// --- التحكم في الوضع الليلي ---
const themeToggle = document.getElementById('themeToggle');
const themeIcon = document.getElementById('themeIcon');
const currentTheme = localStorage.getItem('theme') || 'light';

if (currentTheme === 'dark') {
    document.body.setAttribute('data-theme', 'dark');
    themeIcon.classList.replace('bi-sun-fill', 'bi-moon-stars-fill');
}

themeToggle.addEventListener('click', () => {
    let theme = 'light';
    if (document.body.getAttribute('data-theme') !== 'dark') {
        document.body.setAttribute('data-theme', 'dark');
        themeIcon.classList.replace('bi-sun-fill', 'bi-moon-stars-fill');
        theme = 'dark';
    } else {
        document.body.removeAttribute('data-theme');
        themeIcon.classList.replace('bi-moon-stars-fill', 'bi-sun-fill');
    }
    localStorage.setItem('theme', theme);
});

// --- وظائف المودال (بدون تعديل الوظائف) ---
function resetModal() {
    document.getElementById('modalTitle').innerText = 'إضافة مورد جديد';
    document.getElementById('supp_id').value = '';
    document.getElementById('supp_name').value = '';
    document.getElementById('supp_company').value = '';
    document.getElementById('supp_phone').value = '';
}

function editSupplier(s) {
    document.getElementById('modalTitle').innerText = 'تعديل بيانات: ' + s.name;
    document.getElementById('supp_id').value = s.id;
    document.getElementById('supp_name').value = s.name;
    document.getElementById('supp_company').value = s.company_name;
    document.getElementById('supp_phone').value = s.phone;
    new bootstrap.Modal(document.getElementById('supplierModal')).show();
}

// --- بحث سريع (UX) ---
document.getElementById('tableSearch').addEventListener('keyup', function() {
    let value = this.value.toLowerCase();
    let rows = document.querySelectorAll("#suppTable tbody tr");
    rows.forEach(row => {
        let text = row.innerText.toLowerCase();
        row.style.display = (text.indexOf(value) > -1) ? "" : "none";
        if(text.indexOf(value) > -1) {
            row.classList.add('animate-in');
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>