<?php 
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// 1. منطق الحذف (لا تغيير في المنطق)
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("UPDATE categories SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    log_action($pdo, $_SESSION['user_id'], 'delete_category', "Deleted category ID: $id");
    
    $_SESSION['msg'] = "تم حذف الفئة بنجاح";
    $_SESSION['msg_type'] = "success";
    header("Location: categories.php");
    exit();
}

// 2. منطق الإضافة (لا تغيير في المنطق)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    if (verify_csrf($_POST['csrf_token'])) {
        $name = $_POST['name'];
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        log_action($pdo, $_SESSION['user_id'], 'add_category', "Added category: $name");
        
        $_SESSION['msg'] = "تم إضافة الفئة بنجاح";
        $_SESSION['msg_type'] = "success";
        header("Location: categories.php");
        exit();
    }
}

$categories = $pdo->query("SELECT * FROM categories WHERE deleted_at IS NULL ORDER BY id DESC")->fetchAll();
require_once '../includes/header.php'; 
?>

<style>
    /* 🎨 Modern CSS Variables 2026 */
    :root {
        --primary: #4f46e5;
        --primary-hover: #4338ca;
        --bg-body: #f8fafc;
        --bg-card: #ffffff;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --input-focus: rgba(79, 70, 229, 0.1);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --glass: rgba(255, 255, 255, 0.8);
    }

    [data-theme="dark"] {
        --bg-body: #020617;
        --bg-card: #0f172a;
        --text-main: #f1f5f9;
        --text-muted: #94a3b8;
        --border-color: #1e293b;
        --input-focus: rgba(79, 70, 229, 0.2);
        --glass: rgba(15, 23, 42, 0.8);
    }

    body {
        background-color: var(--bg-body);
        color: var(--text-main);
        transition: var(--transition);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
    }

    /* ✨ Animations */
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .animate-page { animation: fadeInUp 0.6s ease-out; }

    /* 🏷️ Card Styling */
    .modern-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        transition: var(--transition);
        backdrop-filter: blur(10px);
    }

    .modern-card:hover {
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05);
        transform: translateY(-2px);
    }

    /* ⌨️ Form Controls */
    .form-control-modern {
        background: var(--bg-body);
        border: 2px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-main);
        padding: 0.75rem 1rem;
        transition: var(--transition);
    }

    .form-control-modern:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 4px var(--input-focus);
        background: var(--bg-card);
        outline: none;
    }

    /* 🔘 Buttons */
    .btn-modern-primary {
        background: var(--primary);
        color: #fff;
        border: none;
        border-radius: 12px;
        padding: 0.75rem 1.5rem;
        font-weight: 600;
        transition: var(--transition);
    }

    .btn-modern-primary:hover {
        background: var(--primary-hover);
        transform: scale(1.02);
        color: #fff;
    }

    .btn-modern-danger {
        border: 1px solid #ef4444;
        color: #ef4444;
        border-radius: 10px;
        transition: var(--transition);
    }

    .btn-modern-danger:hover {
        background: #ef4444;
        color: #fff;
    }

    /* 📊 Table Styling */
    .table-modern {
        width: 100%;
        border-spacing: 0 10px;
        border-collapse: separate;
    }

    .table-modern thead th {
        border: none;
        color: var(--text-muted);
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.8rem;
        letter-spacing: 1px;
        padding: 1rem;
    }

    .table-modern tbody tr {
        background: var(--bg-card);
        transition: var(--transition);
    }

    .table-modern tbody td {
        padding: 1.25rem 1rem;
        border-top: 1px solid var(--border-color);
        border-bottom: 1px solid var(--border-color);
    }

    .table-modern tbody td:first-child { border-right: 1px solid var(--border-color); border-radius: 0 15px 15px 0; }
    .table-modern tbody td:last-child { border-left: 1px solid var(--border-color); border-radius: 15px 0 0 15px; }

    /* 📱 Responsive Adjustments */
    @media (max-width: 768px) {
        .table-modern thead { display: none; }
        .table-modern tbody td { 
            display: block; 
            text-align: left; 
            padding: 0.5rem 1rem;
            border: none !important;
        }
        .table-modern tbody td::before {
            content: attr(data-label);
            float: right;
            font-weight: bold;
            color: var(--primary);
        }
        .table-modern tbody tr {
            display: block;
            margin-bottom: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 15px !important;
        }
    }

    /* 🌓 Dark Mode Toggle */
    .theme-toggle {
        position: fixed;
        bottom: 2rem;
        left: 2rem;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: var(--primary);
        color: white;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        z-index: 1000;
    }
