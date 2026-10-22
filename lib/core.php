<?php
// FOMAXO Store System - shared core: settings, database, login, money helpers.

date_default_timezone_set('Asia/Dubai');

/** Places the private settings file can live, safest first (all outside the website folder except the last). */
function config_paths()
{
    $app = dirname(__DIR__);
    $paths = [];
    if (getenv('FOMAXO_POS_CONFIG')) {
        $paths[] = getenv('FOMAXO_POS_CONFIG');
    }
    $paths[] = dirname($app) . '/fomaxo-pos-config.php';
    $home = getenv('HOME') ?: '';
    if (!$home && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        $home = $pw['dir'] ?? '';
    }
    if (!$home && preg_match('#^(/home/[^/]+)/#', $app, $m)) {
        $home = $m[1];
    }
    if ($home) {
        $paths[] = rtrim($home, '/') . '/.fomaxo-pos-config.php';
        $paths[] = rtrim($home, '/') . '/fomaxo-private/fomaxo-pos-config.php';
    }
    $paths[] = $app . '/config.php'; // last resort: inside the app folder (an update can remove it)
    return array_values(array_unique($paths));
}

function cfg($key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = false;
        foreach (config_paths() as $file) {
            if ($file && @is_file($file)) {
                $config = require $file;
                break;
            }
        }
    }
    if ($config === false) {
        return $key === null ? false : $default;
    }
    if ($key === null) {
        return $config;
    }
    // A few settings can be changed by a manager inside the app (Settings); those win over the file.
    if (in_array($key, ['ziina_token', 'ziina_mode', 'store_trn'], true)) {
        $ov = app_settings();
        if (array_key_exists($key, $ov)) {
            return $ov[$key];
        }
    }
    return array_key_exists($key, $config) ? $config[$key] : $default;
}

function app_settings($reload = false)
{
    static $ov = null;
    if ($ov === null || $reload) {
        $ov = [];
        try {
            foreach (q("SELECT k, v FROM meta WHERE k IN ('set_ziina_token', 'set_ziina_mode', 'set_store_trn')")->fetchAll() as $r) {
                $ov[substr($r['k'], 4)] = $r['v'];
            }
        } catch (Throwable $e) {
            // database not ready yet - use the settings file
        }
    }
    return $ov;
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

/** Store code for order numbers: "City Centre Sharjah" -> "CCS", "Dubai" -> "DUB". */
function store_code_from_name($name)
{
    $words = preg_split('/[^A-Za-z0-9]+/', strtoupper((string) $name), -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) >= 2) {
        $c = '';
        foreach ($words as $w) {
            $c .= $w[0];
        }
        return substr($c, 0, 4);
    }
    return $words ? substr($words[0], 0, 3) : 'ST';
}

function clean_store_code($c)
{
    return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $c)), 0, 6);
}

