<?php
// إعدادات الوقت - توحيد كامل على توقيت القاهرة
$appTimezone = 'Africa/Cairo';
date_default_timezone_set($appTimezone);
ini_set('date.timezone', $appTimezone);

// إعدادات قاعدة البيانات
$host = 'localhost';
$db   = 'ahmad';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // مزامنة توقيت جلسة MySQL مع توقيت التطبيق (يدعم التوقيت الصيفي تلقائيا)
    $cairoOffset = (new DateTime('now', new DateTimeZone($appTimezone)))->format('P'); // مثال: +03:00
    $pdo->exec("SET time_zone = '{$cairoOffset}'");
} catch (\PDOException $e) {
    // في بيئة الإنتاج نكتفي برسالة عامة، لكن للتطوير نظهر الخطأ
    die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage());
}

require_once __DIR__ . '/finance_helpers.php';
ensure_finance_schema($pdo);

// بدء الجلسة بإعدادات آمنة
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => false, // اجعلها true إذا كنت تستخدم HTTPS
        'use_strict_mode' => true,
    ]);
}

// دالة الحماية من XSS (Output Escaping)
function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// دالة سجل العمليات (Audit Log)
function log_action($pdo, $user_id, $action, $details = "") {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $details, $_SERVER['REMOTE_ADDR']]);
}
?>