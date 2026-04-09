<?php
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT i.id, i.created_at, i.total_amount, i.payment_method,
                              i.customer_id, i.walkin_customer_name, i.walkin_customer_phone,
                              u.username,
                              c.name AS registered_customer_name,
                              COALESCE(c.phone_number, c.phone) AS registered_customer_phone
                       FROM invoices i
                       LEFT JOIN customers c ON i.customer_id = c.id
                       LEFT JOIN users u ON i.user_id = u.id
                       WHERE i.id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die('الفاتورة غير موجودة');
}

$customerName = !empty($invoice['customer_id'])
    ? ($invoice['registered_customer_name'] ?? 'عميل مسجل')
    : (!empty($invoice['walkin_customer_name']) ? $invoice['walkin_customer_name'] : 'عميل نقدي');

$customerPhone = !empty($invoice['customer_id'])
    ? ($invoice['registered_customer_phone'] ?? '')
    : ($invoice['walkin_customer_phone'] ?? '');

$stmtItems = $pdo->prepare("SELECT p.name AS product_name, it.quantity, it.price
                            FROM invoice_items it
                            JOIN products p ON it.product_id = p.id
                            WHERE it.invoice_id = ?");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$paymentLabels = [
    'cash' => 'نقدي',
    'bank' => 'بنكي',
    'credit' => 'Credit',
    'vodafone' => 'فودافون'
];
$paymentMethod = $paymentLabels[$invoice['payment_method']] ?? $invoice['payment_method'];
$invoiceDateTimeForShare = date('Y/m/d - h:i A', strtotime($invoice['created_at']));
$itemsForShare = [];
foreach ($items as $item) {
    $qty = (float)$item['quantity'];
    $unitPrice = (float)$item['price'];
    $itemsForShare[] = [
        'name' => $item['product_name'],
        'quantity' => (int)$qty,
        'unit_price' => number_format($unitPrice, 2) . ' ج.م',
        'line_total' => number_format($unitPrice * $qty, 2) . ' ج.م',
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة ERP #<?= (int)$invoice['id'] ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-start: #050711;
            --bg-end: #0e1a38;
            --surface: rgba(15, 23, 42, 0.92);
            --surface-2: rgba(30, 41, 59, 0.88);
            --line: rgba(148, 163, 184, 0.25);
            --text-main: #e2e8f0;
            --text-soft: #94a3b8;
            --electric-blue: #38bdf8;
            --crimson: #e11d48;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Cairo', sans-serif;
            color: var(--text-main);
            background: linear-gradient(130deg, var(--bg-start), var(--bg-end));
            padding: 22px;
        }

        .invoice-wrap {
            max-width: 920px;
            margin: 0 auto;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
            margin-bottom: 18px;
        }

        .btn {
            border: 0;
            border-radius: 12px;
            padding: 10px 16px;
            font-family: inherit;
            font-weight: 700;
            cursor: pointer;
            color: var(--white);
        }

        .btn-print { background: #2563eb; }
        .btn-shot { background: #0891b2; }
        .btn-wa { background: #16a34a; }

        .invoice-card {
            background: linear-gradient(180deg, var(--surface), var(--surface-2));
            border: 1px solid var(--line);
            border-radius: 22px;
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.45);
            overflow: hidden;
        }

        .invoice-header {
            padding: 24px 24px 18px;
            border-bottom: 1px solid var(--line);
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: center;
        }

        .logo-box {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(145deg, #0ea5e9, #1d4ed8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 26px;
            font-weight: 800;
        }

        .company-meta h1 {
            margin: 0 0 4px;
            font-size: 24px;
            color: var(--white);
        }

        .company-meta p {
            margin: 0;
            color: var(--text-soft);
            font-size: 14px;
        }

        .invoice-id {
            text-align: left;
            font-weight: 800;
            color: var(--electric-blue);
            font-size: 20px;
        }

        .invoice-body {
            padding: 20px 24px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 16px;
            margin-bottom: 18px;
        }

        .meta-item {
            background: rgba(2, 6, 23, 0.4);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
        }

        .meta-item .label {
            color: var(--text-soft);
            font-size: 12px;
        }

        .meta-item .value {
            color: var(--white);
            font-size: 15px;
            font-weight: 700;
            margin-top: 4px;
        }

        .items-table-wrap {
            border: 1px solid var(--line);
            border-radius: 14px;
            overflow: hidden;
            margin-top: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 10px;
            text-align: center;
        }

        th {
            background: rgba(15, 23, 42, 0.95);
            color: var(--electric-blue);
            font-size: 13px;
        }

        tr:not(:last-child) td {
            border-bottom: 1px solid var(--line);
        }

        .text-right {
            text-align: right;
        }

        .total-box {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid rgba(225, 29, 72, 0.45);
            background: rgba(225, 29, 72, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .total-box .amount {
            color: var(--crimson);
            font-size: 22px;
            font-weight: 800;
        }

        .invoice-footer {
            border-top: 1px solid var(--line);
            padding: 16px 24px 22px;
            text-align: center;
            color: var(--text-soft);
        }

        .invoice-footer strong {
            color: var(--white);
        }

        @media (max-width: 680px) {
            .invoice-header {
                grid-template-columns: 1fr;
            }
            .invoice-id {
                text-align: right;
            }
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            @page { size: A4; margin: 10mm; }
            body {
                padding: 0;
                background: #fff;
                color: #000;
            }
            .actions {
                display: none !important;
            }
            .invoice-card {
                box-shadow: none;
                border: 1px solid #ddd;
                background: #fff;
                color: #111;
            }
            th { background: #f4f4f4; color: #222; }
            .meta-item, .items-table-wrap {
                border-color: #ddd;
                background: #fff;
            }
            .meta-item .label { color: #666; }
            .meta-item .value, .company-meta h1, .invoice-footer strong { color: #111; }
            .company-meta p, .invoice-footer { color: #666; }
            .total-box {
                border-color: #dc2626;
                background: #fff5f5;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-wrap">
        <div class="actions no-print">
            <button type="button" class="btn btn-print" onclick="window.print()">طباعة</button>
            <button type="button" class="btn btn-shot" onclick="captureInvoice()">التقاط صورة PNG</button>
            <button type="button" class="btn btn-wa" onclick="shareInvoiceWhatsApp()">مشاركة واتساب</button>
        </div>

        <div class="invoice-card" id="invoiceCard">
            <div class="invoice-header">
                <div style="display:flex; gap:12px; align-items:center;">
                    <div class="logo-box">ERP</div>
                    <div class="company-meta">
                        <h1>فاتورة ARKAN </h1>
                        <p>القاهرة - مصر | 01110275747 </p>
                    </div>
                </div>
                <div class="invoice-id">#<?= (int)$invoice['id'] ?></div>
            </div>

            <div class="invoice-body">
                <div class="grid">
                    <div class="meta-item">
                        <div class="label">التاريخ والوقت</div>
                        <div class="value"><?= htmlspecialchars(date('Y/m/d - h:i A', strtotime($invoice['created_at']))) ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="label">اسم العميل</div>
                        <div class="value"><?= htmlspecialchars($customerName) ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="label">رقم الهاتف</div>
                        <div class="value"><?= !empty($customerPhone) ? htmlspecialchars($customerPhone) : '--' ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="label">الموظف المسؤول</div>
                        <div class="value"><?= htmlspecialchars($invoice['username'] ?? '-') ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="label">طريقة الدفع</div>
                        <div class="value"><?= htmlspecialchars($paymentMethod) ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="label">عدد الأصناف</div>
                        <div class="value"><?= count($items) ?></div>
                    </div>
                </div>

                <div class="items-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="text-right">الصنف</th>
                                <th>الكمية</th>
                                <th>سعر الوحدة</th>
                                <th>الإجمالي الفرعي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($items)): ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="text-right"><?= htmlspecialchars($item['product_name']) ?></td>
                                        <td><?= (int)$item['quantity'] ?></td>
                                        <td><?= number_format((float)$item['price'], 2) ?> ج.م</td>
                                        <td><?= number_format((float)$item['price'] * (float)$item['quantity'], 2) ?> ج.م</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4">لا توجد أصناف داخل هذه الفاتورة</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="total-box">
                    <span>إجمالي المبلغ</span>
                    <span class="amount"><?= number_format((float)$invoice['total_amount'], 2) ?> ج.م</span>
                </div>
            </div>

            <div class="invoice-footer">
                <!-- <div><strong>شكراً لزيارتكم</strong></div> -->
                <div> Arkan | اركان للحلول البرمجية❤️</div>
                <div><a href="https://wa.me/201099319899">01099319899</a></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script>
        const invoiceId = <?= (int)$invoice['id'] ?>;
        const rawPhone = "<?= htmlspecialchars((string)$customerPhone, ENT_QUOTES, 'UTF-8') ?>";
        const shareDateTime = "<?= htmlspecialchars($invoiceDateTimeForShare, ENT_QUOTES, 'UTF-8') ?>";
        const customerName = "<?= htmlspecialchars((string)$customerName, ENT_QUOTES, 'UTF-8') ?>";
        const employeeName = "<?= htmlspecialchars((string)($invoice['username'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>";
        const paymentMethodLabel = "<?= htmlspecialchars((string)$paymentMethod, ENT_QUOTES, 'UTF-8') ?>";
        const itemsCount = <?= (int)count($items) ?>;
        const totalAmountLabel = "<?= number_format((float)$invoice['total_amount'], 2) ?> ج.م";
        const shareItems = <?= json_encode($itemsForShare, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        function normalizeEgyptPhone(phone) {
            if (!phone) return '';
            let clean = phone.replace(/[^0-9]/g, '');
            if (clean.startsWith('0')) {
                clean = '20' + clean.substring(1);
            } else if (!clean.startsWith('20') && clean.length >= 10) {
                clean = '20' + clean;
            }
            return clean;
        }

        function invoiceTextMessage() {
            const lines = [
                'التاريخ والوقت',
                shareDateTime,
                'اسم العميل',
                customerName || '-',
                'رقم الهاتف',
                rawPhone || '-',
                'الموظف المسؤول',
                employeeName || '-',
                'طريقة الدفع',
                paymentMethodLabel || '-',
                'عدد الأصناف',
                String(itemsCount),
                'الصنف\tالكمية\tسعر الوحدة\tالإجمالي الفرعي'
            ];

            if (Array.isArray(shareItems) && shareItems.length > 0) {
                shareItems.forEach((item) => {
                    lines.push(
                        `${item.name}\t${item.quantity}\t${item.unit_price}\t${item.line_total}`
                    );
                });
            }

            lines.push(
                'إجمالي المبلغ',
                totalAmountLabel,
                'شكراً لزيارتكم',
                'برمجة بواسطة: Arkan | اركان للحلول البرمجية❤️'
            );

            return lines.join('\n');
        }

        function shareInvoiceWhatsApp() {
            const waPhone = normalizeEgyptPhone(rawPhone);
            if (!waPhone) {
                alert('لا يوجد رقم هاتف للعميل في الفاتورة.');
                return;
            }
            const txt = encodeURIComponent(invoiceTextMessage());
            window.open('https://wa.me/' + waPhone + '?text=' + txt, '_blank');
        }

        async function captureInvoice() {
            const target = document.getElementById('invoiceCard');
            html2canvas(target, {scale: 2, backgroundColor: null}).then(async (canvas) => {
                const waPhone = normalizeEgyptPhone(rawPhone);
                const dataUrl = canvas.toDataURL('image/png');
                const blob = await (await fetch(dataUrl)).blob();
                const fileName = 'invoice-' + invoiceId + '.png';

                // أفضل تجربة مدعومة: مشاركة ملف الصورة مباشرة (على الأجهزة التي تدعم Web Share API)
                if (navigator.canShare && navigator.share) {
                    const imageFile = new File([blob], fileName, { type: 'image/png' });
                    if (navigator.canShare({ files: [imageFile] })) {
                        try {
                            await navigator.share({
                                files: [imageFile],
                                title: 'فاتورة رقم #' + invoiceId,
                                text: invoiceTextMessage()
                            });
                            return;
                        } catch (e) {
                            // يكمل fallback إذا المستخدم أغلق نافذة المشاركة
                        }
                    }
                }

                const a = document.createElement('a');
                a.href = dataUrl;
                a.download = fileName;
                document.body.appendChild(a);
                a.click();
                a.remove();

                if (waPhone) {
                    const autoMessage = encodeURIComponent(
                        invoiceTextMessage() + '\n\n(تم حفظ صورة الفاتورة تلقائياً، أرفقها ثم أرسل)'
                    );
                    window.open('https://wa.me/' + waPhone + '?text=' + autoMessage, '_blank');
                }
            }).catch(() => {
                alert('فشل التقاط صورة الفاتورة.');
            });
        }
    </script>
</body>
</html>