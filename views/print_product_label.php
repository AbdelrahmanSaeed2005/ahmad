<?php
/**
 * ملصق حراري 50×30 مم — تم التعديل لضمان مطابقة الباركود مع ID المنتج
 */
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
check_login();
require_once '../includes/barcode_helpers.php';

// تفعيل إظهار الأخطاء للتصحيح (يمكنك حذفها بعد التأكد من العمل)
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

/** * التعديل الجوهري هنا:
 * نستخدم الـ ID الصافي كـ Payload لضمان أن الكاميرا تقرأ "1" 
 * وقاعدة البيانات تبحث عن "1".
 */
$payload = (string)$product['id']; 

// توليد صورة الباركود بناءً على الـ ID الصافي
$barcodeSrc = erp_code128_png_data_uri($payload, 1, 40);

$priceFmt = number_format((float) $product['selling_price'], 2) . ' ج.م';
$name = htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8');
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ملصق — <?= $name ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            font-family: 'Cairo', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

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

        .label-main {
            padding: 16px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 40vh;
            background: #e2e8f0;
        }

        .thermal-label {
            width: 50mm;
            height: 30mm;
            background: #fff;
            border: 1px solid #ccc;
            padding: 1mm 1.5mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            text-align: center;
            overflow: hidden;
        }

        .thermal-label .product-name {
            font-size: 7pt;
            font-weight: 700;
            line-height: 1.15;
            max-height: 8.5mm;
            overflow: hidden;
            width: 100%;
            margin-bottom: 0.5mm;
        }

        .thermal-label .barcode-img {
            max-width: 46mm;
            height: auto;
            max-height: 11mm;
            object-fit: contain;
            image-rendering: crisp-edges;
            margin-bottom: 0;
        }

        .thermal-label .payload {
            font-size: 7pt; /* تكبير الخط قليلاً لسهولة الرؤية */
            font-weight: bold;
            color: #000;
            margin-top: -2px;
            line-height: 1;
        }

        .thermal-label .price {
            font-size: 9pt;
            font-weight: 700;
            margin-top: 1mm;
            color: #000;
        }

        @media print {
            @page { size: 50mm 30mm; margin: 0; }
            html, body { background: #fff !important; }
            .no-print { display: none !important; }
            .label-main { padding: 0 !important; margin: 0 !important; min-height: 0 !important; background: #fff !important; display: block !important; }
            .thermal-label { border: none !important; width: 50mm !important; height: 30mm !important; margin: 0 auto !important; }
        }
    </style>
</head>
<body>
    <header class="screen-toolbar no-print">
        <button type="button" onclick="window.print()">طباعة الملصق</button>
        <span style="opacity:0.85;font-size:0.9rem;">تم تعديل الباركود ليتطابق مع الـ ID المباشر (<?= $payload ?>).</span>
    </header>

    <main class="label-main">
        <div class="thermal-label">
            <div class="product-name"><?= $name ?></div>
            
            <?php if ($barcodeSrc !== ''): ?>
                <img class="barcode-img" src="<?= $barcodeSrc ?>" alt="باركود">
            <?php endif; ?>

            <div class="payload"><?= $payload ?></div>
            
            <div class="price"><?= $priceFmt ?></div>
            
            <?php if (trim((string) $product['barcode']) !== '' && (string)$product['barcode'] !== $payload): ?>
                <div style="font-size: 5pt; color: #444;">SKU: <?= htmlspecialchars($product['barcode']) ?></div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($autoprint): ?>
    <script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
    </script>
    <?php endif; ?>
</body>
</html>