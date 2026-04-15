<?php
/**
 * ملصق حراري 38×25 مم (مقسوم نصفين)
 * - النصف العلوي: الباركود + رقم الباركود تحته
 * - النصف السفلي: اسم المنتج + السعر + الرقم التسلسلي
 */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
check_login();
require_once '../includes/barcode_helpers.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    echo 'معرّف غير صالح';
    exit;
}

$stmt = $pdo->prepare('SELECT id, name, selling_price, barcode FROM products WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    echo 'المنتج غير موجود';
    exit;
}

$payload = (string)$product['id'];
$barcodeSrc = erp_code128_png_data_uri($payload, 1, 40);
$priceFmt = number_format((float) $product['selling_price'], 2) . ' ج.م';
$name = htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8');
$serialNumber = str_pad($product['id'], 6, '0', STR_PAD_LEFT);
$shopName = 'اسم المحل'; // ✅ غيّر هذا إلى اسم محلك
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ملصق — <?= $name ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            background: #fff;
            font-family: 'Cairo', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* شريط الأدوات للشاشة فقط */
        .screen-toolbar {
            padding: 12px 16px;
            background: #0f172a;
            color: #fff;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .screen-toolbar button {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-weight: 600;
        }
        .screen-toolbar button:hover { background: #1d4ed8; }

        /* حاوية الملصق */
        .label-container {
            padding: 16px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 40vh;
            background: #e2e8f0;
        }

        /* ============================================ */
        /* الملصق الحراري - المقاس 38×25 مم */
        /* ============================================ */
        .thermal-label {
            width: 38mm;      /* العرض 38 ملم */
            height: 25mm;     /* الارتفاع 25 ملم */
            background: #fff;
            border: 1px solid #ccc;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            direction: ltr;    /* الاتجاه لضمان ظهور الباركود بشكل صحيح */
        }

        /* النصف العلوي (الباركود + الرقم) */
        .barcode-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2px;
            border-bottom: 1px dashed #ddd;
        }

        /* رأس الملصق - اسم المحل */
        .shop-header {
            font-size: 7pt;
            font-weight: 800;
            color: #000;
            text-align: center;
            margin-bottom: 1px;
            letter-spacing: -0.5px;
        }

        /* صورة الباركود - مسحوبة بالعرض */
        .barcode-img {
            max-width: 34mm;
            width: 100%;
            height: auto;
            max-height: 10mm;
            object-fit: contain;
            image-rendering: crisp-edges;
            margin: 0 auto;
        }

        /* ✅ رقم الباركود (الأرقام تحت الصورة) */
        .barcode-number {
            font-size: 7pt;
            font-weight: 700;
            font-family: 'Courier New', monospace;
            color: #000;
            text-align: center;
            margin-top: 1px;
            letter-spacing: 0.5px;
        }

        /* النصف السفلي (المعلومات) */
        .info-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 2px 4px;
        }

        /* اسم المنتج - سطر واحد */
        .product-name {
            font-size: 7pt;
            font-weight: 600;
            color: #000;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2px;
        }

        /* التذييل (الرقم التسلسلي + السعر) */
        .footer-info {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            width: 100%;
        }

        .serial-number {
            font-size: 6pt;
            font-weight: 400;
            color: #555;
        }

        .price {
            font-size: 9pt;
            font-weight: 800;
            color: #000;
        }

        /* الطباعة */
        @media print {
            @page { 
                size: 38mm 25mm; 
                margin: 0; 
            }
            html, body { 
                background: #fff !important; 
                margin: 0;
                padding: 0;
            }
            .no-print { 
                display: none !important; 
            }
            .label-container { 
                padding: 0 !important; 
                margin: 0 !important; 
                min-height: 0 !important; 
                background: #fff !important; 
                display: block !important; 
            }
            .thermal-label { 
                border: none !important; 
                width: 38mm !important; 
                height: 25mm !important; 
                margin: 0 auto !important; 
            }
        }
    </style>
</head>
<body>
    <!-- شريط الأدوات (يظهر فقط على الشاشة) -->
    <header class="screen-toolbar no-print">
        <button type="button" onclick="window.print()">
            🖨️ طباعة الملصق
        </button>
        <span style="opacity:0.85;font-size:0.9rem;">
            مقاس: 38×25 مم | الباركود مطابق للـ ID: <?= $payload ?>
        </span>
        <button type="button" onclick="window.close()" style="background:#475569;">
            ✖ إغلاق
        </button>
    </header>

    <main class="label-container">
        <div class="thermal-label">
            <!-- ✅ النصف العلوي: الباركود + رقم الباركود -->
            <div class="barcode-section">
                <div class="shop-header"><?= htmlspecialchars($shopName) ?></div>
                <?php if ($barcodeSrc !== ''): ?>
                    <img class="barcode-img" src="<?= $barcodeSrc ?>" alt="باركود">
                <?php endif; ?>
                <!-- ✅ عرض رقم الباركود أسفل الصورة -->
                <div class="barcode-number"><?= $payload ?></div>
            </div>

            <!-- ✅ النصف السفلي: اسم المنتج + الرقم التسلسلي + السعر -->
            <div class="info-section">
                <div class="product-name" title="<?= $name ?>">
                    <?= $name ?>
                </div>
                <div class="footer-info">
                    <span class="serial-number">#<?= $serialNumber ?></span>
                    <span class="price"><?= $priceFmt ?></span>
                </div>
            </div>
        </div>
    </main>

    <?php if ($autoprint): ?>
    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { 
                window.print(); 
            }, 400);
        });
    </script>
    <?php endif; ?>
</body>
</html>