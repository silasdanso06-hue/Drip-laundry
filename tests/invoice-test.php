<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/lib/invoice.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$catalog = json_decode(file_get_contents($root . '/assets/data/catalog.json'), true, 32, JSON_THROW_ON_ERROR);
// Fix prices in this test fixture; owner edits must not change test expectations.
foreach ($catalog as &$item) {
    if ($item['fold'] === null) $item['fold'] = 5;
    if ($item['id'] === 'item-1-2') $item['fold'] = 8;
    if ($item['id'] === 'item-1-6') $item['iron'] = 10;
    if ($item['id'] === 'item-5-3') $item['fold'] = 35;
    if ($item['id'] === 'item-2-1') $item['iron'] = null;
}
unset($item);
set_error_handler(static function ($severity, $message, $file, $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    invoiceOrder(['items' => [['id' => 'fold-only', 'service' => 'iron', 'quantity' => 1]]], [['id' => 'fold-only', 'fold' => 5]]);
    throw new RuntimeException('Missing service price was accepted.');
} catch (InvalidArgumentException $expected) {
    // Missing price fields must be rejected without PHP warnings.
} finally {
    restore_error_handler();
}
$request = [
    'id' => 'DC-EXAMPLE-001',
    'name' => 'Example Customer',
    'phone' => '020 000 0000',
    'location' => 'Example pickup address, Madina Estate',
    'date' => date('Y-m-d', strtotime('+1 day')),
    'paymentMethod' => 'Cash on pickup',
    'items' => [
        ['id' => 'item-1-2', 'service' => 'fold', 'quantity' => 3, 'unitPrice' => 0],
        ['id' => 'item-1-6', 'service' => 'iron', 'quantity' => 2],
        ['id' => 'item-5-3', 'service' => 'fold', 'quantity' => 1]
    ],
    'total' => 0
];
$order = invoiceOrder($request, $catalog);
check($order['total'] == 79, 'Invoice must recalculate 24 + 20 + 35, ignoring submitted totals.');

foreach ([6 => 50, 7 => 60, 8 => 70] as $kg => $price) {
    $load = $request;
    $load['items'] = [['id' => 'load-' . $kg . 'kg', 'service' => 'fold', 'quantity' => 1]];
    $loadOrder = invoiceOrder($load, $catalog);
    check($loadOrder['total'] == $price && $loadOrder['lines'][0]['unit'] === 'load', 'Load price must charge for one load, not per kilogram.');
    $load['items'][0]['service'] = 'iron';
    try {
        invoiceOrder($load, $catalog);
        throw new RuntimeException('Wash-and-fold load was charged as ironing.');
    } catch (InvalidArgumentException $expected) {}
}
$mixedLoads = $request;
$mixedLoads['items'] = [['id' => 'load-6kg', 'service' => 'fold', 'quantity' => 1], ['id' => 'load-7kg', 'service' => 'fold', 'quantity' => 1]];
$loadOrder = invoiceOrder($mixedLoads, $catalog);
check($loadOrder['subtotal'] == 110 && $loadOrder['discountAmount'] == 5.5 && $loadOrder['total'] == 104.5, 'Loads must receive the same order discount.');

foreach ([0, -1, 1.5, 100, '2'] as $quantity) {
    $invalid = $request;
    $invalid['items'][0]['quantity'] = $quantity;
    try {
        invoiceOrder($invalid, $catalog);
        throw new RuntimeException('Invalid quantity was accepted.');
    } catch (InvalidArgumentException $expected) {}
}

$quote = $request;
$quote['items'] = [['id' => 'item-2-1', 'service' => 'iron', 'quantity' => 1]];
try {
    invoiceOrder($quote, $catalog);
    throw new RuntimeException('Quote-only service was charged.');
} catch (InvalidArgumentException $expected) {}

$card = $request;
$card['paymentMethod'] = 'Card';
try {
    invoiceOrder($card, $catalog);
    throw new RuntimeException('Unsupported payment was accepted.');
} catch (InvalidArgumentException $expected) {}

$pdf = invoicePdf($order, $root . '/assets/images/dripclean-logo.png');
check(str_starts_with($pdf, '%PDF-1.4'), 'PDF signature missing.');
check(str_contains($pdf, '/Subtype /Image'), 'Invoice logo missing.');
check(str_contains($pdf, 'UNPAID'), 'Unpaid status missing.');
check(str_contains($pdf, 'GHS 79.00'), 'Invoice total missing.');
if (!is_dir($root . '/output/pdf')) mkdir($root . '/output/pdf', 0777, true);
file_put_contents($root . '/output/pdf/example-invoice.pdf', $pdf);

$long = $request;
if (!is_dir($root . '/tmp/pdfs')) mkdir($root . '/tmp/pdfs', 0777, true);
$long['items'] = array_map(fn ($item) => ['id' => $item['id'], 'service' => 'fold', 'quantity' => 1], $catalog);
$longPdf = invoicePdf(invoiceOrder($long, $catalog), $root . '/assets/images/dripclean-logo.png');
check(str_contains($longPdf, '/Count ' . (int)ceil(count($catalog) / 15)), 'All catalogue entries should appear across the required invoice pages.');
file_put_contents($root . '/tmp/pdfs/invoice-multipage.pdf', $longPdf);

$mobile = $request;
$mobile['paymentMethod'] = 'Mobile Money';
$mobile['paymentStatus'] = 'Paid';
$mobileOrder = invoiceOrder($mobile, $catalog);
$mobilePdf = invoicePdf($mobileOrder, $root . '/assets/images/dripclean-logo.png');
check($mobileOrder['paymentMethod'] === 'Mobile Money', 'Mobile Money selection was lost.');
check(str_contains($mobilePdf, '(Mobile Money)'), 'Invoice payment label is incorrect.');
check(str_contains($mobilePdf, 'UNPAID - payment not yet verified'), 'Unverified payment must stay unpaid.');
check(!str_contains($mobilePdf, 'payment due at pickup'), 'Cash instructions must not appear on a Mobile Money invoice.');
file_put_contents($root . '/tmp/pdfs/mobile-money-invoice.pdf', $mobilePdf);
echo "PASS: catalogue totals, invalid quantities, quote-only items, cash and Mobile Money, logo, unpaid status and pagination.\n";