/** Next order number for a store: FMX-<code>-10000, 10001, ... Call inside the sale transaction. */
function next_order_no($storeId)
{
    q('UPDATE stores SET next_no = next_no + 1 WHERE id = ?', [$storeId]);
    $s = q1('SELECT code, next_no FROM stores WHERE id = ?', [$storeId]);
    return 'FMX-' . ($s['code'] !== '' ? $s['code'] : 'ST') . '-' . ((int) $s['next_no'] - 1);
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
/** VAT only applies once the business has a TRN (VAT registration). Until then: no VAT anywhere. */
function vat_rate()
{
    return trim((string) cfg('store_trn', '')) === '' ? 0.0 : (float) cfg('vat_rate', 5);
}

function calc_totals($lines, $discount = null, $couponFils = 0)
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
    $couponFils = max(0, min((int) $couponFils, $subtotal - $disc));
    $afterDisc = $subtotal - $disc - $couponFils;
    $rate = vat_rate();
    if (cfg('prices_include_vat', true)) {
        $vat = (int) round($afterDisc * $rate / (100 + $rate));
        $total = $afterDisc;
    } else {
        $vat = (int) round($afterDisc * $rate / 100);
        $total = $afterDisc + $vat;
    }
    return ['subtotal' => $subtotal, 'discount' => $disc, 'coupon' => $couponFils, 'vat' => $vat, 'total' => $total];
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

/** This year's spend for FOMAXO Elite tiers: store purchases plus fomaxo.com orders (same mobile). */
function customer_year_spend($customerId)
{
    $start = date('Y') . '-01-01 00:00:00';
    $row = q1("SELECT COALESCE(SUM(total),0) AS s FROM sales WHERE customer_id = ? AND status = 'paid' AND created_at >= ?", [$customerId, $start]);
    $spend = (int) $row['s'];
    $c = q1('SELECT phone FROM customers WHERE id = ?', [$customerId]);
    if ($c && strpos((string) $c['phone'], 'e:') !== 0 && cfg('website_customers', true)) {
        require_once __DIR__ . '/website.php';
        $spend += web_year_spend($c['phone']);
    }
    return $spend;
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

/** Staff names and usernames in capitals: "sara ahmed" -> "SARA AHMED", "sara" -> "SARA" (login ignores capitals). */
function name_case($n)
{
    $n = trim(preg_replace('/\s+/', ' ', (string) $n));
    return mb_strtoupper($n, 'UTF-8');
}

function username_case($u)
{
    return strtoupper(trim((string) $u));
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

/** The FOMAXO name drawn in the FOMAXO typeface (same artwork as fomaxo.com). */
function wordmark()
{
    return '<svg class="wm" viewBox="0 0 3196 481" role="img" aria-label="FOMAXO"><path fill="currentColor" fill-rule="evenodd" d="M1068 147 1068 480 1135 480 1135 214ZM1778 123 1581 480 1672 479 1815 221 1823 204ZM0 480 68 480 69 257 377 257 377 189 69 189 68 105 0 105ZM2934 1 2911 4 2871 15 2837 31 2801 56 2768 90 2743 128 2726 168 2720 191 2716 218 2716 263 2723 303 2739 346 2750 366 2766 389 2776 401 2802 426 2837 450 2864 463 2890 472 2926 479 2973 480 3003 476 3023 471 3048 462 3069 452 3108 426 3141 393 3166 356 3185 311 3194 267 3194 215 3186 174 3178 151 3165 124 3154 106 3138 85 3110 57 3089 41 3069 29 3027 11 2998 4 2976 1ZM2933 70 2966 69 3005 76 3038 90 3064 108 3087 131 3105 157 3120 192 3126 222 3126 259 3120 289 3107 321 3092 344 3064 373 3039 390 3013 402 2976 411 2934 411 2902 404 2869 389 2847 374 2822 349 2803 321 2789 287 2784 262 2783 229 2790 191 2805 157 2823 131 2847 107 2872 90 2901 77ZM2623 1 2538 1 2431 145 2421 161 2463 217ZM2198 1 2366 233 2184 480 2269 480 2411 293 2417 299 2550 480 2635 480 2276 1ZM722 1 677 9 654 17 625 31 590 55 555 91 533 124 520 151 513 171 504 216 504 265 513 311 531 354 557 393 591 427 623 449 647 461 671 470 714 479 760 480 785 477 811 471 855 453 897 425 928 394 948 366 958 348 974 307 982 265 982 217 973 171 954 126 939 102 925 84 893 53 869 36 851 26 815 11 786 4 763 1ZM721 70 754 69 793 76 829 92 848 105 873 129 894 159 908 193 914 224 914 258 907 292 893 324 876 349 854 371 825 391 792 405 764 411 721 411 693 405 660 391 635 374 609 348 592 323 580 297 572 264 571 227 577 194 583 177 594 155 610 132 636 106 658 91 689 77ZM0 1 0 69 423 69 423 1ZM1847 0 1802 82 2022 480 2113 480ZM1068 0 1068 97 1282 311 1425 169 1426 170 1426 480 1494 480 1494 4 1282 215Z"/></svg>';
}

/** Who is logged in, the shop settings and the stores: what the app needs to start. */
function me_payload($u)
{
    return [
        'user' => $u,
        'settings' => [
            'store_name' => cfg('store_name'),
            'store_tagline' => cfg('store_tagline'),
            'store_address' => cfg('store_address'),
            'store_phone' => cfg('store_phone'),
            'store_trn' => cfg('store_trn'),
            'receipt_footer' => cfg('receipt_footer'),
            'currency' => cfg('currency', 'AED'),
            'vat_rate' => vat_rate(),
            'prices_include_vat' => (bool) cfg('prices_include_vat', true),
            'default_low_stock' => (int) cfg('default_low_stock', 5),
            'staff_max_discount' => (float) cfg('staff_max_discount', 10),
            'ziina' => ziina_enabled(),
            'ziina_mode' => ziina_mode(),
            'tiers' => cfg('tiers', []),
            'base_url' => cfg('base_url'),
        ],
        'stores' => stores_list($u['role'] !== 'manager'),
    ];
}

/** One line of text from the app: spaces tidied, cut to $max characters. */
function text_in($v, $max)
{
    return trim(mb_substr(preg_replace('/\s+/', ' ', (string) $v), 0, $max));
}

/** How a sale was paid, in words for activity and messages. */
function method_name($m)
{
    return ['cash' => 'Cash', 'tap' => 'Card / Tap', 'card' => 'Card machine', 'ziina' => 'Ziina QR'][$m] ?? (string) $m;
}
