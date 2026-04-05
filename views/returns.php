<?php 
/* File Path: http://localhost/project/ERP_System_V2/views/returns.php */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// --- 1. معالجة طلبات AJAX (بدون تغيير في المنطق البرمجي) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'search_product') {
        $q = $_GET['q'] ?? '';
        $stmt = $pdo->prepare("SELECT id, name, barcode, cost_price, selling_price FROM products WHERE (name LIKE ? OR barcode LIKE ?) AND deleted_at IS NULL LIMIT 10");
        $stmt->execute(["%$q%", "%$q%"]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['action'] === 'process_return') {
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $pdo->beginTransaction();
            $p_id = $data['product_id'];
            $qty = $data['quantity'];
            $return_price = $data['return_price']; 
            $user_id = $_SESSION['user_id'];

            $stmt = $pdo->prepare("SELECT name, cost_price FROM products WHERE id = ?");
            $stmt->execute([$p_id]);
            $product = $stmt->fetch();
            if (!$product) throw new Exception("المنتج غير موجود");

            $stmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
            $stmt->execute([$qty, $p_id]);

            $total_return = $return_price * $qty;
            $desc = "مرتجع منتج: " . $product['name'] . " (عدد: $qty)";
            recordTransaction($pdo, [
                'direction' => 'out',
                'amount' => $total_return,
                'payment_method' => 'cash',
                'description' => $desc,
                'user_id' => (int) $user_id,
                'related_type' => 'return',
            ]);

            $stmt = $pdo->prepare("INSERT INTO returns (product_id, quantity, return_price, user_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$p_id, $qty, $total_return, $user_id]);

            $pdo->commit();
            echo json_encode(['success' => true, 'msg' => 'تمت عملية المرتجع بنجاح وتحديث الخزنة']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'msg' => 'خطأ: ' . $e->getMessage()]);
        }
        exit;
    }
}

require_once '../includes/header.php'; 
?>

