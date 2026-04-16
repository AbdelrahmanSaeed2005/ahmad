<?php 
/* File Path: http://localhost/project/ERP_System_V2/views/products.php
 * Description: إدارة المنتجات - نسخة محسنة تماماً مع حل مشكلة Duplicate Entry
 */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// --- 1. منطق الحذف (بدون تغيير) ---
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("UPDATE products SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    log_action($pdo, $_SESSION['user_id'], 'delete_product', "ID: $id");
    $_SESSION['msg'] = "تم نقل المنتج إلى سلة المحذوفات";
    $_SESSION['msg_type'] = "danger";
    header("Location: products.php");
    exit();
}

// --- 2. منطق الإضافة/التعديل (مع تحسين التحقق) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_product']) || isset($_POST['edit_product']))) {
    if (verify_csrf($_POST['csrf_token'])) {
        $name = trim($_POST['name']);
        $barcode = trim($_POST['barcode']);
        $cat_id = (int)$_POST['category_id'];
        $cost = (float)$_POST['cost_price'];
        $sell = (float)$_POST['selling_price'];
        $min_sell = (float)$_POST['min_selling_price'];
        $stock = (int)$_POST['stock_quantity'];
        $product_id = (int)($_POST['product_id'] ?? 0);

        // ✅ التحقق بشكل منفصل - الأهم: فحص الباركود أولاً
        $check_barcode = $pdo->prepare("SELECT id, name FROM products WHERE barcode = ? AND id != ? AND deleted_at IS NULL");
        $check_barcode->execute([$barcode, $product_id]);
        $existing_barcode = $check_barcode->fetch();

        $check_name = $pdo->prepare("SELECT id FROM products WHERE name = ? AND id != ? AND deleted_at IS NULL");
        $check_name->execute([$name, $product_id]);
        $existing_name = $check_name->fetch();

        // رسائل خطأ محددة لكل حالة
        if ($existing_barcode) {
            $_SESSION['msg'] = "⚠️ الباركود '" . htmlspecialchars($barcode) . "' مستخدم بالفعل للمنتج: " . htmlspecialchars($existing_barcode['name']);
            $_SESSION['msg_type'] = "warning";
        } elseif ($existing_name) {
            $_SESSION['msg'] = "⚠️ اسم المنتج '" . htmlspecialchars($name) . "' موجود بالفعل";
            $_SESSION['msg_type'] = "warning";
        } else {
            // التحقق من صحة الأسعار
            if ($min_sell > $sell) {
                $_SESSION['msg'] = "⚠️ الحد الأدنى للسعر لا يمكن أن يكون أكبر من سعر البيع";
                $_SESSION['msg_type'] = "warning";
            } elseif ($cost > $sell) {
                $_SESSION['msg'] = "⚠️ سعر التكلفة لا يمكن أن يكون أكبر من سعر البيع";
                $_SESSION['msg_type'] = "warning";
            } else {
                try {
                    if (isset($_POST['edit_product'])) {
                        $stmt = $pdo->prepare("UPDATE products SET name=?, barcode=?, category_id=?, cost_price=?, selling_price=?, min_selling_price=?, stock_quantity=? WHERE id=?");
                        $stmt->execute([$name, $barcode, $cat_id, $cost, $sell, $min_sell, $stock, $product_id]);
                        $_SESSION['msg'] = "✅ تم تحديث بيانات المنتج بنجاح";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO products (name, barcode, category_id, cost_price, selling_price, min_selling_price, stock_quantity) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $barcode, $cat_id, $cost, $sell, $min_sell, $stock]);
                        $_SESSION['msg'] = "✅ تم إضافة المنتج الجديد بنجاح";
                    }
                    $_SESSION['msg_type'] = "success";
                } catch (PDOException $e) {
                    // في حالة وجود خطأ غير متوقع في قاعدة البيانات
                    if ($e->errorInfo[1] == 1062) {
                        $_SESSION['msg'] = "⚠️ الباركود مكرر: " . htmlspecialchars($barcode);
                    } else {
                        $_SESSION['msg'] = "❌ حدث خطأ في قاعدة البيانات: " . $e->getMessage();
                    }
                    $_SESSION['msg_type'] = "danger";
                }
            }
        }
        header("Location: products.php");
        exit();
    }
}

