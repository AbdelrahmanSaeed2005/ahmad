<?php
// get_purchase_details.php
require_once '../includes/db_connect.php';

$id = $_GET['id'] ?? 0;

// استعلام لجلب تفاصيل الأصناف مع جلب الباركود من جدول المنتجات عن طريق الاسم
// نستخدم LEFT JOIN لضمان ظهور الصنف حتى لو تم حذف المنتج الأصلي من المخزن
$sql = "SELECT pi.*, p.barcode as original_barcode 
        FROM purchase_items pi 
        LEFT JOIN products p ON pi.product_name = p.name 
        WHERE pi.purchase_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$items = $stmt->fetchAll();

if (!$items) {
    echo '<div class="alert alert-info m-3 text-center">لا توجد تفاصيل لهذه الفاتورة أو رقم الفاتورة غير صحيح.</div>';
    exit;
}

echo '<div class="table-responsive">';
echo '<table class="table table-sm table-striped table-hover mb-0 text-center align-middle">';
echo '<thead class="table-dark">
        <tr>
            <th>الباركود</th>
            <th>اسم الصنف</th>
            <th>الكمية</th>
            <th>التكلفة</th>
            <th>الإجمالي</th>
        </tr>
      </thead>';
echo '<tbody>';

foreach($items as $item) {
    // التحقق من وجود الباركود، إذا لم يتوفر نضع "-"
    $barcode = !empty($item['original_barcode']) ? htmlspecialchars($item['original_barcode']) : '<span class="text-muted">-</span>';
    $name = htmlspecialchars($item['product_name']);
    $qty = $item['quantity'];
    $price = number_format($item['purchase_price'], 2);
    $total = number_format($item['quantity'] * $item['purchase_price'], 2);

    echo "<tr>
            <td>$barcode</td>
            <td class='fw-bold text-start ps-3'>$name</td>
            <td><span class='badge bg-info text-dark'>$qty</span></td>
            <td>$price ج.م</td>
            <td class='fw-bold text-primary'>$total ج.م</td>
          </tr>";
}

echo '</tbody></table></div>';