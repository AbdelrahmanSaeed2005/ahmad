<?php 
/* File Path: views/users.php */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php'; 

$current_role = $_SESSION['role_name'] ?? null;
if ($current_role !== 'admin') { die("عذراً، لا تمتلك صلاحية الوصول."); }

$error = null;
$success = null;

// --- [1. Create] إضافة مستخدم جديد ---
if (isset($_POST['add_user'])) {
    $user = trim($_POST['username']);
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $full_name = trim($_POST['full_name']);
    $role_id = ($_POST['role'] == 'admin') ? 1 : 2; 

    try {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$user]);
        if ($check->fetch()) {
            $error = "اسم المستخدم ' $user ' مستخدم بالفعل لموظف آخر.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role_id, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$user, $pass, $full_name, $role_id]);
            header("Location: users.php?msg=added"); 
            exit();
        }
    } catch (PDOException $e) { 
        $error = "خطأ في النظام: " . $e->getMessage(); 
    }
}

// --- [2. Update] تعديل بيانات مستخدم ---
if (isset($_POST['edit_user'])) {
    $id = (int)$_POST['user_id'];
    $full_name = trim($_POST['full_name']);
    $role_id = ($_POST['role'] == 'admin') ? 1 : 2;
    
    try {
        if (!empty($_POST['password'])) {
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, role_id = ?, password = ? WHERE id = ?");
            $stmt->execute([$full_name, $role_id, $pass, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, role_id = ? WHERE id = ?");
            $stmt->execute([$full_name, $role_id, $id]);
        }
        header("Location: users.php?msg=updated"); exit();
    } catch (PDOException $e) {
        $error = "خطأ في التحديث: " . $e->getMessage();
    }
}

// --- [3. Toggle Status] تعطيل أو تفعيل الموظف ---
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $new_status = (int)$_GET['status'];
    if ($id !== 1) { 
        $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$new_status, $id]);
        $msg = $new_status ? "activated" : "deactivated";
        header("Location: users.php?msg=$msg"); exit();
    }
}

// --- [4. Delete] محاولة حذف مستخدم نهائياً ---
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id !== 1) { 
        try {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            header("Location: users.php?msg=deleted"); exit();
        } catch (PDOException $e) {
            // التحقق من كود الخطأ الخاص بالقيود (Foreign Key Constraint)
            if ($e->getCode() == "23000") {
                $error = "عذراً، هذا الموظف له سجلات عمل وحركات مالية سابقة، لا يمكن حذفه نهائياً. يمكنك 'تعطيل' حسابه بدلاً من الحذف.";
            } else {
                $error = "فشل الحذف: " . $e->getMessage();
            }
        }
    }
}

if(isset($_GET['msg'])) {
    if($_GET['msg'] == 'added') $success = "تم إضافة الموظف بنجاح.";
    if($_GET['msg'] == 'updated') $success = "تم تحديث البيانات بنجاح.";
    if($_GET['msg'] == 'deleted') $success = "تم حذف الموظف نهائياً من النظام.";
    if($_GET['msg'] == 'deactivated') $success = "تم تعطيل حساب الموظف بنجاح.";
    if($_GET['msg'] == 'activated') $success = "تم إعادة تنشيط حساب الموظف.";
}

$users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
require_once '../includes/header.php'; 
?>

