<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $fixture = $root . '/tmp/discounts-' . bin2hex(random_bytes(6));
define('DATA_DIRECTORY', $fixture);
require $root . '/lib/invoice.php';
require $root . '/lib/payments.php';
function discountCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$request = ['id' => 'DC-DISCOUNT-TEST', 'name' => 'Discount test', 'phone' => '0200000000', 'location' => 'Test address', 'date' => date('Y-m-d'), 'paymentMethod' => 'Online payment', 'items' => [['id' => 'test', 'service' => 'fold', 'quantity' => 1]], 'subtotal' => 0, 'discountRate' => 99, 'discountAmount' => 999, 'total' => 1];
try {
    $cases = json_decode(file_get_contents(__DIR__ . '/discount-cases.json'), true, 16, JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
        $catalog = [['id' => 'test', 'name' => 'Laundry', 'category' => 'Test', 'fold' => $case['subtotalMinor'] / 100, 'iron' => null]];
        $order = invoiceOrder($request, $catalog);
        discountCheck((int)round($order['subtotal'] * 100) === $case['subtotalMinor'] && $order['discountRate'] === $case['rate'] && (int)round($order['discountAmount'] * 100) === $case['discountMinor'] && (int)round($order['total'] * 100) === $case['totalMinor'], 'Threshold or rounding failed: ' . $case['subtotalMinor']);
    }
    databaseInitialize(databaseEmptyStore());
    databaseWrite('customers', ['test@example.invalid' => ['id' => 'test-customer', 'name' => 'Test', 'email' => 'test@example.invalid', 'phone' => '', 'hash' => password_hash('Test password', PASSWORD_DEFAULT)]]);
    $catalog = [['id' => 'test', 'name' => 'Laundry', 'category' => 'Test', 'fold' => 200, 'iron' => null]];
    $order = invoiceOrder($request, $catalog) + ['status' => 'Received', 'paymentStatus' => 'Unpaid'];
    createSavedOrder('test-customer', $order);
    discountCheck(savedOrder($order['id'])['discountAmount'] == 20 && paymentAmount(savedOrder($order['id'])) === 18000, 'Saved discount or amount due incorrect.');
    paymentSaveSettings(['enabled' => '1', 'mode' => 'test', 'site_url' => 'http://localhost/Driplaudary', 'secret' => 'sk_test_' . str_repeat('a', 40)]);
    $attempt = paymentStart($order['id'], 'test-customer', 'test@example.invalid', function ($method, $path, $payload) {
        discountCheck($payload['amount'] === '18000', 'Paystack received the subtotal instead of the discounted total.');
        return ['reference' => $payload['reference'], 'authorization_url' => 'https://checkout.paystack.com/mock-discount'];
    });
    paymentVerify($attempt['reference'], 'test-customer', fn() => ['id' => '123', 'domain' => 'test', 'status' => 'success', 'reference' => $attempt['reference'], 'amount' => 18000, 'currency' => 'GHS'], true);
    updateOrderStatus($order['id'], 'Ready', 'Paid', orderVersion(savedOrder($order['id'])));
    discountCheck(savedOrder($order['id'])['amountPaid'] === 180.0, 'Admin payment did not use discounted amount.');
    $pdf = invoicePdf(savedOrder($order['id']), $root . '/assets/images/dripclean-logo.png');
    discountCheck(str_contains($pdf, 'SUBTOTAL') && str_contains($pdf, 'DISCOUNT \\(10%\\)') && str_contains($pdf, 'GHS 200.00') && str_contains($pdf, '- GHS 20.00') && str_contains($pdf, 'GHS 180.00'), 'Discount breakdown missing from invoice.');
    if (!is_dir($root . '/tmp/pdfs')) mkdir($root . '/tmp/pdfs', 0700, true);
    file_put_contents($root . '/tmp/pdfs/discount-invoice.pdf', $pdf);
    // Exercise a full final page and a page break with the larger totals block.
    foreach ([15, 16] as $count) {
        $many = $order; $many['lines'] = array_fill(0, $count, $order['lines'][0]);
        $many = array_replace($many, orderTotals(20000 * $count));
        $manyPdf = invoicePdf($many, $root . '/assets/images/dripclean-logo.png');
        discountCheck(str_contains($manyPdf, 'UNPAID - payment not yet verified') && !str_contains($manyPdf, 'payment due at pickup'), 'Online payment invoice must await verification.');
        discountCheck(str_contains($manyPdf, '/Count ' . ($count === 15 ? 1 : 2)), 'Discount totals pagination failed.');
        file_put_contents($root . '/tmp/pdfs/discount-' . $count . '-lines.pdf', $manyPdf);
    }
    $legacy = $order; unset($legacy['subtotal'], $legacy['discountRate'], $legacy['discountAmount']); $legacy['total'] = 200;
    discountCheck(str_contains(invoicePdf($legacy, $root . '/assets/images/dripclean-logo.png'), 'GHS 200.00') && paymentAmount($legacy) === 20000, 'An older order was repriced.');
    echo "PASS: discount boundaries, rounding, untrusted totals, saved records, Paystack amount, admin payment, invoices and legacy totals. No real payment sent.\n";
} finally {
    foreach (glob($fixture . '/*') ?: [] as $file) unlink($file);
    if (is_dir($fixture)) { unlink($fixture . '/.htaccess'); rmdir($fixture); }
}
