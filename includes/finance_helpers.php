<?php
/**
 * Schema guard + unified cash_transactions insert (single path, no duplicate helpers).
 */

$GLOBALS['finance_column_cache'] = [];

function finance_invalidate_column_cache(): void {
    $GLOBALS['finance_column_cache'] = [];
}

function finance_table_exists(PDO $pdo, string $table): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $q->execute([$table]);
    return (int) $q->fetchColumn() > 0;
}

function finance_column_exists(PDO $pdo, string $table, string $column): bool {
    $cache = &$GLOBALS['finance_column_cache'];
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        if (finance_table_exists($pdo, $table)) {
            foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[$table][$row['Field']] = true;
            }
        }
    }
    return isset($cache[$table][$column]);
}

function ensure_finance_schema(PDO $pdo): void {
    if (!finance_table_exists($pdo, 'profit_withdrawals')) {
        $pdo->exec("CREATE TABLE profit_withdrawals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            amount DECIMAL(10,2) NOT NULL,
            description VARCHAR(255),
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!finance_table_exists($pdo, 'cash_transactions')) {
        $pdo->exec("CREATE TABLE cash_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('income','expense') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(32) NULL,
            direction ENUM('in','out') NOT NULL DEFAULT 'in',
            source_type VARCHAR(50) NULL,
            source_id INT NULL,
            description VARCHAR(255),
            related_id INT NULL,
            related_type VARCHAR(50) NULL,
            supplier_id INT NULL,
            balance_after DECIMAL(10,2) NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        finance_invalidate_column_cache();
        return;
    }

    $alters = [
        'payment_method' => "ALTER TABLE cash_transactions ADD COLUMN payment_method VARCHAR(32) NULL",
        'supplier_id' => "ALTER TABLE cash_transactions ADD COLUMN supplier_id INT NULL",
        'balance_after' => "ALTER TABLE cash_transactions ADD COLUMN balance_after DECIMAL(10,2) NULL",
        'related_type' => "ALTER TABLE cash_transactions ADD COLUMN related_type VARCHAR(50) NULL",
        'related_id' => "ALTER TABLE cash_transactions ADD COLUMN related_id INT NULL",
        'source_type' => "ALTER TABLE cash_transactions ADD COLUMN source_type VARCHAR(50) NULL",
        'source_id' => "ALTER TABLE cash_transactions ADD COLUMN source_id INT NULL",
        'direction' => "ALTER TABLE cash_transactions ADD COLUMN direction ENUM('in','out') NULL",
        'payment_type' => "ALTER TABLE cash_transactions ADD COLUMN payment_type VARCHAR(32) NULL",
        'is_cleared' => "ALTER TABLE cash_transactions ADD COLUMN is_cleared TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($alters as $col => $sql) {
        if (!finance_column_exists($pdo, 'cash_transactions', $col)) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                if ($e->errorInfo[1] != 1060) {
                    throw $e;
                }
            }
        }
    }

    if (finance_column_exists($pdo, 'cash_transactions', 'direction')) {
        $pdo->exec("UPDATE cash_transactions SET direction = CASE WHEN type = 'expense' THEN 'out' ELSE 'in' END WHERE direction IS NULL");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `wallets` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `wallet_name` varchar(50) NOT NULL,
            `balance` decimal(10,2) DEFAULT 0.00,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `wallet_name` (`wallet_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("INSERT INTO wallets (wallet_name, balance) SELECT 'vodafone', 0 WHERE NOT EXISTS (SELECT 1 FROM wallets WHERE wallet_name = 'vodafone')");
    $pdo->exec("INSERT INTO wallets (wallet_name, balance) SELECT 'bank', 0 WHERE NOT EXISTS (SELECT 1 FROM wallets WHERE wallet_name = 'bank')");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_returns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            invoice_id INT NOT NULL,
            invoice_item_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            refund_method VARCHAR(20) NOT NULL DEFAULT 'adjust_balance',
            cash_transaction_id INT NULL,
            notes VARCHAR(255) NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS supplier_returns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            purchase_id INT NOT NULL,
            purchase_item_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            settlement_method VARCHAR(20) NOT NULL DEFAULT 'adjust_balance',
            cash_transaction_id INT NULL,
            notes VARCHAR(255) NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    finance_invalidate_column_cache();
}

/**
 * @param array $params direction: 'in'|'out', amount (positive), user_id, description,
 *                       payment_method (default cash), related_type/related_id optional,
 *                       source_type/source_id optional (synced to related_* if related_* empty),
 *                       supplier_id optional, balance_after optional (auto if omitted)
 * @return int last insert id
 */
function recordTransaction(PDO $pdo, array $params): int {
    $amount = abs((float) ($params['amount'] ?? 0));
    if ($amount <= 0) {
        throw new InvalidArgumentException('recordTransaction: amount required');
    }
    $dir = strtolower((string) ($params['direction'] ?? 'in'));
    $type = ($dir === 'out') ? 'expense' : 'income';

    $user_id = (int) ($params['user_id'] ?? 0);
    if ($user_id < 1) {
        throw new InvalidArgumentException('recordTransaction: user_id required');
    }

    $description = (string) ($params['description'] ?? '');
    $payment_method = (string) ($params['payment_method'] ?? 'cash');

    $related_type = $params['related_type'] ?? $params['source_type'] ?? null;
    $related_id = isset($params['related_id']) ? (int) $params['related_id'] : (isset($params['source_id']) ? (int) $params['source_id'] : null);
    $source_type = $params['source_type'] ?? $related_type;
    $source_id = isset($params['source_id']) ? (int) $params['source_id'] : ($related_id !== null && $related_id > 0 ? $related_id : null);

    $supplier_id = isset($params['supplier_id']) ? (int) $params['supplier_id'] : null;
    if ($supplier_id !== null && $supplier_id < 1) {
        $supplier_id = null;
    }

    $last = $pdo->query("SELECT balance_after FROM cash_transactions ORDER BY id DESC LIMIT 1")->fetchColumn();
    $last = $last !== false && $last !== null ? (float) $last : 0.0;
    if (array_key_exists('balance_after', $params) && $params['balance_after'] !== null) {
        $new_balance = (float) $params['balance_after'];
    } else {
        $new_balance = $type === 'income' ? $last + $amount : $last - $amount;
    }

    $payment_type = $params['payment_type'] ?? null;

    $base = [
        'type' => $type,
        'amount' => $amount,
        'description' => $description,
        'related_id' => $related_id > 0 ? $related_id : null,
        'related_type' => $related_type,
        'payment_method' => $payment_method,
        'balance_after' => $new_balance,
        'user_id' => $user_id,
        'supplier_id' => $supplier_id,
    ];
    $optional = [
        'source_type' => $source_type,
        'source_id' => $source_id > 0 ? $source_id : null,
        'direction' => $dir === 'out' ? 'out' : 'in',
        'payment_type' => $payment_type,
    ];

    $insert_cols = [];
    $insert_vals = [];
    foreach ($base as $c => $v) {
        if (finance_column_exists($pdo, 'cash_transactions', $c)) {
            $insert_cols[] = $c;
            $insert_vals[] = $v;
        }
    }
    foreach ($optional as $c => $v) {
        if ($v === null && $c === 'payment_type') {
            continue;
        }
        if (finance_column_exists($pdo, 'cash_transactions', $c)) {
            $insert_cols[] = $c;
            $insert_vals[] = $v;
        }
    }

    if (empty($insert_cols)) {
        throw new RuntimeException('recordTransaction: cash_transactions has no recognized columns');
    }

    $ph = implode(', ', array_fill(0, count($insert_cols), '?'));
    $stmt = $pdo->prepare('INSERT INTO cash_transactions (' . implode(', ', $insert_cols) . ") VALUES ($ph)");
    $stmt->execute($insert_vals);
    return (int) $pdo->lastInsertId();
}
