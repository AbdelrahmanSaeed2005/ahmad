<?php
/**
 * توليد باركود Code 128 للمنتجات (يعتمد على معرف المنتج بعد تنسيق رقمي ثابت).
 * يُفضّل PNG عند توفر GD أو Imagick؛ وإلا يُستخدم SVG ولا يحتاج أي امتداد رسومي.
 */

declare(strict_types=1);

$barcodeAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($barcodeAutoload)) {
    require_once $barcodeAutoload;
}

use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * القيمة المرسَمة في الباركود: رقم المنتج بعرض 8 أرقام (مناسب لـ Code 128 وللمسح في POS).
 */
function erp_product_code128_payload(array $product): string
{
    $id = isset($product['id']) ? (int) $product['id'] : 0;
    return str_pad((string) max(0, $id), 8, '0', STR_PAD_LEFT);
}

/**
 * باركود كـ data URI (PNG إن وُجد GD/Imagick، وإلا SVG — يعمل بدون امتدادات على الخادم).
 */
function erp_code128_png_data_uri(string $payload, int $widthFactor = 2, int $height = 46): string
{
    if ($payload === '') {
        return '';
    }

    $type = BarcodeGeneratorSVG::TYPE_CODE_128;

    if (extension_loaded('gd') || extension_loaded('imagick')) {
        try {
            $png = new BarcodeGeneratorPNG();
            $binary = $png->getBarcode($payload, $type, $widthFactor, $height, [0, 0, 0]);
            return 'data:image/png;base64,' . base64_encode($binary);
        } catch (\Throwable $e) {
            // احتياط إذا فشل الرندر رغم الإعلان عن الامتداد
        }
    }

    $svgGen = new BarcodeGeneratorSVG();
    $svg = $svgGen->getBarcode($payload, $type, (float) $widthFactor, (float) $height, 'black');
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
