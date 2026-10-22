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
        "CREATE INDEX idx_sales_status ON sales (status)",
        "CREATE INDEX idx_items_sale ON sale_items (sale_id)",
        "CREATE INDEX idx_moves_product ON stock_moves (product_id)",
        "CREATE INDEX idx_attempts_ip ON login_attempts (ip)",
    ];
}
