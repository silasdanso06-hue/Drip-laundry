<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = 'http://127.0.0.1/Driplaudary/';
$checks = ['index.html' => 200, 'business.php' => 200, 'admin.php' => 200, 'recover.php' => 200,
    'owner-reset.php' => 200, 'catalog.php' => 200, 'customer.php' => 200, 'payment-config.php' => 200,
    'orders.php' => 401, 'admin-orders.php' => 302, 'admin-business.php' => 302, 'admin-payments.php' => 302,
    'sms.php' => 302, 'payment.php' => 302];
$page = file_get_contents(dirname(__DIR__) . '/index.html');
preg_match_all('/(?:src|href|poster)="(assets\/[^"?#]+)(?:[?#][^"]*)?"/', $page, $matches);
foreach (array_unique($matches[1]) as $asset) $checks[$asset] = 200;
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
try {
    foreach ($checks as $path => $expected) {
        curl_setopt($curl, CURLOPT_URL, $base . $path);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($body === false || $status !== $expected) throw new RuntimeException("$path: expected $expected, received $status");
        if (preg_match('/(?:PHP |<b>)(?:Warning|Fatal error|Parse error|Deprecated)/', $body)) throw new RuntimeException("PHP error in $path");
    }
    echo 'PASS: ' . count($checks) . " public pages, guest access checks and local assets; no PHP errors in responses.\n";
} finally {
    curl_close($curl);
}
