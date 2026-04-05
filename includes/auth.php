<?php
require_once 'db_connect.php';

// التحقق من تسجيل الدخول
function check_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php");
        exit();
    }
}

// التحقق من الصلاحية (RBAC)
function has_permission($pdo, $permission_name) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.id
        WHERE rp.role_id = ? AND p.name = ?
    ");
    $stmt->execute([$_SESSION['role_id'], $permission_name]);
    return $stmt->fetchColumn() > 0;
}

// توليد توكن CSRF لحماية الفورم
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>