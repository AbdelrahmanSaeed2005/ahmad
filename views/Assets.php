<?php
/* File Path: views/Assets.php */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// التحقق من صلاحية المدير فقط
if (($_SESSION['role_name'] ?? '') !== 'admin') {
    die("عذراً، لا تمتلك صلاحية الوصول.");
}

// ============================================
// دوال المنطق المالي
// ============================================

/**
 * تحديث رصيد الشريك الحالي
 */
function updatePartnerBalance($pdo, $partner_id) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE 
            WHEN type = 'Initial' OR type = 'Capital Increase' THEN amount 
            WHEN type = 'Withdrawal' THEN -amount 
            ELSE 0 END), 0) as balance
        FROM partner_transactions 
        WHERE partner_id = ?
    ");
    $stmt->execute([$partner_id]);
    $balance = $stmt->fetchColumn();
    
    $update = $pdo->prepare("UPDATE partners SET current_balance = ? WHERE id = ?");
    $update->execute([$balance, $partner_id]);
    
    return $balance;
}

/**
 * تحديث جميع أرصدة الشركاء
 */
function updateAllPartnersBalances($pdo) {
    $stmt = $pdo->query("SELECT id FROM partners");
    $partners = $stmt->fetchAll();
    foreach ($partners as $partner) {
        updatePartnerBalance($pdo, $partner['id']);
    }
}

/**
 * تحديث نسب الشركاء بناءً على الرصيد الحالي
 */
function updatePartnersRatios($pdo) {
    $total = $pdo->query("SELECT SUM(current_balance) as total FROM partners")->fetchColumn();
    
    if ($total > 0) {
        $stmt = $pdo->query("SELECT id, current_balance FROM partners");
        $partners = $stmt->fetchAll();
        
        // إضافة عمود النسبة مؤقتاً (ليس في الجدول الأصلي)
        // سنعرض النسبة في الواجهة فقط
    }
    return $total;
}

/**
 * توزيع الأرباح
 */
function distributeProfit($pdo, $total_profit, $distribution_date, $decisions) {
    try {
        $pdo->beginTransaction();
        
        // جلب الشركاء مع أرصدتهم الحالية
        $stmt = $pdo->query("SELECT id, name, current_balance FROM partners");
        $partners = $stmt->fetchAll();
        $total_balance = array_sum(array_column($partners, 'current_balance'));
        
        $distributed = 0;
        
        foreach ($partners as $partner) {
            // حصة الشريك = (رصيد الشريك ÷ إجمالي الأرصدة) × إجمالي الربح
            $share = ($total_balance > 0) ? ($partner['current_balance'] / $total_balance) * $total_profit : 0;
            $decision = $decisions[$partner['id']] ?? 'withdraw';
            
            if ($decision === 'retain') {
                // إعادة استثمار: تضاف للرصيد
                $stmt2 = $pdo->prepare("INSERT INTO partner_transactions (partner_id, amount, type, transaction_date, notes) VALUES (?, ?, 'Capital Increase', ?, ?)");
                $stmt2->execute([$partner['id'], $share, $distribution_date, "إعادة استثمار أرباح بقيمة $share"]);
                $distributed += $share;
            } else {
                // سحب: تسجل كسحب ولا تؤثر على الرصيد
                $stmt2 = $pdo->prepare("INSERT INTO partner_transactions (partner_id, amount, type, transaction_date, notes) VALUES (?, ?, 'Withdrawal', ?, ?)");
                $stmt2->execute([$partner['id'], $share, $distribution_date, "سحب أرباح بقيمة $share"]);
            }
        }
        
        // تحديث أرصدة الشركاء
        updateAllPartnersBalances($pdo);
        
        $pdo->commit();
        return ['success' => true, 'message' => "تم توزيع $distributed ج.م بنجاح"];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// معالجة الطلبات
$message = null;
$error = null;

// إضافة أصل جديد
if (isset($_POST['add_asset'])) {
    $name = $_POST['asset_name'];
    $category = $_POST['category'];
    $initial_value = (float)$_POST['initial_value'];
    $current_value = (float)$_POST['current_value'];
    $purchase_date = $_POST['purchase_date'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO assets (asset_name, category, initial_value, current_value, purchase_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $category, $initial_value, $current_value, $purchase_date]);
        $message = "✅ تم إضافة الأصل بنجاح";
    } catch (Exception $e) {
        $error = "❌ خطأ: " . $e->getMessage();
    }
}

// حذف أصل
if (isset($_GET['delete_asset'])) {
    $id = (int)$_GET['delete_asset'];
    try {
        $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$id]);
        $message = "✅ تم حذف الأصل بنجاح";
    } catch (Exception $e) {
        $error = "❌ خطأ: " . $e->getMessage();
    }
}

