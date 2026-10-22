<?php
// Read-only look at the fomaxo.com shop database (same Hostinger account), to recognise
// customers who ordered on the website. Nothing on the website is ever changed from here.

function web_db()
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $pdo = null;
    $file = cfg('website_db_config', dirname(dirname(__DIR__), 2) . '/fomaxo.com/fomaxo-db-config.php');
    if (!$file || !@is_file($file) || !@is_readable($file)) {
        return null;
    }
    try {
        $c = require $file;
        if (!is_array($c) || empty($c['name'])) {
            return null;
        }
        $pdo = new PDO('mysql:host=' . ($c['host'] ?? 'localhost') . ';port=' . ($c['port'] ?? 3306) . ';dbname=' . $c['name'] . ';charset=utf8mb4',
            $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 4]);
        $pdo->exec("SET time_zone = '+04:00'");
    } catch (Throwable $e) {
        error_log('FOMAXO POS: website database not reachable: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

function phone9($p)
{
    return substr(preg_replace('/\D/', '', (string) $p), -9);
}

/** The customer's details and order history on fomaxo.com, found by mobile (or email). Null if none. */
function web_customer($phone, $email = '')
{
    $pdo = web_db();
    if (!$pdo) {
        return null;
    }
    $p9 = phone9($phone);
    $email = strtolower(trim((string) $email));
    try {
        $where = [];
        $params = [];
        if (strlen($p9) === 9) {
            $where[] = 'RIGHT(REGEXP_REPLACE(phone, "[^0-9]", ""), 9) = ?';
            $params[] = $p9;
        }
        if ($email !== '') {
            $where[] = 'LOWER(email) = ?';
            $params[] = $email;
        }
        if (!$where) {
            return null;
        }
        $sql = "SELECT name, phone, email, total, status, created_at FROM fx_orders
                WHERE test = 0 AND status NOT IN ('Awaiting payment', 'Cancelled', 'Refunded') AND (" . implode(' OR ', $where) . ")
                ORDER BY created_at DESC LIMIT 200";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } catch (PDOException $e) {
            // older MySQL without REGEXP_REPLACE: compare the last digits more simply
            $sql = str_replace('RIGHT(REGEXP_REPLACE(phone, "[^0-9]", ""), 9) = ?', "RIGHT(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), 9) = ?", $sql);
            $st = $pdo->prepare($sql);
            $st->execute($params);
        }
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('FOMAXO POS: website customer lookup failed: ' . $e->getMessage());
        return null;
    }
    if (!$rows) {
        return null;
    }
    $year = date('Y');
    $spent = 0;
    $yearSpent = 0;
    foreach ($rows as $r) {
        $f = (int) round(((float) $r['total']) * 100);
        $spent += $f;
        if (substr($r['created_at'], 0, 4) === $year) {
            $yearSpent += $f;
        }
    }
    $last = $rows[0];
    $em = '';
    foreach ($rows as $r) {
        if (trim($r['email']) !== '') {
            $em = strtolower(trim($r['email']));
            break;
        }
    }
    return [
        'name' => trim((string) $last['name']),
        'phone' => trim((string) $last['phone']),
        'email' => $em,
        'orders' => count($rows),
        'spent' => $spent,
        'year_spend' => $yearSpent,
        'last_order' => substr($last['created_at'], 0, 10),
    ];
}

/** This year's website spend for a mobile number (for FOMAXO Elite tiers). */
function web_year_spend($phone)
{
    static $cache = [];
    $p9 = phone9($phone);
    if (strlen($p9) !== 9) {
        return 0;
    }
    if (!isset($cache[$p9])) {
        $w = web_customer($phone);
        $cache[$p9] = $w ? (int) $w['year_spend'] : 0;
    }
    return $cache[$p9];
}
