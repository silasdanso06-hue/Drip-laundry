<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/payments.php';
$email = 'payment-test-' . bin2hex(random_bytes(6)) . '@example.invalid';
$id = 'DC-PAY-HTTP-' . strtoupper(bin2hex(random_bytes(6)));
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15, CURLOPT_INTERFACE => '127.0.0.3']);
function payHttp(string $path, ?array $fields = null, bool $json = false): array {
    global $curl;
    curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1/Driplaudary/' . $path, CURLOPT_POST => $fields !== null,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded')]]);
    if ($fields !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $json ? json_encode($fields) : http_build_query($fields));
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('HTTP request failed.');
    return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body];
}
function payHttpCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    payHttpCheck(payHttp('admin-payments.php')['status'] === 302, 'Owner settings allowed a guest.');
    payHttpCheck(payHttp('payment.php?order=anything')['status'] === 302, 'Payment page allowed a guest.');
    $config = payHttp('payment-config.php');
    payHttpCheck($config['status'] === 200 && array_keys(json_decode($config['body'], true)) === ['enabled', 'mode'], 'Public settings leaked private data.');
    payHttpCheck(payHttp('payment-return.php?reference=forged')['status'] === 400, 'Forged callback was accepted.');
    payHttpCheck(payHttp('payment-webhook.php', ['event' => 'charge.success', 'data' => ['reference' => 'forged']], true)['status'] === 400, 'Unsigned webhook was accepted.');
    $session = json_decode(payHttp('customer.php')['body'], true);
    $signup = payHttp('account.php', ['action' => 'signup', 'csrf' => $session['csrf'], 'email' => $email, 'name' => 'Payment test', 'password' => 'Chosen test password', 'confirm' => 'Chosen test password']);
    payHttpCheck($signup['status'] === 303, 'Customer signup failed.');
    $account = json_decode(payHttp('customer.php')['body'], true);
    createSavedOrder($account['user']['id'], ['id' => $id, 'total' => 25, 'paymentStatus' => 'Unpaid', 'status' => 'Received', 'paymentMethod' => 'Cash on pickup']);
    $page = payHttp('payment.php?order=' . $id);
    payHttpCheck($page['status'] === 200 && str_contains($page['body'], 'GHS 25.00'), 'Owned order payment page did not load.');
    payHttpCheck(payHttp('payment.php?order=DC-NOT-OWNED')['status'] === 404, 'Non-owned order was exposed.');
    $attempts = databaseRead('payment-attempts');
    $badCsrf = payHttp('payment.php', ['order' => $id, 'csrf' => 'forged', 'action' => 'start', 'email' => $email]);
    payHttpCheck(str_contains($badCsrf['body'], 'Form expired') && databaseRead('payment-attempts') === $attempts, 'Invalid CSRF started a payment.');
    payHttp('customer.php', ['action' => 'logout', 'csrf' => $account['csrf']], true);
    echo "PASS: payment page ownership, owner authorization, public settings, invalid callbacks, unsigned webhooks and CSRF. No provider requests or charges.\n";
} finally {
    databaseTransaction(function (array &$store) use ($id, $email): void { unset($store['orders'][$id], $store['customers'][$email]); });
    curl_close($curl);
}