// إضافة شريك
if (isset($_POST['add_partner'])) {
    $name = $_POST['partner_name'];
    $initial_investment = (float)$_POST['initial_investment'];
    
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO partners (name, initial_investment, current_balance) VALUES (?, ?, ?)");
        $stmt->execute([$name, $initial_investment, $initial_investment]);
        $partner_id = $pdo->lastInsertId();
        
        $stmt2 = $pdo->prepare("INSERT INTO partner_transactions (partner_id, amount, type, notes) VALUES (?, ?, 'Initial', ?)");
        $stmt2->execute([$partner_id, $initial_investment, "استثمار مبدئي بقيمة $initial_investment"]);
        
        $pdo->commit();
        $message = "✅ تم إضافة الشريك بنجاح";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "❌ خطأ: " . $e->getMessage();
    }
}

// حذف شريك
if (isset($_GET['delete_partner'])) {
    $id = (int)$_GET['delete_partner'];
    try {
        $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$id]);
        $message = "✅ تم حذف الشريك بنجاح";
    } catch (Exception $e) {
        $error = "❌ خطأ: " . $e->getMessage();
    }
}

// توزيع الأرباح
if (isset($_POST['distribute_profit'])) {
    $total_profit = (float)$_POST['total_profit'];
    $distribution_date = $_POST['distribution_date'];
    $decisions = $_POST['decision'] ?? [];
    
    if ($total_profit <= 0) {
        $error = "❌ الرجاء إدخال مبلغ ربح صحيح";
    } else {
        $result = distributeProfit($pdo, $total_profit, $distribution_date, $decisions);
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

// جلب البيانات
$assets = $pdo->query("SELECT * FROM assets ORDER BY created_at DESC")->fetchAll();
$partners = $pdo->query("SELECT * FROM partners ORDER BY created_at DESC")->fetchAll();
$transactions = $pdo->query("
    SELECT pt.*, p.name as partner_name 
    FROM partner_transactions pt 
    JOIN partners p ON pt.partner_id = p.id 
    ORDER BY pt.transaction_date DESC 
    LIMIT 50
")->fetchAll();

// حساب إجمالي الأصول والقيم
$total_assets_value = $pdo->query("SELECT SUM(current_value) FROM assets")->fetchColumn() ?: 0;
$total_partners_balance = $pdo->query("SELECT SUM(current_balance) FROM partners")->fetchColumn() ?: 0;
$total_company_value = $total_assets_value + $total_partners_balance;

require_once '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الأصول والشركاء | ERP V2</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #6366f1;
            --primary-dark: #4338ca;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --bg-sidebar: #ffffff;
            --bg-hover: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --border-medium: #cbd5e1;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.02);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.03);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.03);
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --radius-full: 9999px;
        }

        [data-theme="dark"] {
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --bg-sidebar: #1e293b;
            --bg-hover: #2d3a4f;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-light: #334155;
            --border-medium: #475569;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: repeat(1, 1fr);
            }
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* Cards */
        .card-custom {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header-custom {
            padding: 1rem 1.5rem;
            background: var(--bg-hover);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header-custom h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body-custom {
            padding: 1.5rem;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: right;
            padding: 0.75rem 1rem;
            background: var(--bg-hover);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--border-light);
        }

        .table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-light);
            color: var(--text-primary);
        }

        .table tbody tr:hover {
            background: var(--bg-hover);
        }

        /* Badges */
        .badge-fixed {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
        }

        .badge-current {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
        }

        /* Forms */
        .form-control, .form-select {
            background: var(--bg-card);
            border: 2px solid var(--border-light);
            color: var(--text-primary);
            border-radius: var(--radius-md);
            padding: 0.6rem 1rem;
            width: 100%;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
            display: block;
        }

        /* Buttons */
        .btn-primary-custom {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-outline-custom {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
            border-radius: var(--radius-md);
            padding: 0.5rem 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-outline-custom:hover {
            background: var(--primary);
            color: white;
        }

        .btn-danger-custom {
            background: var(--danger);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-danger-custom:hover {
            background: #dc2626;
        }

        /* Modals */
        .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-light);
            padding: 1rem 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid var(--border-light);
            padding: 1rem 1.5rem;
        }

        /* Alerts */
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid #10b981;
            border-radius: var(--radius-lg);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid #ef4444;
            border-radius: var(--radius-lg);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        /* Ratio bars */
        .ratio-bar {
            height: 6px;
            background: var(--border-light);
            border-radius: var(--radius-full);
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .ratio-fill {
            height: 100%;
            background: var(--primary);
            border-radius: var(--radius-full);
            transition: width 0.5s ease;
        }

        /* Theme Toggle */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 45px;
            height: 45px;
            border-radius: var(--radius-full);
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            z-index: 9999;
            transition: all 0.3s ease;
        }

        .theme-toggle:hover {
            transform: scale(1.1) rotate(15deg);
        }

        /* Partner Card */
        .partner-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .partner-name {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .partner-balance {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--success);
        }

        .decision-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .btn-retain {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            padding: 0.3rem 0.8rem;
            font-size: 0.8rem;
            cursor: pointer;
        }

        .btn-withdraw {
            background: var(--warning);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            padding: 0.3rem 0.8rem;
            font-size: 0.8rem;
            cursor: pointer;
        }
    </style>
</head>
<body>

    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold">
                <i class="bi bi-bank text-primary me-2"></i>
                إدارة الأصول وحقوق الشركاء
            </h2>
            <div>
                <button class="btn btn-primary-custom me-2" data-bs-toggle="modal" data-bs-target="#addAssetModal">
                    <i class="bi bi-plus-circle me-1"></i> أصل جديد
                </button>
                <button class="btn btn-outline-custom" data-bs-toggle="modal" data-bs-target="#addPartnerModal">
                    <i class="bi bi-person-plus me-1"></i> شريك جديد
                </button>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert-success p-3 mb-4 rounded-3">
                <i class="bi bi-check-circle-fill me-2"></i> <?= $message ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-danger p-3 mb-4 rounded-3">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">إجمالي الأصول</div>
                <div class="stat-value"><?= number_format($total_assets_value, 2) ?> <small>ج.م</small></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي رؤوس أموال الشركاء</div>
                <div class="stat-value"><?= number_format($total_partners_balance, 2) ?> <small>ج.م</small></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">قيمة المنشأة</div>
                <div class="stat-value text-primary"><?= number_format($total_company_value, 2) ?> <small>ج.م</small></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">عدد الشركاء</div>
                <div class="stat-value"><?= count($partners) ?> <small>شريك</small></div>
            </div>
        </div>

        <!-- Assets Table -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h3>
                    <i class="bi bi-building text-primary"></i>
                    الأصول
                </h3>
                <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill">
                    <?= count($assets) ?> أصل
                </span>
            </div>
            <div class="card-body-custom">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>اسم الأصل</th>
                                <th>النوع</th>
                                <th>قيمة الشراء</th>
                                <th>القيمة الحالية</th>
                                <th>تاريخ الشراء</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($assets) > 0): ?>
                                <?php foreach ($assets as $index => $asset): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($asset['asset_name']) ?></td>
                                        <td>
                                            <span class="<?= $asset['category'] == 'fixed' ? 'badge-fixed' : 'badge-current' ?>">
                                                <?= $asset['category'] == 'fixed' ? '🏢 ثابت' : '💰 متداول' ?>
                                            </span>
                                        </td>
                                        <td><?= number_format($asset['initial_value'], 2) ?> ج.م</td>
                                        <td><?= number_format($asset['current_value'], 2) ?> ج.م</td>
                                        <td><?= date('Y-m-d', strtotime($asset['purchase_date'])) ?> </td>
                                        <td>
                                            <a href="?delete_asset=<?= $asset['id'] ?>" class="btn-danger-custom" onclick="return confirm('هل أنت متأكد من حذف هذا الأصل؟')">
                                                <i class="bi bi-trash3"></i> حذف
                                            </a>
                                         </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="bi bi-box fs-1 d-block mb-2 opacity-50"></i>
                                        لا توجد أصول مضافة
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Partners Section -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h3>
                    <i class="bi bi-people text-primary"></i>
                    الشركاء
                </h3>
                <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill">
                    <?= count($partners) ?> شريك
                </span>
            </div>
            <div class="card-body-custom">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>اسم الشريك</th>
                                <th>الاستثمار المبدئي</th>
                                <th>الرصيد الحالي</th>
                                <th>النسبة المئوية</th>
                                <th>تاريخ الانضمام</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_balance = array_sum(array_column($partners, 'current_balance'));
                            ?>
                            <?php if (count($partners) > 0): ?>
                                <?php foreach ($partners as $index => $partner): ?>
                                    <?php 
                                    $ratio = $total_balance > 0 ? ($partner['current_balance'] / $total_balance) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><?= $index + 1 ?> </td>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($partner['name']) ?></div>
                                            <small class="text-muted">ID: #<?= $partner['id'] ?></small>
                                         </td>
                                        <td><?= number_format($partner['initial_investment'], 2) ?> ج.م</td>
                                        <td>
                                            <span class="partner-balance"><?= number_format($partner['current_balance'], 2) ?> ج.م</span>
                                         </td>
                                        <td>
                                            <div><?= number_format($ratio, 2) ?>%</div>
                                            <div class="ratio-bar">
                                                <div class="ratio-fill" style="width: <?= $ratio ?>%"></div>
                                            </div>
                                         </td>
                                        <td><?= date('Y-m-d', strtotime($partner['created_at'])) ?> </td>
                                        <td>
                                            <a href="?delete_partner=<?= $partner['id'] ?>" class="btn-danger-custom" onclick="return confirm('هل أنت متأكد من حذف هذا الشريك؟ سيتم حذف جميع معاملاته.')">
                                                <i class="bi bi-trash3"></i> حذف
                                            </a>
                                         </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="bi bi-people fs-1 d-block mb-2 opacity-50"></i>
                                        لا توجد شركاء
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Profit Distribution Section -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h3>
                    <i class="bi bi-graph-up text-success"></i>
                    توزيع الأرباح
                </h3>
            </div>
            <div class="card-body-custom">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">تاريخ التوزيع</label>
                            <input type="date" name="distribution_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">إجمالي الربح</label>
                            <input type="number" step="0.01" name="total_profit" class="form-control" placeholder="مبلغ الربح" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ملاحظات</label>
                            <input type="text" name="notes" class="form-control" placeholder="ملاحظات حول توزيع الأرباح">
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="form-label fw-bold">قرارات الشركاء</label>
                        <div class="row g-3">
                            <?php foreach ($partners as $partner): ?>
                                <?php 
                                $ratio = $total_balance > 0 ? ($partner['current_balance'] / $total_balance) * 100 : 0;
                                ?>
                                <div class="col-md-4">
                                    <div class="partner-card">
                                        <div class="partner-name"><?= htmlspecialchars($partner['name']) ?></div>
                                        <div class="small text-muted">الرصيد الحالي: <?= number_format($partner['current_balance'], 2) ?> ج.م</div>
                                        <div class="small text-muted">النسبة الحالية: <?= number_format($ratio, 2) ?>%</div>
                                        <div class="decision-buttons">
                                            <label class="btn-retain" style="display: inline-flex; align-items: center; gap: 0.3rem;">
                                                <input type="radio" name="decision[<?= $partner['id'] ?>]" value="retain" checked> 
                                                <i class="bi bi-arrow-repeat"></i> إعادة استثمار
                                            </label>
                                            <label class="btn-withdraw" style="display: inline-flex; align-items: center; gap: 0.3rem;">
                                                <input type="radio" name="decision[<?= $partner['id'] ?>]" value="withdraw"> 
                                                <i class="bi bi-cash-stack"></i> سحب نقدي
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" name="distribute_profit" class="btn btn-primary-custom w-100 py-3">
                            <i class="bi bi-check-circle me-2"></i>
                            توزيع الأرباح
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Transactions History -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h3>
                    <i class="bi bi-clock-history text-info"></i>
                    سجل العمليات
                </h3>
                <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill">
                    آخر 50 عملية
                </span>
            </div>
            <div class="card-body-custom">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>الشريك</th>
                                <th>المبلغ</th>
                                <th>نوع العملية</th>
                                <th>الملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($transactions) > 0): ?>
                                <?php foreach ($transactions as $trans): ?>
                                    <tr>
                                        <td><?= date('Y-m-d H:i', strtotime($trans['transaction_date'])) ?> </td>
                                        <td><?= htmlspecialchars($trans['partner_name']) ?> </td>
                                        <td>
                                            <span class="<?= $trans['type'] == 'Withdrawal' ? 'text-danger' : 'text-success' ?> fw-bold">
                                                <?= $trans['type'] == 'Withdrawal' ? '-' : '+' ?> <?= number_format($trans['amount'], 2) ?> ج.م
                                            </span>
                                         </td>
                                        <td>
                                            <?php
                                            $typeLabels = [
                                                'Initial' => '🏁 استثمار مبدئي',
                                                'Withdrawal' => '💰 سحب نقدي',
                                                'Capital Increase' => '📈 زيادة رأس المال'
                                            ];
                                            echo $typeLabels[$trans['type']] ?? $trans['type'];
                                            ?>
                                         </td>
                                        <td><?= htmlspecialchars($trans['notes'] ?: '—') ?> </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="bi bi-journal fs-1 d-block mb-2 opacity-50"></i>
                                        لا توجد عمليات مسجلة
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Asset Modal -->
    <div class="modal fade" id="addAssetModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-building me-2"></i>إضافة أصل جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الأصل</label>
                        <input type="text" name="asset_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع الأصل</label>
                        <select name="category" class="form-select">
                            <option value="fixed">ثابت (مباني - أجهزة - مكاتب)</option>
                            <option value="current">متداول (نقدي - مخزون)</option>
                        </select>
                    </div>
                                        <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">قيمة الشراء</label>
                            <input type="number" step="0.01" name="initial_value" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">القيمة الحالية</label>
                            <input type="number" step="0.01" name="current_value" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label">تاريخ الشراء</label>
                        <input type="date" name="purchase_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_asset" class="btn btn-primary-custom">إضافة الأصل</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Partner Modal -->
    <div class="modal fade" id="addPartnerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>إضافة شريك جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الشريك</label>
                        <input type="text" name="partner_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الاستثمار المبدئي</label>
                        <input type="number" step="0.01" name="initial_investment" class="form-control" required>
                    </div>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-2"></i>
                        سيتم تسجيل الاستثمار المبدئي كأول عملية للشريك.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_partner" class="btn btn-primary-custom">إضافة الشريك</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Theme Toggle
        function toggleTheme() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme') || 'light';
            const target = current === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', target);
            localStorage.setItem('assets_theme', target);
            
            const icon = document.getElementById('themeIcon');
            icon.className = target === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        }

        // Load saved theme
        document.addEventListener('DOMContentLoaded', () => {
            const saved = localStorage.getItem('assets_theme') || 'light';
            document.documentElement.setAttribute('data-theme', saved);
            
            const icon = document.getElementById('themeIcon');
            icon.className = saved === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        });
    </script>
</body>
</html>

<?php require_once '../includes/footer.php'; ?>