</style>

<div class="container py-5 animate-page" dir="rtl">
    
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h2 class="fw-bold text-main mb-1">إدارة الفئات</h2>
            <p class="text-muted small">تنظيم وتصنيف المنتجات في متجرك بكل سهولة</p>
        </div>
        <div class="text-primary fs-1">
            <i class="bi bi-tags-fill"></i>
        </div>
    </div>

    <div class="modern-card p-4 mb-5">
        <h5 class="mb-4 fw-bold"><i class="bi bi-plus-circle-dotted me-2 text-primary"></i> إضافة صنف جديد</h5>
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="col-md-9">
                <label class="form-label small text-muted">اسم الفئة</label>
                <input type="text" name="name" class="form-control-modern w-100" placeholder="مثلاً: الإلكترونيات، العطور..." required>
            </div>
            <div class="col-md-3">
                <button type="submit" name="add_category" class="btn-modern-primary w-100">
                    <i class="bi bi-check-lg me-1"></i> حفظ الفئة
                </button>
            </div>
        </form>
    </div>

    <div class="modern-card p-0 overflow-hidden">
        <div class="bg-primary bg-opacity-10 p-3 border-bottom border-color">
            <h6 class="mb-0 fw-bold text-primary">الفئات المسجلة حالياً (<?= count($categories) ?>)</h6>
        </div>
        
        <div class="table-responsive p-3">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th width="80px">ID</th>
                        <th>اسم الفئة</th>
                        <th>تاريخ الإضافة</th>
                        <th class="text-center">التحكم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td data-label="ID"><span class="badge bg-light text-dark border">#<?= $cat['id'] ?></span></td>
                        <td data-label="الاسم"><span class="fw-bold fs-5"><?= e($cat['name']) ?></span></td>
                        <td data-label="التاريخ"><span class="text-muted"><i class="bi bi-calendar3 me-1"></i> <?= date('Y/m/d', strtotime($cat['created_at'])) ?></span></td>
                        <td data-label="الإجراء" class="text-center">
                            <a href="?delete=<?= $cat['id'] ?>" 
                               class="btn btn-sm btn-modern-danger px-3 py-2" 
                               onclick="return confirm('⚠️ هل أنت متأكد من نقل هذه الفئة لسلة المحذوفات؟')">
                               <i class="bi bi-trash3-fill me-1"></i> حذف
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($categories)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">لا يوجد فئات مسجلة بعد.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<script>
    // 💾 Theme Logic (Save in LocalStorage)
    function toggleTheme() {
        const body = document.documentElement;
        const icon = document.getElementById('theme-icon');
        const currentTheme = body.getAttribute('data-theme');
        
        if (currentTheme === 'dark') {
            body.removeAttribute('data-theme');
            localStorage.setItem('theme', 'light');
            icon.className = 'bi bi-moon-stars-fill';
        } else {
            body.setAttribute('data-theme', 'dark');
            localStorage.setItem('theme', 'dark');
            icon.className = 'bi bi-sun-fill';
        }
    }

    // Load saved theme on load
    window.onload = () => {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            document.getElementById('theme-icon').className = 'bi bi-sun-fill';
        }
    }
</script>

<?php require_once '../includes/footer.php'; ?>