<?php
// FOMAXO Store System - shared core: settings, database, login, money helpers.

date_default_timezone_set('Asia/Dubai');

function cfg($key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $candidates = [
            getenv('FOMAXO_POS_CONFIG') ?: null,
            dirname(__DIR__, 2) . '/fomaxo-pos-config.php',
            dirname(__DIR__) . '/config.php',
        ];
        foreach ($candidates as $file) {
            if ($file && is_file($file)) {
                $config = require $file;
                break;
            }
        }
        if ($config === null) {
            $config = false;
        }
    }
    if ($config === false) {
        return $key === null ? false : $default;
    }
    if ($key === null) {
        return $config;
    }
    return array_key_exists($key, $config) ? $config[$key] : $default;
}

function db()
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (cfg() === false) {
        throw new RuntimeException('Settings file config.php is missing. Copy config.sample.php to config.php and fill it in.');
    }
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (cfg('db_driver') === 'sqlite') {
        $pdo = new PDO('sqlite:' . cfg('db_path'), null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    } else {
        $dsn = 'mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), $opts);
        $pdo->exec("SET time_zone = '+04:00'");
    }
    return $pdo;
}

function is_mysql()
{
    return cfg('db_driver') !== 'sqlite';
}

function now()
{
    return date('Y-m-d H:i:s');
}

function q($sql, $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q1($sql, $params = [])
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function insert($table, $data)
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' .
        implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

function uuid4()
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function receipt_no_from_uuid($uuid)
{
    return 'FMX-' . strtoupper(substr(str_replace('-', '', $uuid), 0, 8));
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// Money is stored in fils (1 AED = 100 fils) so there are no rounding errors.
function money($fils)
{
    return number_format($fils / 100, 2);
}

function to_fils($aed)
{
    return (int) round(((float) $aed) * 100);
}

/**
 * Works out the bill. $lines = [['price'=>fils,'qty'=>n], ...]
 * $discount = ['type'=>'percent'|'amount', 'value'=>number] (amount is in AED)
 */
function calc_totals($lines, $discount = null)
{
    $subtotal = 0;
    foreach ($lines as $l) {
        $subtotal += (int) $l['price'] * (int) $l['qty'];
    }
    $disc = 0;
    if ($discount && isset($discount['value']) && (float) $discount['value'] > 0) {
        if (($discount['type'] ?? 'amount') === 'percent') {
            $pct = min(100, max(0, (float) $discount['value']));
            $disc = (int) round($subtotal * $pct / 100);
        } else {
            $disc = min($subtotal, to_fils($discount['value']));
        }
    }
    $afterDisc = $subtotal - $disc;
    $rate = (float) cfg('vat_rate', 5);
    if (cfg('prices_include_vat', true)) {
        $vat = (int) round($afterDisc * $rate / (100 + $rate));
        $total = $afterDisc;
    } else {
        $vat = (int) round($afterDisc * $rate / 100);
        $total = $afterDisc + $vat;
    }
    return ['subtotal' => $subtotal, 'discount' => $disc, 'vat' => $vat, 'total' => $total];
}

function tier_for($spendFils)
{
    $aed = $spendFils / 100;
    $tiers = cfg('tiers', []);
    usort($tiers, function ($a, $b) {
        return $b['min'] <=> $a['min'];
    });
    foreach ($tiers as $t) {
        if ($aed >= $t['min']) {
            return $t['name'];
        }
    }
    return null;
}

function next_tier($spendFils)
{
    $aed = $spendFils / 100;
    $tiers = cfg('tiers', []);
    usort($tiers, function ($a, $b) {
        return $a['min'] <=> $b['min'];
    });
    foreach ($tiers as $t) {
        if ($aed < $t['min']) {
            return ['name' => $t['name'], 'needed' => (int) round(($t['min'] - $aed) * 100)];
        }
    }
    return null;
}

function customer_year_spend($customerId)
{
    $start = date('Y') . '-01-01 00:00:00';
    $row = q1("SELECT COALESCE(SUM(total),0) AS s FROM sales WHERE customer_id = ? AND status = 'paid' AND created_at >= ?", [$customerId, $start]);
    return (int) $row['s'];
}

// ---------- JSON API helpers ----------

class ApiError extends Exception
{
    public $status;
    public function __construct($message, $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function fail($message, $status = 400)
{
    throw new ApiError($message, $status);
}

function json_out($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body()
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = $raw ? json_decode($raw, true) : [];
        if (!is_array($b)) {
            $b = [];
        }
    }
    return $b;
}

function client_ip()
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ---------- Login ----------

function current_user()
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (strlen($token) < 20) {
        return null;
    }
    $row = q1(
        'SELECT u.id, u.name, u.username, u.role, u.active, u.store_id, t.id AS token_id, t.expires_at
         FROM tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?',
        [hash('sha256', $token)]
    );
    if (!$row || !$row['active'] || $row['expires_at'] < now()) {
        return null;
    }
    q('UPDATE tokens SET last_used = ? WHERE id = ?', [now(), $row['token_id']]);
    $user = ['id' => (int) $row['id'], 'name' => $row['name'], 'username' => $row['username'], 'role' => $row['role'],
        'store_id' => $row['store_id'] === null ? null : (int) $row['store_id']];
    return $user;
}

function require_user()
{
    $u = current_user();
    if (!$u) {
        fail('Please log in again.', 401);
    }
    return $u;
}

function require_manager()
{
    $u = require_user();
    if ($u['role'] !== 'manager') {
        fail('Only a manager can do this.', 403);
    }
    return $u;
}

function issue_token($userId)
{
    $token = bin2hex(random_bytes(32));
    insert('tokens', [
        'user_id' => $userId,
        'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400),
        'created_at' => now(),
        'last_used' => now(),
    ]);
    return $token;
}

function is_installed()
{
    try {
        return (bool) q1('SELECT id FROM users LIMIT 1');
    } catch (Throwable $e) {
        return false;
    }
}

/** Records what staff do, for the owner's Team > Activity screen. Never stops the real work. */
function log_activity($user, $action, $detail = '', $ref = '', $storeId = null)
{
    try {
        insert('activity', [
            'user_id' => is_array($user) ? $user['id'] : (int) $user,
            'store_id' => $storeId,
            'action' => $action,
            'detail' => mb_substr((string) $detail, 0, 255),
            'ref' => mb_substr((string) $ref, 0, 40),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        error_log('FOMAXO POS activity: ' . $e->getMessage());
    }
}

function meta_get($k, $default = null)
{
    try {
        $r = q1('SELECT v FROM meta WHERE k = ?', [$k]);
        return $r ? $r['v'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function meta_set($k, $v)
{
    q('DELETE FROM meta WHERE k = ?', [$k]);
    q('INSERT INTO meta (k, v) VALUES (?, ?)', [$k, (string) $v]);
}
