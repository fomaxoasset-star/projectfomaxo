<?php
// Database tables. Works on MySQL (Hostinger) and SQLite (local testing).

function schema_sql($mysql)
{
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $dt = $mysql ? 'DATETIME' : 'TEXT';

    return [
        "CREATE TABLE IF NOT EXISTS users (
            id $id,
            name VARCHAR(100) NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            pass_hash VARCHAR(255) NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'staff',
            active TINYINT NOT NULL DEFAULT 1,
            created_at $dt NOT NULL
        )$end",

        "CREATE TABLE IF NOT EXISTS tokens (
            id $id,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at $dt NOT NULL,
            created_at $dt NOT NULL,
            last_used $dt NULL
        )$end",

        "CREATE TABLE IF NOT EXISTS login_attempts (
            id $id,
            ip VARCHAR(45) NOT NULL,
            created_at $dt NOT NULL
        )$end",

        "CREATE TABLE IF NOT EXISTS products (
            id $id,
            name VARCHAR(120) NOT NULL,
            variant VARCHAR(60) NOT NULL DEFAULT '',
            sku VARCHAR(64) NOT NULL DEFAULT '',
            category VARCHAR(60) NOT NULL DEFAULT '',
            price INT NOT NULL DEFAULT 0,
            stock INT NOT NULL DEFAULT 0,
            low_at INT NOT NULL DEFAULT 5,
            active TINYINT NOT NULL DEFAULT 1,
            created_at $dt NOT NULL,
            updated_at $dt NOT NULL
        )$end",

        "CREATE TABLE IF NOT EXISTS customers (
            id $id,
            phone VARCHAR(30) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL DEFAULT '',
            created_at $dt NOT NULL
        )$end",

        "CREATE TABLE IF NOT EXISTS sales (
            id $id,
            uuid CHAR(36) NOT NULL UNIQUE,
            receipt_no VARCHAR(20) NOT NULL,
            receipt_token CHAR(32) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            customer_id INT NULL,
            subtotal INT NOT NULL,
            discount INT NOT NULL DEFAULT 0,
            vat INT NOT NULL DEFAULT 0,
            total INT NOT NULL,
            method VARCHAR(10) NOT NULL,
            status VARCHAR(12) NOT NULL,
            ziina_id VARCHAR(64) NULL,
            payment_ref VARCHAR(64) NOT NULL DEFAULT '',
            cash_given INT NULL,
            change_due INT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            offline TINYINT NOT NULL DEFAULT 0,
            created_at $dt NOT NULL,
            paid_at $dt NULL
        )$end",

        "CREATE TABLE IF NOT EXISTS sale_items (
            id $id,
            sale_id INT NOT NULL,
            product_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            variant VARCHAR(60) NOT NULL DEFAULT '',
            price INT NOT NULL,
            qty INT NOT NULL,
            line_total INT NOT NULL
        )$end",

        "CREATE TABLE IF NOT EXISTS stock_moves (
            id $id,
            product_id INT NOT NULL,
            user_id INT NOT NULL,
            qty_change INT NOT NULL,
            stock_after INT NOT NULL,
            reason VARCHAR(30) NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            sale_id INT NULL,
            created_at $dt NOT NULL
        )$end",

        "CREATE INDEX idx_sales_created ON sales (created_at)",
        // (multi-store tables are added by migrate() below)
        "CREATE INDEX idx_sales_status ON sales (status)",
        "CREATE INDEX idx_items_sale ON sale_items (sale_id)",
        "CREATE INDEX idx_moves_product ON stock_moves (product_id)",
        "CREATE INDEX idx_attempts_ip ON login_attempts (ip)",
    ];
}

const SCHEMA_VERSION = 12;

function schema_version()
{
    try {
        $r = q1("SELECT v FROM meta WHERE k = 'schema'");
        return $r ? (int) $r['v'] : 1;
    } catch (Throwable $e) {
        return 1;
    }
}

