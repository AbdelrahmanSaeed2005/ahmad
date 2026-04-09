<?php 
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// إيداع/سحب — نفس مسار لوحة الإدارة (cash_transactions + مزامنة المحافظ)
if (isset($_POST['adjust_balance'])) {
    $channel = trim((string) ($_POST['wallet_name'] ?? ''));
    $amount = (float) ($_POST['amount'] ?? 0);
    $adjType = $_POST['adj_type'] ?? '';

    if (!in_array($channel, ['vodafone', 'bank'], true) || $amount <= 0 || !in_array($adjType, ['add', 'sub'], true)) {
        $_SESSION['msg'] = 'بيانات غير صالحة';
        header('Location: wallets.php');
        exit();
    }

    $balStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0)
         FROM cash_transactions WHERE payment_method = ?"
    );
    $balStmt->execute([$channel]);
    $currentBalance = (float) $balStmt->fetchColumn();

    if ($adjType === 'sub' && $amount > $currentBalance + 0.0001) {
        $_SESSION['msg'] = 'الرصيد غير كافٍ للسحب';
        header('Location: wallets.php');
        exit();
    }

    $label = $channel === 'vodafone' ? 'فودافون كاش' : 'البنك';
    try {
        if ($adjType === 'add') {
            recordTransaction($pdo, [
                'direction' => 'in',
                'amount' => $amount,
                'payment_method' => $channel,
                'description' => 'إيداع يدوي — ' . $label,
                'user_id' => (int) $_SESSION['user_id'],
                'related_type' => 'wallet_adjustment',
            ]);
        } else {
            recordTransaction($pdo, [
                'direction' => 'out',
                'amount' => $amount,
                'payment_method' => $channel,
                'description' => 'سحب يدوي — ' . $label,
                'user_id' => (int) $_SESSION['user_id'],
                'related_type' => 'wallet_adjustment',
            ]);
        }
        $pdo->exec(
            "UPDATE wallets w SET balance = (
                SELECT COALESCE(SUM(CASE WHEN ct.type = 'income' THEN ct.amount ELSE -ct.amount END), 0)
                FROM cash_transactions ct WHERE ct.payment_method = 'vodafone'
            ) WHERE w.wallet_name = 'vodafone'"
        );
        $pdo->exec(
            "UPDATE wallets w SET balance = (
                SELECT COALESCE(SUM(CASE WHEN ct.type = 'income' THEN ct.amount ELSE -ct.amount END), 0)
                FROM cash_transactions ct WHERE ct.payment_method = 'bank'
            ) WHERE w.wallet_name = 'bank'"
        );
        $_SESSION['msg'] = 'تم التسجيل في حركة النقدية والتقارير';
    } catch (Throwable $e) {
        $_SESSION['msg'] = 'تعذر التنفيذ';
    }
    header('Location: wallets.php');
    exit();
}

$pdo->exec(
    "UPDATE wallets w SET balance = (
        SELECT COALESCE(SUM(CASE WHEN ct.type = 'income' THEN ct.amount ELSE -ct.amount END), 0)
        FROM cash_transactions ct WHERE ct.payment_method = 'vodafone'
    ) WHERE w.wallet_name = 'vodafone'"
);
$pdo->exec(
    "UPDATE wallets w SET balance = (
        SELECT COALESCE(SUM(CASE WHEN ct.type = 'income' THEN ct.amount ELSE -ct.amount END), 0)
        FROM cash_transactions ct WHERE ct.payment_method = 'bank'
    ) WHERE w.wallet_name = 'bank'"
);

$wallets = $pdo->query("SELECT * FROM wallets")->fetchAll();
require_once '../includes/header.php';
?>

<div class="container mt-4">
    <div class="row">
        <?php foreach($wallets as $w): ?>
        <div class="col-md-6 mb-3">
            <div class="card shadow border-0 p-4 <?= $w['wallet_name'] == 'vodafone' ? 'bg-danger text-white' : 'bg-primary text-white' ?>">
                <h3><?= strtoupper($w['wallet_name']) ?></h3>
                <h1 class="display-4 fw-bold"><?= number_format($w['balance'], 2) ?> ج.م</h1>
                <button class="btn btn-light mt-2" data-bs-toggle="modal" data-bs-target="#adjModal<?= $w['id'] ?>">تعديل الرصيد</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>