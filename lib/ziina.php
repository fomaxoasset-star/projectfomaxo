<?php
// Ziina payments (https://docs.ziina.com). Amounts go to Ziina in fils (AED x 100).
// Modes: 'live' real money, 'test' Ziina test payments, 'demo' no Ziina account needed
// (the QR code opens a built-in practice page instead - for trying the app only).

function ziina_mode()
{
    return cfg('ziina_mode', 'test');
}

function ziina_enabled()
{
    return ziina_mode() === 'demo' || trim((string) cfg('ziina_token', '')) !== '';
}

function ziina_call($method, $path, $payload = null)
{
    $ch = curl_init('https://api-v2.ziina.com/api' . $path);
    $headers = [
        'Authorization: Bearer ' . trim(cfg('ziina_token')),
        'Accept: application/json',
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        throw new ApiError('Could not reach Ziina: ' . $err, 502);
    }
    $data = json_decode($raw, true);
    if ($code >= 400 || !is_array($data)) {
        $msg = is_array($data) ? ($data['message'] ?? $data['error'] ?? 'unknown error') : 'unexpected reply';
        if (is_array($msg)) {
            $msg = implode(', ', $msg);
        }
        throw new ApiError('Ziina refused the payment request (' . $code . '): ' . $msg, 502);
    }
    return $data;
}

/** Creates a Ziina payment. Returns ['id' => ..., 'redirect_url' => ...] */
function ziina_create($totalFils, $message, $successUrl, $cancelUrl)
{
    if (ziina_mode() === 'demo') {
        $id = 'demo_' . bin2hex(random_bytes(8));
        demo_store($id, 'requires_payment_instrument');
        return ['id' => $id, 'redirect_url' => cfg('base_url') . '/ziina-demo.php?id=' . $id];
    }
    $res = ziina_call('POST', '/payment_intent', [
        'amount' => $totalFils,
        'currency_code' => 'AED',
        'message' => $message,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'failure_url' => $cancelUrl,
        'test' => ziina_mode() !== 'live',
        'expiry' => (string) ((time() + 30 * 60) * 1000),
    ]);
    if (empty($res['id']) || empty($res['redirect_url'])) {
        throw new ApiError('Ziina did not return a payment link.', 502);
    }
    return ['id' => $res['id'], 'redirect_url' => $res['redirect_url']];
}

/** Returns Ziina status: completed, failed, canceled, pending, requires_... */
function ziina_status($intentId)
{
    if (strpos($intentId, 'demo_') === 0) {
        return demo_store($intentId) ?: 'failed';
    }
    $res = ziina_call('GET', '/payment_intent/' . rawurlencode($intentId));
    return $res['status'] ?? 'pending';
}

function demo_store($id, $setStatus = null)
{
    $file = dirname(__DIR__) . '/data/ziina-demo.json';
    $all = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    if ($setStatus !== null) {
        $all[$id] = $setStatus;
        file_put_contents($file, json_encode($all), LOCK_EX);
        return $setStatus;
    }
    return $all[$id] ?? null;
}

/** A Ziina payment link for a saved sale. */
function ziina_intent_for($sale)
{
    $r = cfg('base_url') . '/receipt.php?t=' . $sale['receipt_token'];
    return ziina_create((int) $sale['total'], cfg('store_name', 'FOMAXO') . ' order', $r, $r . '&cancelled=1');
}

/** Remember the web address of Ziina's payment links (e.g. pay.ziina.com): the Ziina app opens those, so "Open Ziina" uses it. */
function ziina_note_host($url)
{
    $h = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    if ($h !== '' && preg_match('/(^|\.)ziina\.com$/', $h) && meta_get('ziina_pay_host') !== $h) {
        meta_set('ziina_pay_host', $h);
    }
}