<style>
    /* Modern UI 2026 Design System */
    :root {
        --primary: #4f46e5;
        --primary-hover: #4338ca;
        --bg-main: #f8fafc;
        --card-bg: #ffffff;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --border: #e2e8f0;
        --input-bg: #ffffff;
        --shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    [data-bs-theme="dark"] {
        --bg-main: #020617;
        --card-bg: #0f172a;
        --text-main: #f8fafc;
        --text-muted: #94a3b8;
        --border: #1e293b;
        --input-bg: #1e293b;
        --shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.3);
    }

    body {
        background-color: var(--bg-main);
        color: var(--text-main);
        font-family: 'Noto Sans Arabic', sans-serif;
        transition: background-color 0.5s ease;
    }

    .top-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2.5rem;
    }

    .theme-switch {
        position: relative;
        width: 60px;
        height: 32px;
        background: var(--border);
        border-radius: 50px;
        cursor: pointer;
        display: flex;
        align-items: center;
        padding: 0 5px;
        transition: var(--transition);
    }

    .theme-switch .toggle-ball {
        width: 24px;
        height: 24px;
        background: white;
        border-radius: 50%;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }

    [data-bs-theme="dark"] .theme-switch { background: var(--primary); }
    [data-bs-theme="dark"] .theme-switch .toggle-ball { transform: translateX(-28px); color: var(--primary); }
    [data-bs-theme="light"] .theme-switch .toggle-ball { color: #f59e0b; }

    .page-title {
        font-size: 2rem;
        font-weight: 800;
        background: linear-gradient(135deg, var(--primary), #818cf8);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .user-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 1.5rem;
        padding: 1.5rem;
        transition: var(--transition);
        height: 100%;
        position: relative;
        animation: slideUp 0.5s ease forwards;
    }

    .user-card.is-disabled {
        opacity: 0.6;
        border-style: dashed;
        background: rgba(0,0,0,0.02);
    }

    @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .user-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow);
        border-color: var(--primary);
    }

    .avatar-circle {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, var(--primary), #a5b4fc);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 1rem;
        font-weight: 700;
        font-size: 1.4rem;
        margin-bottom: 1rem;
    }

    .role-badge {
        position: absolute;
        top: 1.5rem;
        left: 1.5rem;
        font-size: 0.75rem;
        padding: 0.35rem 0.8rem;
        border-radius: 50px;
        font-weight: 700;
    }

    .role-admin { background: rgba(79, 70, 229, 0.1); color: #6366f1; }
    .role-cashier { background: rgba(16, 185, 129, 0.1); color: #10b981; }

    .status-badge {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-left: 5px;
    }

    .action-btns {
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border);
        display: flex;
        gap: 0.5rem;
    }

    .form-control, .form-select {
        background-color: var(--input-bg);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 0.75rem;
        color: var(--text-main);
    }

    .modal-content {
        background-color: var(--card-bg);
        border-radius: 1.5rem;
        border: none;
    }
</style>

<div class="container py-5" dir="rtl">
    <div class="top-actions">
        <div>
            <h2 class="page-title mb-1">الموظفين</h2>
            <p class="text-muted">إدارة صلاحيات المستخدمين وحالة الوصول</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="theme-switch" onclick="toggleDarkMode()">
                <div class="toggle-ball"><i class="bi bi-sun-fill" id="themeIcon"></i></div>
            </div>
            <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="bi bi-person-plus me-1"></i> إضافة موظف
            </button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4 animate__animated animate__shakeX">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success border-0 rounded-4 shadow-sm mb-4">
            <i class="bi bi-check-circle-fill me-2"></i> <?= $success ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach($users as $u): ?>
        <div class="col-xl-4 col-md-6">
            <div class="user-card text-end <?= !$u['is_active'] ? 'is-disabled' : '' ?>">
                <span class="role-badge <?= ($u['role_id'] == 1) ? 'role-admin' : 'role-cashier' ?>">
                    <?= ($u['role_id'] == 1) ? 'مدير' : 'كاشير' ?>
                </span>
                
                <div class="avatar-circle shadow-sm">
                    <?= mb_substr($u['full_name'], 0, 1, 'utf-8') ?>
                </div>
                
                <h5 class="fw-bold mb-1">
                    <span class="status-badge <?= $u['is_active'] ? 'bg-success' : 'bg-danger' ?>"></span>
                    <?= htmlspecialchars($u['full_name']) ?>
                </h5>
                <p class="text-muted small mb-0"><i class="bi bi-person me-1"></i> @<?= htmlspecialchars($u['username']) ?></p>
                
                <div class="action-btns">
                    <button class="btn btn-light border flex-grow-1" onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)">
                        <i class="bi bi-pencil-square"></i> تعديل
                    </button>

                    <?php if($u['id'] != 1): ?>
                        <?php if($u['is_active']): ?>
                            <a href="?toggle_status=<?= $u['id'] ?>&status=0" class="btn btn-outline-warning" title="تعطيل الموظف">
                                <i class="bi bi-slash-circle"></i>
                            </a>
                        <?php else: ?>
                            <a href="?toggle_status=<?= $u['id'] ?>&status=1" class="btn btn-outline-success" title="تنشيط الموظف">
                                <i class="bi bi-check-circle"></i>
                            </a>
                        <?php endif; ?>

                        <a href="?delete=<?= $u['id'] ?>" class="btn btn-outline-danger" 
                           onclick="return confirm('تنبيه: سيتم محاولة حذف الموظف نهائياً. في حال وجود سجلات سابقة، سيتم رفض العملية حمايةً للبيانات. هل أنت متأكد؟')">
                            <i class="bi bi-trash"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content p-3 text-end">
            <div class="modal-header border-0">
                <h5 class="fw-bold">إضافة موظف جديد</h5>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-bold">الاسم الكامل</label>
                    <input type="text" name="full_name" class="form-control" placeholder="أدخل اسم الموظف الثلاثي" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">اسم المستخدم</label>
                    <input type="text" name="username" class="form-control" placeholder="اسم الدخول للنظام" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">كلمة المرور</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-bold">الصلاحية</label>
                    <select name="role" class="form-select">
                        <option value="cashier">كاشير</option>
                        <option value="admin">مدير</option>
                    </select>
                </div>
                <button type="submit" name="add_user" class="btn btn-primary w-100 py-3">تأكيد الإضافة</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content p-3 text-end">
            <div class="modal-header border-0">
                <h5 class="fw-bold">تعديل البيانات</h5>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="user_id" id="edit_id">
                <div class="mb-3">
                    <label class="form-label small fw-bold">الاسم الكامل</label>
                    <input type="text" name="full_name" id="edit_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">تغيير كلمة المرور (اختياري)</label>
                    <input type="password" name="password" class="form-control" placeholder="اتركها فارغة للأمان">
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-bold">الصلاحية</label>
                    <select name="role" id="edit_role" class="form-select">
                        <option value="cashier">كاشير</option>
                        <option value="admin">مدير</option>
                    </select>
                </div>
                <button type="submit" name="edit_user" class="btn btn-primary w-100 py-3">حفظ التغييرات</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(user) {
    document.getElementById('edit_id').value = user.id;
    document.getElementById('edit_name').value = user.full_name;
    document.getElementById('edit_role').value = (user.role_id == 1) ? 'admin' : 'cashier';
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

function setTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    localStorage.setItem('theme', theme);
    const icon = document.getElementById('themeIcon');
    icon.className = theme === 'dark' ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
}

function toggleDarkMode() {
    const newTheme = localStorage.getItem('theme') === 'light' ? 'dark' : 'light';
    setTheme(newTheme);
}

document.addEventListener('DOMContentLoaded', () => {
    setTheme(localStorage.getItem('theme') || 'light');
});
</script>

<?php require_once '../includes/footer.php'; ?>