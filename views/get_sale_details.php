<?php
/* File Path: http://localhost/project/ERP_System_V2/views/get_sale_details.php
Description: جلب تفاصيل أصناف فاتورة مبيعات معينة - تم تصحيح أسماء الأعمدة
*/
require_once '../includes/db_connect.php';

$sale_id = $_GET['id'] ?? 0;

if ($sale_id > 0) {
    try {
        // قمت بتغيير p.product_name إلى p.name (تأكد هل اسم العمود في جدول products هو name أم شيء آخر)
        // إذا كان اسم العمود عندك هو product_name فعلاً، تأكد من عدم وجود مسافات خفية
        $stmt = $pdo->prepare("
            SELECT si.*, p.name as display_name 
            FROM sale_items si 
            JOIN products p ON si.product_id = p.id 
            WHERE si.sale_id = ?
        ");
        $stmt->execute([$sale_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($items) {
            echo '<div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="bg-light text-center">
                            <tr>
                                <th>الصنف</th>
                                <th>الكمية</th>
                                <th>سعر الوحدة</th>
                                <th>الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody class="text-center">';
            
            $grand_total = 0;
            foreach ($items as $item) {
                $row_total = $item['quantity'] * $item['unit_price'];
                $grand_total += $row_total;
                echo '<tr>
                        <td class="text-start ps-3">' . htmlspecialchars($item['display_name']) . '</td>
                        <td>' . $item['quantity'] . '</td>
                        <td>' . number_format($item['unit_price'], 2) . ' ج.م</td>
                        <td class="fw-bold">' . number_format($row_total, 2) . ' ج.م</td>
                      </tr>';
            }
            
            echo '</tbody>
                    <tfoot class="bg-light fw-bold text-center">
                        <tr>
                            <td colspan="3" class="text-end">إجمالي الفاتورة:</td>
                            <td class="text-primary">' . number_format($grand_total, 2) . ' ج.م</td>
                        </tr>
                    </tfoot>
                  </table>
                </div>';
        } else {
            echo '<div class="p-4 text-center text-muted">لم يتم العثور على أصناف لهذه الفاتورة.</div>';
        }
    } catch (PDOException $e) {
        // في حال استمر الخطأ، سيطبع لك أسماء الأعمدة الموجودة في جدول المنتجات لتصحيحها
        echo '<div class="p-4 text-center text-danger small">خطأ في قاعدة البيانات: ' . $e->getMessage() . '</div>';
    }
} else {
    echo '<div class="p-4 text-center text-danger">معرف فاتورة غير صالح.</div>';
}
?>