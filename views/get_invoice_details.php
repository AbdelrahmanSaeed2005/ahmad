<?php
require_once '../includes/db_connect.php';
$id = $_GET['id'];

$stmt = $pdo->prepare("SELECT it.*, p.name FROM invoice_items it 
                       JOIN products p ON it.product_id = p.id 
                       WHERE it.invoice_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
?>

<table class="table mb-0 text-center">
    <thead class="table-dark">
        <tr>
            <th>المنتج</th>
            <th>الكمية</th>
            <th>سعر الوحدة</th>
            <th>الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($items as $item): ?>
        <tr>
            <td><?= $item['name'] ?></td>
            <td><?= $item['quantity'] ?></td>
            <td><?= number_format($item['price'], 2) ?></td>
            <td class="fw-bold"><?= number_format($item['price'] * $item['quantity'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>