/** Upgrades an existing database to the newest layout. Safe to call on every request. */
function migrate()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (schema_version() >= SCHEMA_VERSION || !is_installed()) {
        return;
    }
    $mysql = is_mysql();
    if ($mysql) {
        q("SELECT GET_LOCK('fomaxo_pos_migrate', 20)");
    }
    try {
        if (schema_version() < 2) {
            migrate_v2($mysql);
        }
        if (schema_version() < 3) {
            migrate_v3();
        }
        if (schema_version() < 7) {
            try_exec("ALTER TABLE customers ADD COLUMN email VARCHAR(120) NOT NULL DEFAULT ''");
        }
        if (schema_version() < 6) {
            try_exec('ALTER TABLE products ADD COLUMN price_lock TINYINT NOT NULL DEFAULT 0');
            try_exec("ALTER TABLE products ADD COLUMN image VARCHAR(160) NOT NULL DEFAULT ''");
            try_exec("ALTER TABLE sales ADD COLUMN coupon VARCHAR(30) NOT NULL DEFAULT ''");
            try_exec("ALTER TABLE sales ADD COLUMN coupon_label VARCHAR(80) NOT NULL DEFAULT ''");
            try_exec('ALTER TABLE sales ADD COLUMN coupon_disc INT NOT NULL DEFAULT 0');
            try_exec('ALTER TABLE sale_items ADD COLUMN free TINYINT NOT NULL DEFAULT 0');
            $mysql6 = is_mysql();
            $id6 = $mysql6 ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
            $end6 = $mysql6 ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
            $dt6 = $mysql6 ? 'DATETIME' : 'TEXT';
            db()->exec("CREATE TABLE IF NOT EXISTS coupons (
                id $id6,
                code VARCHAR(30) NOT NULL UNIQUE,
                kind VARCHAR(5) NOT NULL DEFAULT 'pct',
                amount INT NOT NULL DEFAULT 0,
                min_order INT NOT NULL DEFAULT 0,
                free_product_id INT NULL,
                ends $dt6 NULL,
                max_uses INT NULL,
                store_id INT NULL,
                active TINYINT NOT NULL DEFAULT 1,
                note VARCHAR(120) NOT NULL DEFAULT '',
                created_at $dt6 NOT NULL
            )$end6");
            if (schema_version() < 5) {
                q("DELETE FROM meta WHERE k = 'web_sync_at'");
            }
            q("DELETE FROM meta WHERE k = 'schema'");
            q("INSERT INTO meta (k, v) VALUES ('schema', '6')");
        }
        if (schema_version() < 7) {
            q("DELETE FROM meta WHERE k = 'schema'");
            q("INSERT INTO meta (k, v) VALUES ('schema', '7')");
        }
        if (schema_version() < 8) {
            foreach (q('SELECT id, name, username FROM users')->fetchAll() as $u8) {
                q('UPDATE users SET name = ?, username = ? WHERE id = ?', [name_case($u8['name']), username_case($u8['username']), $u8['id']]);
            }
            q("DELETE FROM meta WHERE k = 'schema'");
            q("INSERT INTO meta (k, v) VALUES ('schema', '8')");
        }
        if (schema_version() < 9) {
            foreach (q('SELECT id, name, username FROM users')->fetchAll() as $u9) {
                q('UPDATE users SET name = ?, username = ? WHERE id = ?', [name_case($u9['name']), username_case($u9['username']), $u9['id']]);
            }
            q("DELETE FROM meta WHERE k = 'schema'");
            q("INSERT INTO meta (k, v) VALUES ('schema', '9')");
        }
        if (schema_version() < 10) {
            try_exec('ALTER TABLE users ADD COLUMN removed TINYINT NOT NULL DEFAULT 0');
            q("DELETE FROM meta WHERE k = 'schema'");
            q("INSERT INTO meta (k, v) VALUES ('schema', '10')");
        }
        if (schema_version() < 11) {
            try_exec("ALTER TABLE stores ADD COLUMN code VARCHAR(8) NOT NULL DEFAULT ''");
            try_exec('ALTER TABLE stores ADD COLUMN next_no INT NOT NULL DEFAULT 10000');
            $used = [];
            foreach (q('SELECT id, name FROM stores ORDER BY id')->fetchAll() as $st) {
                $base = store_code_from_name($st['name']);
                $code = $base;
                for ($n = 2; isset($used[$code]); $n++) {
                    $code = substr($base, 0, 4) . $n;
                }
                $used[$code] = 1;
                q('UPDATE stores SET code = ? WHERE id = ?', [$code, $st['id']]);
            }
            q("DELETE FROM meta WHERE k = 'schema'");
            q("INSERT INTO meta (k, v) VALUES ('schema', '11')");
        }
        if (schema_version() < 12) {
            // One-time fresh start (owner's request, 22 Oct 2026): remove all orders made so far, plus
            // customers, activity and stock history. Stock levels stay as they are. Order numbers restart at 10000.
            q('DELETE FROM sale_items');
            q('DELETE FROM sales');
            q('DELETE FROM stock_moves');
            q('DELETE FROM activity');
            q('DELETE FROM customers');
            q('UPDATE stores SET next_no = 10000');
            q("DELETE FROM meta WHERE k = 'schema'");
            q("INSERT INTO meta (k, v) VALUES ('schema', '12')");
        }
        if (schema_version() < 5) {
            try_exec('ALTER TABLE products ADD COLUMN price_lock TINYINT NOT NULL DEFAULT 0');
            try_exec("ALTER TABLE products ADD COLUMN image VARCHAR(160) NOT NULL DEFAULT ''");
            q("DELETE FROM meta WHERE k = 'schema'");
            q("INSERT INTO meta (k, v) VALUES ('schema', '5')");
            q("DELETE FROM meta WHERE k = 'web_sync_at'"); // pick up product photos on the next sync
        }
        if (schema_version() < 4) {
            try_exec('ALTER TABLE products ADD COLUMN price_lock TINYINT NOT NULL DEFAULT 0');
            q("DELETE FROM meta WHERE k = 'schema'");
            q("INSERT INTO meta (k, v) VALUES ('schema', '4')");
        }
        // First time: bring in the real product list from fomaxo.com (only if nothing was set up by hand).
        // The Sync button on Stock (and the 10-minute auto sync) do it any time later.
        if (!q1("SELECT id FROM products WHERE sku <> '' LIMIT 1")) {
            require_once __DIR__ . '/webimport.php';
            try {
                import_web_products();
            } catch (Throwable $e) {
                error_log('FOMAXO POS: first product import skipped: ' . $e->getMessage());
            }
        }
    } finally {
        if ($mysql) {
            q("SELECT RELEASE_LOCK('fomaxo_pos_migrate')");
        }
    }
}