// --- 3b. AJAX: منتجات (فئة + بحث + مخزون) — للواجهة الديناميكية ---
if (isset($_GET['action']) && $_GET['action'] === 'products_by_category') {
    header('Content-Type: application/json; charset=utf-8');
    $cat = $_GET['category_id'] ?? 'all';
    $q = trim($_GET['q'] ?? '');
    $stock = $_GET['stock'] ?? 'all';
    if (!in_array($stock, ['all', 'available', 'lowstock', 'outofstock'], true)) {
        $stock = 'all';
    }
    $where = ['p.deleted_at IS NULL'];
    $params = [];
    if ($cat !== 'all' && $cat !== '') {
        $where[] = 'p.category_id = ?';
        $params[] = (int) $cat;
    }
    if ($q !== '') {
        $where[] = 'p.name LIKE ?';
        $params[] = '%' . $q . '%';
    }
    if ($stock === 'available') {
        $where[] = 'p.stock_quantity > 0';
    } elseif ($stock === 'lowstock') {
        $where[] = 'p.stock_quantity > 0 AND p.stock_quantity <= 5';
    } elseif ($stock === 'outofstock') {
        $where[] = 'p.stock_quantity = 0';
    }
    $whereSql = implode(' AND ', $where);
    $sql = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE $whereSql ORDER BY p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 3. AJAX endpoint للتحقق المباشر من الباركود ---
if (isset($_GET['check_barcode'])) {
    header('Content-Type: application/json');
    $barcode = $_GET['check_barcode'];
    $exclude_id = (int)($_GET['id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT id, name FROM products WHERE barcode = ? AND id != ? AND deleted_at IS NULL");
    $stmt->execute([$barcode, $exclude_id]);
    $product = $stmt->fetch();
    
    echo json_encode([
        'exists' => $product ? true : false,
        'product' => $product ? $product['name'] : null
    ]);
    exit;
}

$categories = $pdo->query("SELECT * FROM categories WHERE deleted_at IS NULL ORDER BY name")->fetchAll();

$allowedPer = [15, 20, 30, 50];
$perPageRaw = $_GET['per_page'] ?? '15';
$perPageAll = ($perPageRaw === 'all');
$perPage = in_array((int) $perPageRaw, $allowedPer, true) ? (int) $perPageRaw : 15;
$nameSearch = trim($_GET['name_search'] ?? '');
$catFilter = $_GET['cat_filter'] ?? 'all';
$stockFilter = $_GET['stock_filter'] ?? 'all';
if (!in_array($stockFilter, ['all', 'available', 'lowstock', 'outofstock'], true)) {
    $stockFilter = 'all';
}

$listWhere = ['p.deleted_at IS NULL'];
$listParams = [];
if ($catFilter !== 'all' && $catFilter !== '') {
    $listWhere[] = 'p.category_id = ?';
    $listParams[] = (int) $catFilter;
}
if ($nameSearch !== '') {
    $listWhere[] = 'p.name LIKE ?';
    $listParams[] = '%' . $nameSearch . '%';
}
if ($stockFilter === 'available') {
    $listWhere[] = 'p.stock_quantity > 0';
} elseif ($stockFilter === 'lowstock') {
    $listWhere[] = 'p.stock_quantity > 0 AND p.stock_quantity <= 5';
} elseif ($stockFilter === 'outofstock') {
    $listWhere[] = 'p.stock_quantity = 0';
}
$listWhereSql = implode(' AND ', $listWhere);

$countListStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $listWhereSql");
$countListStmt->execute($listParams);
$listTotal = (int) $countListStmt->fetchColumn();

$page = max(1, (int) ($_GET['page'] ?? 1));
if ($perPageAll) {
    $perPage = max(1, $listTotal);
    $totalPages = 1;
    $page = 1;
    $offset = 0;
} else {
    $totalPages = max(1, (int) ceil($listTotal / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
}

$sqlList = "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE $listWhereSql ORDER BY p.id DESC";
if (!$perPageAll) {
    $sqlList .= ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
}
$listStmt = $pdo->prepare($sqlList);
$listStmt->execute($listParams);
$products = $listStmt->fetchAll();

$total_products = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL")->fetchColumn();
$zero_stock_products = $pdo->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND stock_quantity = 0")->fetchColumn();
$active_products = $pdo->query("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND stock_quantity > 0")->fetchColumn();

function products_list_url(array $overrides = []): string {
    $params = [
        'name_search' => $overrides['name_search'] ?? ($_GET['name_search'] ?? ''),
        'cat_filter' => $overrides['cat_filter'] ?? ($_GET['cat_filter'] ?? 'all'),
        'stock_filter' => $overrides['stock_filter'] ?? ($_GET['stock_filter'] ?? 'all'),
        'per_page' => $overrides['per_page'] ?? ($_GET['per_page'] ?? '15'),
        'page' => $overrides['page'] ?? ($_GET['page'] ?? 1),
    ];
    $q = [];
    if ($params['name_search'] !== '') {
        $q['name_search'] = $params['name_search'];
    }
    if ($params['cat_filter'] !== '' && $params['cat_filter'] !== 'all') {
        $q['cat_filter'] = $params['cat_filter'];
    }
    if ($params['stock_filter'] !== '' && $params['stock_filter'] !== 'all') {
        $q['stock_filter'] = $params['stock_filter'];
    }
    if ($params['per_page'] !== '' && $params['per_page'] !== '15') {
        $q['per_page'] = $params['per_page'];
    }
    if ((int) $params['page'] > 1) {
        $q['page'] = (int) $params['page'];
    }
    $qs = http_build_query($q);
    return 'products.php' . ($qs !== '' ? '?' . $qs : '');
}

require_once '../includes/header.php'; 
?>

<style>
:root {
    --primary-soft: #6366f1;
    --primary-hover: #4f46e5;
    --bg-main: #f9fafb;
    --bg-card: #ffffff;
    --text-main: #1f2937;
    --text-muted: #6b7280;
    --border-soft: #e5e7eb;
    --glass-bg: rgba(255, 255, 255, 0.7);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

[data-theme="dark"] {
    --bg-main: #0f172a;
    --bg-card: #1e293b;
    --text-main: #f1f5f9;
    --text-muted: #94a3b8;
    --border-soft: #334155;
    --glass-bg: rgba(15, 23, 42, 0.7);
}

body {
    background-color: var(--bg-main);
    color: var(--text-main);
    font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
    transition: var(--transition);
}

/* Glass Header */
.stats-bar {
    background: var(--glass-bg);
    backdrop-filter: blur(12px);
    border: 1px solid var(--border-soft);
    border-radius: 20px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 15px -3px rgba(0,0,0,0.05);
}

/* Modern Stats Badges */
.stat-pill {
    padding: 0.5rem 1rem;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: var(--transition);
}

/* Grid System for Filters */
.filter-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    background: var(--bg-card);
    padding: 8px;
    border-radius: 14px;
    width: fit-content;
    border: 1px solid var(--border-soft);
}

.filter-btn {
    border: none;
    background: transparent;
    color: var(--text-muted);
    padding: 8px 18px;
    border-radius: 10px;
    font-size: 0.9rem;
    transition: var(--transition);
}

.filter-btn.active {
    background: var(--primary-soft);
    color: white;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

a.filter-btn {
    color: inherit;
    display: inline-block;
}

a.filter-btn:hover {
    color: var(--primary-soft);
}

/* Table Enhancements */
.modern-card {
    background: var(--bg-card);
    border-radius: 20px;
    border: 1px solid var(--border-soft);
    overflow: hidden;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.02);
}

.table thead th {
    background: var(--bg-main);
    color: var(--text-muted);
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
    padding: 1.2rem;
    border: none;
}

.table tbody tr {
    transition: var(--transition);
    border-bottom: 1px solid var(--border-soft);
}

.table tbody tr:hover {
    background-color: rgba(99, 102, 241, 0.03) !important;
    transform: scale(1.002);
}

/* Dark Mode Switch */
.theme-toggle {
    cursor: pointer;
    width: 45px;
    height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-card);
    border: 1px solid var(--border-soft);
    color: var(--primary-soft);
    transition: var(--transition);
}

.theme-toggle:hover {
    transform: rotate(15deg);
    border-color: var(--primary-soft);
}

/* Animations */
.fade-in { animation: fadeIn 0.5s ease-out; }
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 768px) {
    .stats-bar { flex-direction: column; text-align: center; gap: 1rem; }
    .filter-group { width: 100%; justify-content: center; }
}
</style>

<div class="container-fluid py-4 fade-in">
    <div class="stats-bar d-flex justify-content-between align-items-center">
        <div>
            <h2 class="fw-bold m-0" style="color: var(--primary-soft);">المخزن الذكي</h2>
            <div class="mt-2 d-flex flex-wrap gap-2">
                <div class="stat-pill bg-success-subtle text-success">
                    <i class="bi bi-check2-circle"></i> متاح: <?= $active_products ?>
                </div>
                <div class="stat-pill bg-warning-subtle text-warning">
                    <i class="bi bi-exclamation-triangle"></i> منتهي: <?= $zero_stock_products ?>
                </div>
                <div class="stat-pill bg-primary-subtle text-primary">
                    <i class="bi bi-layers"></i> الإجمالي: <?= $total_products ?>
                </div>
            </div>
        </div>
        
        <div class="d-flex gap-3 align-items-center">
            <button class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#productModal">
                <i class="bi bi-plus-circle-fill me-2"></i> إضافة صنف جديد
            </button>
        </div>
    </div>

    <?php if(isset($_SESSION['msg'])): ?>
        <?php $productsStockBroadcast = (($_SESSION['msg_type'] ?? '') === 'success'); ?>
        <div class="alert alert-<?= $_SESSION['msg_type'] ?> border-0 shadow-sm rounded-4 fade show d-flex align-items-center mb-4">
            <i class="bi bi-info-circle-fill me-3 fs-4"></i>
            <div class="flex-grow-1"><?= $_SESSION['msg'] ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php if (!empty($productsStockBroadcast)): ?>
        <script>
        try {
            var _bc = new BroadcastChannel('erp_product_stock');
            _bc.postMessage({ type: 'stock_updated' });
            _bc.close();
        } catch (e) {}
        </script>
        <?php endif; ?>
        <?php unset($_SESSION['msg']); unset($_SESSION['msg_type']); ?>
    <?php endif; ?>

    <form method="get" class="mb-4" action="products.php" id="productsFilterForm">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
            <div class="d-flex flex-wrap align-items-end gap-3 flex-grow-1">
                <div style="min-width: 200px;">
                    <label class="form-label small text-muted mb-1 d-block">بحث بالاسم</label>
                    <input type="text" name="name_search" class="form-control rounded-3 border shadow-sm" placeholder="اسم المنتج..." value="<?= htmlspecialchars($nameSearch) ?>">
                </div>
                <div>
                    <label class="form-label small text-muted mb-1 d-block">الفئة</label>
                    <select name="cat_filter" class="form-select rounded-3 border shadow-sm" style="min-width: 200px;" onchange="this.form.page.value=1;this.form.submit()">
                        <option value="all"<?= ($catFilter === 'all' || $catFilter === '') ? ' selected' : '' ?>>كل الفئات</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"<?= ((string)$catFilter === (string)$c['id']) ? ' selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label small text-muted mb-1 d-block">عدد الصفوف</label>
                    <select name="per_page" class="form-select rounded-3 border shadow-sm" style="min-width: 130px;" onchange="this.form.page.value=1;this.form.submit()">
                        <option value="15"<?= ($perPageRaw === '15') ? ' selected' : '' ?>>15</option>
                        <option value="20"<?= ($perPageRaw === '20') ? ' selected' : '' ?>>20</option>
                        <option value="30"<?= ($perPageRaw === '30') ? ' selected' : '' ?>>30</option>
                        <option value="50"<?= ($perPageRaw === '50') ? ' selected' : '' ?>>50</option>
                        <option value="all"<?= $perPageAll ? ' selected' : '' ?>>الكل</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small text-muted mb-1 d-block d-md-block">&nbsp;</label>
                    <button type="submit" class="btn btn-primary rounded-3 px-4" onclick="this.form.page.value='1';"><i class="bi bi-search me-1"></i> بحث</button>
                </div>
            </div>
        </div>
        <input type="hidden" name="page" value="<?= (int)$page ?>">
        <?php if ($stockFilter !== 'all' && $stockFilter !== ''): ?>
            <input type="hidden" name="stock_filter" value="<?= htmlspecialchars($stockFilter) ?>">
        <?php endif; ?>
    </form>

    <div class="filter-group shadow-sm mb-4">
        <a href="<?= htmlspecialchars(products_list_url(['stock_filter' => 'all', 'page' => 1])) ?>" class="filter-btn text-decoration-none<?= ($stockFilter === 'all' || $stockFilter === '') ? ' active' : '' ?>">كل المنتجات</a>
        <a href="<?= htmlspecialchars(products_list_url(['stock_filter' => 'available', 'page' => 1])) ?>" class="filter-btn text-decoration-none<?= $stockFilter === 'available' ? ' active' : '' ?>">المتاح</a>
        <a href="<?= htmlspecialchars(products_list_url(['stock_filter' => 'lowstock', 'page' => 1])) ?>" class="filter-btn text-decoration-none<?= $stockFilter === 'lowstock' ? ' active' : '' ?>">مخزون حرج</a>
        <a href="<?= htmlspecialchars(products_list_url(['stock_filter' => 'outofstock', 'page' => 1])) ?>" class="filter-btn text-decoration-none<?= $stockFilter === 'outofstock' ? ' active' : '' ?>">المنتهية</a>
    </div>

    <div class="modern-card">
        <div class="table-responsive">
            <table class="table align-middle m-0" id="productsTable">
                <thead>
                    <tr>
                        <th class="ps-4">الباركود</th>
                        <th>المنتج</th>
                        <th>الفئة</th>
                        <th>التكلفة والبيع</th>
                        <th class="text-center">حالة المخزون</th>
                        <th class="text-center pe-4">الإجراءات</th>
                    </tr>
                </thead>
                <tbody id="productsTableBody">
                    <?php if (count($products) > 0): ?>
                        <?php foreach ($products as $p): ?>
                        <tr class="product-row" data-stock="<?= $p['stock_quantity'] ?>" data-category-id="<?= (int)($p['category_id'] ?? 0) ?>">
                            <td class="ps-4">
                                <code class="small text-muted bg-light px-2 py-1 rounded"><?= htmlspecialchars($p['barcode']) ?></code>
                            </td>
                            <td>
                                <div class="fw-bold text-main"><?= htmlspecialchars($p['name']) ?></div>
                            </td>
                            <td>
                                <span class="badge rounded-pill bg-light text-muted border px-3"><?= htmlspecialchars($p['category_name']) ?></span>
                            </td>
                            <td>
                                <div class="small text-muted">شراء: <?= number_format($p['cost_price'], 2) ?></div>
                                <div class="fw-bold text-primary">بيع: <?= number_format($p['selling_price'], 2) ?></div>
                            </td>
                            <td class="text-center">
                                <?php 
                                $stock = $p['stock_quantity'];
                                $badge_class = $stock == 0 ? 'bg-danger' : ($stock <= 5 ? 'bg-warning' : 'bg-success');
                                ?>
                                <span class="badge <?= $badge_class ?> shadow-sm" style="width: 45px; height: 45px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1rem;">
                                    <?= $stock ?>
                                </span>
                            </td>
                            <td class="text-center pe-4">
                                <div class="btn-group shadow-sm rounded-3">
                                    <a href="print_product_label.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" class="btn btn-white border" title="عرض الباركود وطباعة الملصق">
                                        <i class="bi bi-upc-scan text-info"></i>
                                    </a>
                                    <button type="button" class="btn btn-white border edit-btn" data-json="<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#productModal">
                                        <i class="bi bi-pencil-square text-warning"></i>
                                    </button>
                                    <a href="?delete=<?= $p['id'] ?>" class="btn btn-white border" onclick="return confirm('نقل المنتج لسلة المحذوفات؟')">
                                        <i class="bi bi-trash3 text-danger"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <i class="bi bi-cloud-slash display-1 text-muted opacity-25"></i>
                                <h4 class="text-muted mt-3">لا يوجد بيانات لعرضها</h4>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!$perPageAll && $totalPages > 1): ?>
    <nav class="mt-4" aria-label="ترقيم المنتجات">
        <ul class="pagination justify-content-center flex-wrap gap-1">
            <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                <a class="page-link rounded-3" href="<?= $page <= 1 ? '#' : htmlspecialchars(products_list_url(['page' => $page - 1])) ?>">&lt;</a>
            </li>
            <?php
            $range = 2;
            for ($i = 1; $i <= $totalPages; $i++) {
                if ($i === 1 || $i === $totalPages || ($i >= $page - $range && $i <= $page + $range)) {
                    ?>
            <li class="page-item<?= $i === $page ? ' active' : '' ?>">
                <a class="page-link rounded-3" href="<?= htmlspecialchars(products_list_url(['page' => $i])) ?>"><?= $i ?></a>
            </li>
                    <?php
                } elseif ($i === $page - $range - 1 || $i === $page + $range + 1) {
                    ?>
            <li class="page-item disabled"><span class="page-link rounded-3">…</span></li>
                    <?php
                }
            }
            ?>
            <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                <a class="page-link rounded-3" href="<?= $page >= $totalPages ? '#' : htmlspecialchars(products_list_url(['page' => $page + 1])) ?>">&gt;</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-plus-circle me-2"></i>صنف جديد</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="product_id" id="product_id">
                
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">اسم المنتج</label>
                        <input type="text" name="name" id="name" class="form-control form-control-lg rounded-3 border-0 shadow-sm" placeholder="مثال: آيفون 15 برو" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">الباركود</label>
                        <input type="text" name="barcode" id="barcode" class="form-control form-control-lg rounded-3 border-0 shadow-sm" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">الفئة</label>
                        <select name="category_id" id="category_id" class="form-select form-select-lg rounded-3 border-0 shadow-sm" required>
                            <?php foreach($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">الكمية الافتتاحية</label>
                        <input type="number" name="stock_quantity" id="stock_quantity" class="form-control form-control-lg rounded-3 border-0 shadow-sm" value="0">
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded-4 shadow-sm">
                            <label class="form-label small fw-bold text-danger">تكلفة الشراء</label>
                            <input type="number" step="0.01" name="cost_price" id="cost_price" class="form-control border-0 p-0 fs-4 fw-bold" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded-4 shadow-sm">
                            <label class="form-label small fw-bold text-success">سعر البيع</label>
                            <input type="number" step="0.01" name="selling_price" id="selling_price" class="form-control border-0 p-0 fs-4 fw-bold" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded-4 shadow-sm">
                            <label class="form-label small fw-bold text-warning">الحد الأدنى</label>
                            <input type="number" step="0.01" name="min_selling_price" id="min_selling_price" class="form-control border-0 p-0 fs-4 fw-bold" required>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 bg-white">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" name="add_product" id="submitBtn" class="btn btn-primary rounded-pill px-5 fw-bold shadow">حفظ الصنف</button>
            </div>
        </form>
    </div>
</div>

<script>
function fillProductModal(data) {
    document.getElementById('modalTitle').innerText = 'تعديل البيانات: ' + data.name;
    document.getElementById('submitBtn').name = 'edit_product';
    document.getElementById('product_id').value = data.id;
    document.getElementById('name').value = data.name;
    document.getElementById('barcode').value = data.barcode;
    document.getElementById('category_id').value = data.category_id;
    document.getElementById('stock_quantity').value = data.stock_quantity;
    document.getElementById('cost_price').value = data.cost_price;
    document.getElementById('selling_price').value = data.selling_price;
    document.getElementById('min_selling_price').value = data.min_selling_price;
}

document.getElementById('productsTableBody').addEventListener('click', function(e) {
    const editBtn = e.target.closest('.edit-btn');
    if (!editBtn || !editBtn.dataset.json) return;
    try {
        fillProductModal(JSON.parse(editBtn.dataset.json));
    } catch (err) { /* ignore */ }
});

document.querySelector('[data-bs-target="#productModal"]').addEventListener('click', function() {
    if (document.getElementById('submitBtn').name === 'edit_product') {
        document.getElementById('modalTitle').innerText = 'إضافة صنف جديد';
        document.getElementById('submitBtn').name = 'add_product';
        document.getElementById('product_id').value = '';
        document.querySelector('#productModal form').reset();
    }
});

// Dark Mode Toggle System 2026
function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateThemeIcon(newTheme);
}

function updateThemeIcon(theme) {
    const icon = document.querySelector('#themeSwitcher i');
    icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
}

// Initial Theme Check
document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);
});
</script>

<?php require_once '../includes/footer.php'; ?>