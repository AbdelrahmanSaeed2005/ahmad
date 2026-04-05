<?php
require_once 'includes/db_connect.php';
log_action($pdo, $_SESSION['user_id'], 'logout');
session_unset();
session_destroy();
header("Location: index.php");
exit();