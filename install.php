<?php
// FOMAXO Store System - one-time setup. Creates the tables and the first manager login.
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/schema.php';

$error = '';
$done = false;

function page($title, $body)
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><link rel="stylesheet" href="assets/page.css"><link rel="icon" href="assets/favicon-32.png"><link rel="apple-touch-icon" href="assets/apple-touch-icon.png"></head><body><main class="card">';
    echo '<div class="brand">FOMAXO</div><h1>' . h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

// ---- Step 1: settings file. Saved one folder ABOVE the website folder, so GitHub
// deploys never remove it and no visitor can ever open it.
if (cfg() === false) {
    $target = dirname(__DIR__) . '/fomaxo-pos-config.php';
    $err1 = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'config') {
        $c = require __DIR__ . '/config.sample.php';
        $c['db_name'] = trim($_POST['db_name'] ?? '');
        $c['db_user'] = trim($_POST['db_user'] ?? '');
        $c['db_pass'] = (string) ($_POST['db_pass'] ?? '');
        $c['store_address'] = trim($_POST['store_address'] ?? '');
        $c['store_phone'] = trim($_POST['store_phone'] ?? '');
        $c['store_trn'] = trim($_POST['store_trn'] ?? '');
        $c['base_url'] = 'https://' . preg_replace('/[^a-z0-9.:-]/i', '', $_SERVER['HTTP_HOST'] ?? '') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        try {
            new PDO('mysql:host=localhost;dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $php = "<?php\n// FOMAXO Store System settings. Keep private.\nreturn " . var_export($c, true) . ";\n";
            if (@file_put_contents($target, $php, LOCK_EX) === false) {
                $target = __DIR__ . '/config.php';
                if (@file_put_contents($target, $php, LOCK_EX) === false) {
                    throw new RuntimeException('Could not save the settings file. Check folder permissions in File Manager.');
                }
            }
            @chmod($target, 0600);
            header('Location: install.php');
            exit;
        } catch (PDOException $e) {
            $err1 = 'Could not connect to the database with those details. Check the name, user and password.';
        } catch (Throwable $e) {
            $err1 = $e->getMessage();
        }
    }
    page('Connect the database', ($err1 ? '<p class="err">' . h($err1) . '</p>' : '') . '
<p>Step 1 of 2. These are saved privately on the server, outside the website folder.</p>
<form method="post" autocomplete="off"><input type="hidden" name="step" value="config">
  <label>Database name<input name="db_name" required value="' . h($_POST['db_name'] ?? '') . '"></label>
  <label>Database user<input name="db_user" required value="' . h($_POST['db_user'] ?? '') . '"></label>
  <label>Database password<input name="db_pass" type="password" required></label>
  <label>Store address (printed on receipts)<input name="store_address" value="' . h($_POST['store_address'] ?? '') . '"></label>
  <label>Store phone<input name="store_phone" value="' . h($_POST['store_phone'] ?? '') . '"></label>
  <label>VAT TRN (optional)<input name="store_trn" value="' . h($_POST['store_trn'] ?? '') . '"></label>
  <button class="btn" type="submit">Save and continue</button>
</form>');
}

try {
    db();
} catch (Throwable $e) {
    page('Cannot reach the database', '<p>Check the database details in the settings file (fomaxo-pos-config.php, one folder above the website folder).</p><p class="err">' . h($e->getMessage()) . '</p>');
}

if (is_installed()) {
    page('Already set up', '<p>The store system is installed. <a href="./">Open the app</a>.</p><p class="muted">This setup page is now locked and cannot be run again.</p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = strtolower(trim($_POST['username'] ?? ''));
    $pass = $_POST['password'] ?? '';
    if ($name === '' || !preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
        $error = 'Enter your name and a username (3+ letters or numbers, no spaces).';
    } elseif (strlen($pass) < 8) {
        $error = 'Password needs at least 8 characters.';
    } elseif ($pass !== ($_POST['password2'] ?? '')) {
        $error = 'The two passwords do not match.';
    } else {
        $pdo = db();
        foreach (schema_sql(is_mysql()) as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                if (stripos($sql, 'CREATE INDEX') !== 0) {
                    throw $e; // index already there is fine, anything else is not
                }
            }
        }
        insert('users', [
            'name' => $name,
            'username' => $username,
            'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'role' => 'manager',
            'active' => 1,
            'created_at' => now(),
        ]);
        if (!empty($_POST['starter'])) {
            // Products, sizes and prices from the fomaxo.com website (happens during migrate below).
        } else {
            q("INSERT INTO products (name, variant, sku, category, price, stock, low_at, active, created_at, updated_at)
               VALUES ('Example product', '', 'manual', 'Fragrance', 0, 0, 5, 0, ?, ?)", [now(), now()]);
            q("DELETE FROM products WHERE sku = 'manual'");
        }
        migrate();
        $done = true;
    }
}

if ($done) {
    page('All set', '<p>Your manager login is ready.</p><p><a class="btn" href="./">Open the store app</a></p><p class="muted">This setup page is now locked and cannot be run again.</p>');
}

$form = ($error ? '<p class="err">' . h($error) . '</p>' : '') . '
<p>Step 2 of 2. Create the first <b>manager</b> login. You can add staff inside the app afterwards.</p>
<form method="post">
  <label>Your name<input name="name" required value="' . h($_POST['name'] ?? '') . '"></label>
  <label>Username<input name="username" required autocapitalize="none" value="' . h($_POST['username'] ?? '') . '"></label>
  <label>Password (8+ characters)<input name="password" type="password" required minlength="8"></label>
  <label>Password again<input name="password2" type="password" required minlength="8"></label>
  <label class="check"><input type="checkbox" name="starter" value="1" checked> Bring in all products, sizes and prices from fomaxo.com</label>
  <button class="btn" type="submit">Set up store system</button>
</form>';
page('Set up FOMAXO Store', $form);
