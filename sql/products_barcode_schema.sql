-- ============================================
-- هيكل جدول المنتجات مع barcode_code (مشروع ERP PHP/MySQL)
-- ============================================
-- ملاحظة: المشروع الحالي قد يستخدم العمود `barcode` فقط.
-- يمكن الإبقاء على `barcode` كاسم الحقل، أو إضافة `barcode_code` كما بالأسفل.

-- تثبيت جديد (مثال):
CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    barcode VARCHAR(100) NULL COMMENT 'مرجع / باركود يدوي',
    barcode_code VARCHAR(128) NULL COMMENT 'كود موحّد للمسح — يُفضّل أن يتوافق مع قيمة الباركود المطبوعة (Code 128 من ID)',
    category_id INT UNSIGNED NULL,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    min_selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    stock_quantity INT NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_barcode (barcode),
    UNIQUE KEY uq_products_barcode_code (barcode_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ترقية قاعدة موجودة (إن وُجد عمود barcode فقط):
-- ============================================
-- ALTER TABLE products
--   ADD COLUMN barcode_code VARCHAR(128) NULL AFTER barcode;
--
-- UPDATE products
-- SET barcode_code = LPAD(id, 8, '0')
-- WHERE (barcode_code IS NULL OR barcode_code = '');
--
-- CREATE UNIQUE INDEX uq_products_barcode_code ON products (barcode_code);
