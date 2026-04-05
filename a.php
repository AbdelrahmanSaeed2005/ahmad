<?php
// a.php - ملف مؤقت لإعداد قاعدة البيانات الأولية (احذفه بعد التشغيل!)
// إعداد الوقت
date_default_timezone_set('Africa/Cairo');

// إعدادات قاعدة البيانات (غيرها حسب خادمك)
$host = 'localhost';
$dbname = 'erp_system_v2';
$username = 'root';
$password = '';

try {
    // الاتصال الأولي بدون تحديد قاعدة البيانات لإنشائها إذا لم تكن موجودة
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // إنشاء قاعدة البيانات إذا لم تكن موجودة
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// دالة لإنشاء الجداول وإدراج البيانات الأولية
function setupDatabase($pdo) {
    // إنشاء الجداول (مع DROP IF EXISTS لإعادة الإنشاء إذا لزم)
    $pdo->exec("DROP TABLE IF EXISTS profit_withdrawals, audit_logs, loan_requests, cash_transactions, expenses, returns, sale_items, sales, products, suppliers, customers, categories, users, role_permissions, permissions, roles");

    // جدول الأدوار
    $pdo->exec("CREATE TABLE roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) UNIQUE NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // جدول الصلاحيات
    $pdo->exec("CREATE TABLE permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) UNIQUE NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // جدول ربط الأدوار بالصلاحيات
    $pdo->exec("CREATE TABLE role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        permission_id INT NOT NULL,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
        UNIQUE(role_id, permission_id)
    )");

    // جدول الفئات
    $pdo->exec("CREATE TABLE categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL
    )");

    // جدول العملاء
    $pdo->exec("CREATE TABLE customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        address VARCHAR(255),
        balance DECIMAL(10,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL
    )");

    // جدول الموردين
    $pdo->exec("CREATE TABLE suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        address VARCHAR(255),
        balance DECIMAL(10,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL
    )");

    // جدول المستخدمين
    $pdo->exec("CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100) UNIQUE,
        full_name VARCHAR(100),
        role_id INT NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    )");

    // جدول المنتجات
    $pdo->exec("CREATE TABLE products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        barcode VARCHAR(50) UNIQUE,
        category_id INT,
        cost_price DECIMAL(10,2) NOT NULL,
        selling_price DECIMAL(10,2) NOT NULL,
        min_selling_price DECIMAL(10,2) NOT NULL,
        stock_quantity INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
    )");

    // جدول المبيعات
    $pdo->exec("CREATE TABLE sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(50) UNIQUE NOT NULL,
        user_id INT NOT NULL,
        customer_id INT,
        total_amount DECIMAL(10,2) NOT NULL,
        payment_method ENUM('cash', 'credit', 'vodafone', 'bank') NOT NULL,
        status ENUM('completed', 'pending') DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
    )");

    // جدول تفاصيل المبيعات
    $pdo->exec("CREATE TABLE sale_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        total DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id)
    )");

    // جدول المرتجعات
    $pdo->exec("CREATE TABLE returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        return_price DECIMAL(10,2) NOT NULL,
        reason VARCHAR(255),
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // جدول المصاريف
    $pdo->exec("CREATE TABLE expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        category VARCHAR(50),
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // جدول الحركات النقدية
    $pdo->exec("CREATE TABLE cash_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('income', 'expense') NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        description VARCHAR(255),
        related_id INT,
        related_type VARCHAR(50),
        balance_after DECIMAL(10,2),
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // جدول طلبات السلف
    $pdo->exec("CREATE TABLE loan_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        reason VARCHAR(255),
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        approved_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
    )");

    // جدول سجل التدقيق
    $pdo->exec("CREATE TABLE audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(255) NOT NULL,
        details TEXT,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    // جدول سحب الأرباح
    $pdo->exec("CREATE TABLE profit_withdrawals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        amount DECIMAL(10,2) NOT NULL,
        description VARCHAR(255),
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // إدراج البيانات الأولية
    // الأدوار
    $pdo->exec("INSERT INTO roles (name, description) VALUES ('admin', 'Administrator'), ('employee', 'Employee')");

    // الصلاحيات
    $pdo->exec("INSERT INTO permissions (name, description) VALUES 
        ('view_dashboard', 'View Dashboard'),
        ('manage_products', 'Manage Products'),
        ('manage_sales', 'Manage Sales'),
        ('manage_users', 'Manage Users'),
        ('view_reports', 'View Reports')");

    // ربط الصلاحيات
    $pdo->exec("INSERT INTO role_permissions (role_id, permission_id) VALUES 
        (1, 1), (1, 2), (1, 3), (1, 4), (1, 5), -- Admin
        (2, 1), (2, 3)"); 

    // المستخدمون مع كلمات مرور مشفرة
    $adminPassword = password_hash('111657', PASSWORD_DEFAULT);
    $employeePassword = password_hash('222222', PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role_id, is_active) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['admin', $adminPassword, 'Admin User', 1, 1]);
    $stmt->execute(['abdo2', $employeePassword, 'Abdo Employee', 2, 1]);

    // فئات تجريبية
    $pdo->exec("INSERT INTO categories (name, description) VALUES 
        ('Electronics', 'Electronic products'),
        ('Clothing', 'Clothing items')");

    // عملاء تجريبيون
    $pdo->exec("INSERT INTO customers (name, phone, address, balance) VALUES 
        ('Customer 1', '0123456789', 'Address 1', 0),
        ('Customer 2', '0987654321', 'Address 2', 100)");

    // منتجات تجريبية
    $pdo->exec("INSERT INTO products (name, barcode, category_id, cost_price, selling_price, min_selling_price, stock_quantity) VALUES 
        ('Product 1', '123456', 1, 50.00, 100.00, 60.00, 10),
        ('Product 2', '654321', 2, 20.00, 50.00, 25.00, 5)");

    echo "Database and tables created successfully! Users created:<br>";
    echo "- Admin: admin / 111657<br>";
    echo "- Employee: abdo2 / 222222<br>";
    echo "<strong>Delete this file (a.php) immediately for security!</strong>";
}

// تشغيل الإعداد
setupDatabase($pdo);
?>