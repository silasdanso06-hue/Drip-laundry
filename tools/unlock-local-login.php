<?php
declare(strict_types=1);
// Local CLI maintenance only; never expose a public lockout-reset endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/owner.php';
$lock = fopen(ownerDirectory() . '/customers.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not acquire account lock.');
try {
    $attempts = ownerRead('customer-attempts') ?? [];
    foreach (['127.0.0.1', '::1'] as $address) unset($attempts[hash('sha256', $address)]);
    ownerWrite('customer-attempts', $attempts);
    echo "Local customer login lockouts cleared. Accounts and passwords are unchanged.\n";
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
