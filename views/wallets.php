<?php 
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';

// تحديث رصيد يدوياً (إيداع/سحب)
if(isset($_POST['adjust_balance'])) {
    $wallet = $_POST['wallet_name'];
    $amount = $_POST['amount'];
    $type = $_POST['adj_type']; // 'add' or 'sub'
    
    if($type == 'add') {
        $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE wallet_name = ?")->execute([$amount, $wallet]);
    } else {
        $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE wallet_name = ?")->execute([$amount, $wallet]);
    }
    $_SESSION['msg'] = "تم تحديث رصيد $wallet";
    header("Location: wallets.php"); exit();
}

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