<style>
    :root {
        --danger-soft: #fef2f2;
        --danger-main: #dc2626;
        --danger-hover: #b91c1c;
        --primary-indigo: #6366f1;
        --bg-body: #f8fafc;
        --card-bg: #ffffff;
        --text-main: #1e293b;
        --border-subtle: #e2e8f0;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    [data-theme="dark"] {
        --bg-body: #020617;
        --card-bg: #0f172a;
        --text-main: #f1f5f9;
        --border-subtle: #1e293b;
        --danger-soft: rgba(220, 38, 38, 0.1);
    }

    body {
        background: var(--bg-body);
        color: var(--text-main);
        font-family: 'Inter', 'Noto Sans Arabic', sans-serif;
        transition: var(--transition);
    }

    /* ✨ UI Enhancements */
    .return-container {
        max-width: 800px;
        margin: 2rem auto;
        animation: fadeIn 0.5s ease-out;
    }

    .modern-card {
        background: var(--card-bg);
        border: 1px solid var(--border-subtle);
        border-radius: 24px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }

    .card-header-custom {
        background: var(--danger-main);
        padding: 2rem;
        color: white;
        text-align: center;
    }

    /* 🔍 Search Styling */
    .search-wrapper {
        position: relative;
        margin-top: -30px;
        padding: 0 2rem;
    }

    .search-box {
        background: var(--card-bg);
        border: 2px solid var(--border-subtle);
        border-radius: 16px;
        padding: 1rem 1.5rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        display: flex;
        align-items: center;
        transition: var(--transition);
    }

    .search-box:focus-within {
        border-color: var(--danger-main);
        box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1);
    }

    .search-box input {
        border: none;
        outline: none;
        width: 100%;
        background: transparent;
        color: var(--text-main);
        font-size: 1.1rem;
        margin-right: 10px;
    }

    /* 📋 Dropdown List */
    #resList {
        background: var(--card-bg);
        border-radius: 12px;
        margin-top: 8px;
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
        border: 1px solid var(--border-subtle);
        max-height: 300px;
        overflow-y: auto;
    }

    .list-item-custom {
        padding: 1rem;
        border-bottom: 1px solid var(--border-subtle);
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .list-item-custom:hover {
        background: var(--danger-soft);
    }

    /* 📝 Form Section */
    .form-section {
        padding: 2rem;
        display: none;
        animation: slideUp 0.4s ease-out forwards;
    }

    .product-summary {
        background: var(--danger-soft);
        border: 1px dashed var(--danger-main);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 2rem;
        color: var(--danger-main);
        font-weight: 600;
    }

    .form-label-custom {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
        display: block;
    }

    .input-modern {
        width: 100%;
        padding: 0.8rem;
        border-radius: 12px;
        border: 1px solid var(--border-subtle);
        background: var(--bg-body);
        color: var(--text-main);
        transition: var(--transition);
    }

    .input-modern:focus {
        border-color: var(--danger-main);
        outline: none;
    }

    .btn-submit {
        background: var(--danger-main);
        color: white;
        border: none;
        padding: 1.2rem;
        border-radius: 16px;
        font-weight: 700;
        font-size: 1.1rem;
        width: 100%;
        transition: var(--transition);
        margin-top: 2rem;
        box-shadow: 0 4px 14px rgba(220, 38, 38, 0.3);
    }

    .btn-submit:hover {
        background: var(--danger-hover);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
    }

    /* 🌙 Dark Mode Toggle */
    .theme-toggle {
        position: fixed;
        bottom: 2rem;
        left: 2rem;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: var(--danger-main);
        color: white;
        border: none;
        cursor: pointer;
        box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        z-index: 1000;
    }

    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>

<div class="container" dir="rtl">
    <div class="return-container">
        <div class="modern-card">
            <div class="card-header-custom">
                <i class="bi bi-arrow-counterclockwise fs-1 mb-2"></i>
                <h3 class="fw-bold mb-1">مركز المرتجعات المباشرة</h3>
                <p class="opacity-75 small mb-0">قم بإرجاع المنتجات للمخزن ورد المبالغ المالية فوراً</p>
            </div>

            <div class="search-wrapper">
                <div class="search-box">
                    <i class="bi bi-search fs-5 text-muted ms-2"></i>
                    <input type="text" id="prodInput" placeholder="ابحث باسم المنتج أو الباركود...">
                    <i class="bi bi-barcode-scan text-danger fs-5"></i>
                </div>
                <div id="resList"></div>
            </div>

            <div id="returnForm" class="form-section">
                <div class="product-summary text-center" id="selectedProdName"></div>
                
                <div class="row g-4">
                    <input type="hidden" id="p_id">
                    <div class="col-md-6">
                        <label class="form-label-custom">سعر المرتجع للوحدة (ج.م)</label>
                        <input type="number" id="retPrice" class="input-modern" step="0.01">
                        <small class="text-muted mt-1 d-block">السعر الذي سيتم خصمه من الخزنة</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-custom">الكمية المرتجعة</label>
                        <input type="number" id="retQty" class="input-modern" value="1" min="1">
                        <small class="text-muted mt-1 d-block">سيتم إضافة هذه الكمية للمخزن</small>
                    </div>
                </div>

                <div class="total-preview mt-4 p-3 rounded-3 bg-light text-center border" id="totalBox">
                    الإجمالي المسترد: <span class="fw-bold text-danger fs-4" id="totalDisplay">0.00</span> ج.م
                </div>

                <button class="btn-submit shadow" onclick="submitReturn()">
                    <i class="bi bi-shield-check me-2"></i> تأكيد العملية وتحديث الخزنة
                </button>
            </div>
            
            <div class="p-4 text-center text-muted small border-top bg-light bg-opacity-50">
                <i class="bi bi-info-circle me-1"></i> يتم تسجيل كافة المرتجعات في سجل العمليات المالية كـ "مصروفات مرتجع".
            </div>
        </div>
    </div>
</div>

<button class="theme-toggle" onclick="toggleTheme()">
    <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
</button>

<script>
// --- المنطق البرمجي (تم الحفاظ عليه بالكامل مع تحسينات بسيطة للـ UI) ---

// حساب الإجمالي فورياً
function calculateTotal() {
    let price = document.getElementById('retPrice').value || 0;
    let qty = document.getElementById('retQty').value || 0;
    document.getElementById('totalDisplay').innerText = (price * qty).toFixed(2);
}

document.getElementById('retPrice').addEventListener('input', calculateTotal);
document.getElementById('retQty').addEventListener('input', calculateTotal);

// البحث عن المنتج
document.getElementById('prodInput').addEventListener('input', function() {
    let q = this.value;
    if(q.length < 1) { document.getElementById('resList').innerHTML = ''; return; }
    
    fetch(`returns.php?action=search_product&q=${q}`)
        .then(res => res.json())
        .then(data => {
            let html = '';
            data.forEach(p => {
                html += `
                <div class="list-item-custom" onclick='selectForReturn(${JSON.stringify(p)})'>
                    <div>
                        <div class="fw-bold text-main">${p.name}</div>
                        <div class="text-muted small">${p.barcode}</div>
                    </div>
                    <div class="text-danger fw-bold">${p.selling_price} ج.م</div>
                </div>`;
            });
            document.getElementById('resList').innerHTML = html;
        });
});

// اختيار المنتج
function selectForReturn(p) {
    document.getElementById('p_id').value = p.id;
    document.getElementById('retPrice').value = p.selling_price; 
    document.getElementById('selectedProdName').innerHTML = `<i class="bi bi-box-seam me-2"></i> تم اختيار: ${p.name}`;
    document.getElementById('returnForm').style.display = 'block';
    document.getElementById('resList').innerHTML = '';
    document.getElementById('prodInput').value = '';
    calculateTotal();
}

// تنفيذ المرتجع
function submitReturn() {
    let data = {
        product_id: document.getElementById('p_id').value,
        return_price: document.getElementById('retPrice').value,
        quantity: document.getElementById('retQty').value
    };

    if(!data.return_price || data.quantity < 1) {
        alert("يرجى التأكد من السعر والكمية");
        return;
    }

    if(!confirm("⚠️ تنبيه: هل استلمت المنتج فعلاً؟ سيتم خصم مبلغ " + (data.return_price * data.quantity) + " ج.م من الخزينة فوراً.")) return;

    fetch('returns.php?action=process_return', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(res => {
        if(res.success) {
            // استخدام التنبيهات الافتراضية أو Toast إذا كانت متاحة في الهيدر
            alert("✅ " + res.msg);
            location.reload();
        } else {
            alert("❌ " + res.msg);
        }
    });
}

// تبديل الوضع (Dark Mode)
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

// تحميل الوضع المفضل
if (localStorage.getItem('theme') === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
    document.getElementById('themeIcon').className = 'bi bi-sun-fill';
}
</script>

<?php require_once '../includes/footer.php'; ?>