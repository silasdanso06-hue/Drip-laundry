<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = dirname(__DIR__) . '/tmp/php-flows-' . bin2hex(random_bytes(6));
define('DATA_DIRECTORY', $fixture); define('OWNER_DIRECTORY', $fixture);
require dirname(__DIR__) . '/lib/account-codes.php';
require dirname(__DIR__) . '/lib/order-management.php';
require dirname(__DIR__) . '/lib/catalog.php';
function flowCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function flowReject(callable $action): void {
    try { $action(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid data operation succeeded.');
}
try {
    databaseInitialize(databaseEmptyStore());
    $input = ['name' => 'Storage test', 'email' => 'test@example.invalid', 'password' => 'A chosen password', 'confirm' => 'A chosen password'];
    $user = customerAccount('signup', $input, 'test');
    $originalVersion = customerPasswordVersion($user);
    $code = ''; $send = function ($channel, $to, $text) use (&$code) { preg_match('/\b\d{6}\b/', $text, $match); $code = $match[0]; };
    $token = accountCodeStart($input['email'], 'reset', 'email', 'test', null, $send);
    accountCodeFinish($token, $code, 'My new chosen password');
    flowCheck(customerPasswordVersion($user) !== $originalVersion, 'Reset must invalidate the old session version.');
    flowReject(fn() => customerAccount('login', $input, 'test'));
    $input['password'] = 'My new chosen password';
    flowCheck(customerAccount('login', $input, 'test')['id'] === $user['id'], 'Reset password was not persisted.');
    flowReject(fn() => accountCodeFinish($token, $code, 'Another password'));
    $token = accountCodeStart('', 'verify', 'email', 'test', $user, $send);
    accountCodeFinish($token, $code);
    flowCheck(!empty(databaseRead('customers')[$input['email']]['email_verified_at']), 'Verification was not persisted.');
    $other = customerAccount('signup', array_replace($input, ['email' => '0201234567', 'confirm' => $input['password']]), 'phone-test');
    flowCheck(customerAccount('login', array_replace($input, ['email' => '+233201234567']), 'phone-test')['id'] === $other['id'], 'Phone login failed on PHP storage.');
    flowCheck(!empty(databaseRead('customers')[$input['email']]['email_verified_at']), 'Another signup erased verification.');
    for ($i = 1; $i <= 21; $i++) createSavedOrder($user['id'], ['id' => sprintf('DC-FILE-%02d', $i), 'total' => 25, 'status' => 'Received', 'paymentStatus' => 'Unpaid']);
    flowCheck(count(customerOrders($user['id'])) === 21 && count(customerOrders($other['id'])) === 0, 'Orders must belong to their customer.');
    flowCheck(savedOrder('DC-FILE-01', $other['id']) === null, 'Cross-customer access was allowed.');
    flowReject(fn() => createSavedOrder($other['id'], ['id' => 'DC-FILE-01']));
    flowCheck(count(ownerOrders('storage TEST', 1)['orders']) === 20 && ownerOrders('storage TEST', 1)['more'], 'Owner search or first page failed.');
    flowCheck(count(ownerOrders($input['email'], 2)['orders']) === 1 && !ownerOrders($input['email'], 2)['more'], 'Second page failed.');
    flowCheck(count(ownerOrders('DC-FILE-01', 1)['orders']) === 1, 'Order number search failed.');
    $version = orderVersion(savedOrder('DC-FILE-01'));
    updateOrderStatus('DC-FILE-01', 'Ready', 'Paid', $version);
    $paid = savedOrder('DC-FILE-01');
    flowCheck($paid['amountPaid'] === 25.0 && $paid['balanceDue'] === 0 && count($paid['paymentHistory']) === 1, 'Payment details were not saved.');
    flowReject(fn() => updateOrderStatus('DC-FILE-01', 'Delivered', 'Paid', $version));
    updateOrderStatus('DC-FILE-01', 'Cancelled', 'Refunded', orderVersion($paid));
    flowCheck(count(savedOrder('DC-FILE-01')['paymentHistory']) === 2, 'Refund history missing.');
    databaseWrite('catalog', [['id' => 'test-item', 'name' => 'Test shirt', 'fold' => 5, 'iron' => 7]]);
    $catalog = catalogSnapshot();
    updateCatalog(['test-item' => ['fold' => '8.50', 'iron' => '10']], $catalog['version']);
    flowCheck(catalogSnapshot()['catalog'][0]['fold'] === 8.5, 'Price update was not persisted.');
    flowReject(fn() => updateCatalog(['test-item' => ['fold' => '5', 'iron' => '7']], $catalog['version']));
    ownerWrite('business', ['hours' => '24 hours', 'fees' => 'Free pickup and delivery']);
    ownerWrite('sms-settings', ['key' => 'fake-test-key', 'sender' => 'Test']);
    flowCheck(ownerRead('business')['hours'] === '24 hours' && ownerRead('sms-settings')['key'] === 'fake-test-key', 'Settings did not persist.');
    echo "PASS: PHP-backed signup, phone login, password reset, verification, customer isolation, order search/pagination, payments, refunds, prices and settings. No messages sent.\n";
} finally {
    foreach (glob($fixture . '/*') ?: [] as $file) unlink($file);
    if (is_dir($fixture)) { unlink($fixture . '/.htaccess'); rmdir($fixture); }
}