function try_exec($sql)
{
    try {
        db()->exec($sql);
    } catch (PDOException $e) {
        // already there (column / index / table) - fine
    }
}

// Version 2: several stores, each with its own stock.
function migrate_v2($mysql)
{
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $dt = $mysql ? 'DATETIME' : 'TEXT';
    db()->exec("CREATE TABLE IF NOT EXISTS meta (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL)$end");
    db()->exec("CREATE TABLE IF NOT EXISTS stores (
        id $id,
        name VARCHAR(80) NOT NULL,
        address VARCHAR(255) NOT NULL DEFAULT '',
        phone VARCHAR(40) NOT NULL DEFAULT '',
        active TINYINT NOT NULL DEFAULT 1,
        created_at $dt NOT NULL
    )$end");
    db()->exec("CREATE TABLE IF NOT EXISTS product_stock (
        id $id,
        product_id INT NOT NULL,
        store_id INT NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        UNIQUE (product_id, store_id)
    )$end");
    db()->exec("CREATE TABLE IF NOT EXISTS activity (
        id $id,
        user_id INT NOT NULL,
        store_id INT NULL,
        action VARCHAR(30) NOT NULL,
        detail VARCHAR(255) NOT NULL DEFAULT '',
        ref VARCHAR(40) NOT NULL DEFAULT '',
        created_at $dt NOT NULL
    )$end");
    try_exec('CREATE INDEX idx_activity_created ON activity (created_at)');
    try_exec('CREATE INDEX idx_activity_user ON activity (user_id)');
    try_exec('ALTER TABLE sales ADD COLUMN store_id INT NULL');
    try_exec('ALTER TABLE stock_moves ADD COLUMN store_id INT NULL');
    try_exec('ALTER TABLE users ADD COLUMN store_id INT NULL');
    try_exec('CREATE INDEX idx_sales_store ON sales (store_id)');

    $main = q1('SELECT id FROM stores ORDER BY id LIMIT 1');
    $mainId = $main ? (int) $main['id'] : insert('stores', [
        'name' => 'Main store',
        'address' => (string) cfg('store_address', ''),
        'phone' => (string) cfg('store_phone', ''),
        'active' => 1,
        'created_at' => now(),
    ]);
    // Everything recorded so far belongs to the main store.
    q('INSERT INTO product_stock (product_id, store_id, stock)
       SELECT p.id, ?, p.stock FROM products p
       WHERE NOT EXISTS (SELECT 1 FROM product_stock s WHERE s.product_id = p.id AND s.store_id = ?)', [$mainId, $mainId]);
    q('UPDATE sales SET store_id = ? WHERE store_id IS NULL', [$mainId]);
    q('UPDATE stock_moves SET store_id = ? WHERE store_id IS NULL', [$mainId]);
    q("DELETE FROM meta WHERE k = 'schema'");
    q("INSERT INTO meta (k, v) VALUES ('schema', '2')");
}

// Version 3: details for the website-style receipt (fragrance family, crossed-out price).
function migrate_v3()
{
    try_exec("ALTER TABLE products ADD COLUMN family VARCHAR(80) NOT NULL DEFAULT ''");
    try_exec('ALTER TABLE products ADD COLUMN compare_at INT NOT NULL DEFAULT 0');
    try_exec('ALTER TABLE sale_items ADD COLUMN was INT NULL');
    q("DELETE FROM meta WHERE k = 'schema'");
    q("INSERT INTO meta (k, v) VALUES ('schema', '3')");
    q("DELETE FROM meta WHERE k = 'web_sync_at'"); // fetch the family and original prices on the next sync
}
