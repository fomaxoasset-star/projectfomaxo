<?php
// FOMAXO Store System - one-time setup. Creates the tables and the first manager login.
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/schema.php';

$error = '';
$done = false;

function page($title, $body)
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><link rel="stylesheet" href="assets/page.css"></head><body><main class="card">';
    echo '<div class="brand">FOMAXO</div><h1>' . h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

if (cfg() === false) {
    page('Setup needed', '<p>The settings file is missing.</p><ol><li>Open the app folder in Hostinger File Manager.</li><li>Copy <b>config.sample.php</b> and name the copy <b>config.php</b>.</li><li>Fill in your database details, then open this page again.</li></ol>');
}

try {
    db();
} catch (Throwable $e) {
    page('Cannot reach the database', '<p>Check the database name, user and password in config.php.</p><p class="err">' . h($e->getMessage()) . '</p>');
}

if (is_installed()) {
    page('Already set up', '<p>The store system is installed. <a href="./">Open the app</a>.</p><p class="muted">For safety you can delete install.php from the server now.</p>');
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
            $low = (int) cfg('default_low_stock', 5);
            foreach (['FOMAXO Gold', 'FOMAXO Dollar', 'FOMAXO King', 'FOMAXO Old Money', 'FOMAXO Royal Candy', 'FOMAXO Matcha Coco', 'FOMAXO Passion Sin'] as $p) {
                insert('products', [
                    'name' => $p, 'variant' => '', 'sku' => '', 'category' => 'Fragrance',
                    'price' => 0, 'stock' => 0, 'low_at' => $low, 'active' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $done = true;
    }
}

if ($done) {
    page('All set', '<p>Your manager login is ready.</p><p><a class="btn" href="./">Open the store app</a></p><p class="muted">Delete install.php from the server now, so nobody can run setup again.</p>');
}

$form = ($error ? '<p class="err">' . h($error) . '</p>' : '') . '
<p>Create the first <b>manager</b> login. You can add staff inside the app afterwards.</p>
<form method="post">
  <label>Your name<input name="name" required value="' . h($_POST['name'] ?? '') . '"></label>
  <label>Username<input name="username" required autocapitalize="none" value="' . h($_POST['username'] ?? '') . '"></label>
  <label>Password (8+ characters)<input name="password" type="password" required minlength="8"></label>
  <label>Password again<input name="password2" type="password" required minlength="8"></label>
  <label class="check"><input type="checkbox" name="starter" value="1" checked> Add the FOMAXO fragrance line as starter products (switched off until you set prices)</label>
  <button class="btn" type="submit">Set up store system</button>
</form>';
page('Set up FOMAXO Store', $form);
