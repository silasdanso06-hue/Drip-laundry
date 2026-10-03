<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$fixture = $root . '/tmp/owner-tests-' . bin2hex(random_bytes(5));
mkdir($fixture, 0777, true);
file_put_contents($fixture . '/.htaccess', "Require all denied\n");
define('OWNER_DIRECTORY', $fixture);
define('CATALOG_PATH', $fixture . '/catalog.json');
copy($root . '/assets/data/catalog.json', CATALOG_PATH);
require $root . '/lib/owner.php';
require $root . '/lib/catalog.php';
require $root . '/lib/invoice.php';
function checkOwner(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$token = bin2hex(random_bytes(24));
ownerWrite('setup', ['hash' => hash('sha256', $token)]);
checkOwner(!ownerSetupAllowed('wrong-token'), 'Unknown setup keys must be rejected.');
checkOwner(ownerSetupAllowed($token), 'Owner setup key should work.');
ownerCreate('Test123', $token);
checkOwner(!ownerSetupAllowed($token), 'Setup key must stop working after creation.');
checkOwner(ownerRead('owner')['hash'] !== 'Test123', 'Password must be hashed.');
checkOwner(!ownerLogin('wrong-password', 'test-client'), 'Wrong password must not authenticate.');
checkOwner(ownerLogin('Test123', 'test-client'), 'Owner password must authenticate.');
$_SESSION = [];
checkOwner(!ownerSignedIn(), 'Customers must not be signed in.');
$_SESSION = ['owner_version' => ownerRead('owner')['version'], 'last_active' => time(), 'csrf' => 'test-csrf'];
checkOwner(ownerSignedIn(), 'Authenticated owner must be recognised.');
checkOwner(!ownerCsrfValid('wrong'), 'Invalid form tokens must fail.');
checkOwner(ownerCsrfValid('test-csrf'), 'Valid form tokens must pass.');
$_SESSION['last_active'] = time() - 1801;
checkOwner(!ownerSignedIn(), 'Expired owner sessions must fail.');
for ($i = 0; $i < 5; $i++) ownerLogin('wrong-password', 'limited-client');
try {
    ownerLogin('Test123', 'limited-client');
    throw new RuntimeException('Rate limiting did not engage.');
} catch (InvalidArgumentException $expected) {}

$snapshot = catalogSnapshot();
$prices = [];
foreach ($snapshot['catalog'] as $item) {
    $prices[$item['id']] = ['fold' => $item['fold'] === null ? '' : (string) $item['fold']];
}
$prices['item-1-2']['fold'] = '8.75';
$prices['item-1-2']['name'] = '  Cotton   T-shirt  ';
$prices['item-1-2']['category'] = 'Everyday clothing';
$prices['item-4-2']['fold'] = '';
updateCatalog($prices, $snapshot['version']);
$after = catalogSnapshot();
$indexed = array_column($after['catalog'], null, 'id');
checkOwner($indexed['item-1-2']['fold'] === 8.75, 'Decimal price was not saved.');
checkOwner($indexed['item-1-2']['name'] === 'Cotton T-shirt' && $indexed['item-1-2']['category'] === 'Everyday clothing', 'Item name/category or whitespace normalization was not saved.');
checkOwner(array_keys($indexed) === array_column($snapshot['catalog'], 'id'), 'Editing must preserve item IDs.');
checkOwner($indexed['item-4-2']['fold'] === null, 'A blank price should request a quote.');
checkOwner($snapshot['version'] !== $after['version'], 'Price revision must change.');
try {
    updateCatalog($prices, $snapshot['version']);
    throw new RuntimeException('Stale editor must not overwrite newer prices.');
} catch (InvalidArgumentException $expected) {}
$invalid = $prices;
$invalid['item-1-2']['fold'] = '-5';
try {
    updateCatalog($invalid, $after['version']);
    throw new RuntimeException('Negative prices must not be accepted.');
} catch (InvalidArgumentException $expected) {}
checkOwner(catalogSnapshot()['version'] === $after['version'], 'Invalid edits must leave prices unchanged.');
foreach ([['name', ''], ['category', '   '], ['name', str_repeat('a', 81)], ['category', str_repeat('b', 61)], ['name', ['invalid']], ['category', "Bad\ncategory"]] as [$field, $value]) {
    $invalid = $prices;
    $invalid['item-1-2'][$field] = $value;
    try {
        updateCatalog($invalid, $after['version']);
        throw new RuntimeException('Invalid item text was accepted.');
    } catch (InvalidArgumentException $expected) {}
    checkOwner(catalogSnapshot()['version'] === $after['version'], 'Rejected item edits must not save any partial changes.');
}
$order = invoiceOrder([
    'id' => 'DC-OWNER-TEST', 'name' => 'Test Customer', 'phone' => '0200000000',
    'location' => 'Test location', 'date' => '2026-10-01', 'paymentMethod' => 'Cash on pickup',
    'items' => [['id' => 'item-1-2', 'service' => 'fold', 'quantity' => 3]]
], $after['catalog']);
checkOwner($order['total'] === 26.25, 'Invoices must use the updated prices.');
checkOwner($order['lines'][0]['name'] === 'Cotton T-shirt' && $order['lines'][0]['category'] === 'Everyday clothing', 'New invoices must use edited item details.');
echo "PASS: one-time setup, password hashing, authentication, expiry, CSRF, rate limiting, price validation, revisions and invoice totals.\n";
echo "Live owner settings and prices were not changed by these tests.\n";
