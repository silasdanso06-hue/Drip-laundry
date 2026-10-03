<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = dirname(__DIR__) . '/tmp/payments-' . bin2hex(random_bytes(6));
define('DATA_DIRECTORY', $fixture); define('OWNER_DIRECTORY', $fixture);
require dirname(__DIR__) . '/lib/payments.php';
function payCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function payReject(callable $action): void {
    try { $action(); } catch (InvalidArgumentException | RuntimeException $expected) { return; }
    throw new RuntimeException('Unsafe payment request succeeded.');
}
function payTestOrder(string $id): void {
    createSavedOrder('customer-1', ['id' => $id, 'total' => 25, 'paymentStatus' => 'Unpaid', 'status' => 'Received', 'paymentMethod' => 'Online payment']);
}
try {
    databaseInitialize(databaseEmptyStore());
    databaseWrite('customers', ['233201234567' => ['id' => 'customer-1', 'name' => 'Payment test', 'email' => '', 'phone' => '233201234567', 'hash' => password_hash('Test password', PASSWORD_DEFAULT)]]);
    payTestOrder('DC-PAYMENT-TEST');
    payReject(fn() => paymentStart('DC-PAYMENT-TEST', 'customer-1', 'test@example.invalid'));
    payReject(fn() => paymentSaveSettings(['enabled' => '1', 'mode' => 'test', 'site_url' => 'http://localhost/Driplaudary']));
    $testKey = 'sk_test_' . str_repeat('a', 40); $liveKey = 'sk_live_' . str_repeat('b', 40);
    payReject(fn() => paymentSaveSettings(['enabled' => '1', 'mode' => 'live', 'site_url' => 'http://localhost/Driplaudary', 'secret' => $liveKey]));
    paymentSaveSettings(['enabled' => '1', 'mode' => 'test', 'site_url' => 'http://localhost/Driplaudary', 'secret' => $testKey]);
    payCheck(array_keys(paymentPublicSettings()) === ['enabled', 'mode'], 'Public settings leaked a secret.');
    payCheck(!paymentCheckoutUrl('https://checkout.paystack.com.evil.invalid/x') && !paymentCheckoutUrl('https://evil.invalid@checkout.paystack.com/x'), 'Unsafe checkout URL allowed.');
    $initializations = 0;
    $init = function($method, $path, $payload, $key) use (&$initializations): array {
        $initializations++;
        payCheck($method === 'POST' && $path === '/transaction/initialize' && $payload['amount'] === '2500' && $payload['currency'] === 'GHS', 'Checkout did not use stored total in pesewas.');
        payCheck($payload['channels'] === ['card', 'mobile_money'] && str_ends_with($payload['callback_url'], '/payment-return.php'), 'Checkout channels or return URL missing.');
        return ['reference' => $payload['reference'], 'authorization_url' => 'https://checkout.paystack.com/test-session'];
    };
    payReject(fn() => paymentStart('DC-PAYMENT-TEST', 'another-customer', 'test@example.invalid', $init));
    payReject(fn() => paymentStart('DC-PAYMENT-TEST', 'customer-1', '', $init));
    $test = paymentStart('DC-PAYMENT-TEST', 'customer-1', 'test@example.invalid', $init);
    payCheck(paymentStart('DC-PAYMENT-TEST', 'customer-1', 'test@example.invalid', $init)['reference'] === $test['reference'] && $initializations === 1, 'Double click initialized two payments.');
    $response = ['id' => '1001', 'domain' => 'test', 'reference' => $test['reference'], 'amount' => 2500, 'currency' => 'GHS', 'status' => 'success', 'channel' => 'mobile_money'];
    $verify = function($method, $path, $payload, $key) use (&$response): array { payCheck($method === 'GET', 'Verification must use GET.'); return $response; };
    payReject(fn() => paymentVerify($test['reference'], 'another-customer', $verify, true));
    foreach (['amount' => 1, 'currency' => 'USD', 'domain' => 'live', 'reference' => 'forged'] as $field => $bad) {
        $original = $response[$field]; $response[$field] = $bad;
        payReject(fn() => paymentVerify($test['reference'], 'customer-1', $verify, true));
        payCheck(savedOrder('DC-PAYMENT-TEST')['paymentStatus'] === 'Unpaid', 'Mismatched verification marked paid.');
        $response[$field] = $original;
    }
    $confirmed = paymentVerify($test['reference'], 'customer-1', $verify, true);
    payCheck($confirmed['status'] === 'test_success' && savedOrder('DC-PAYMENT-TEST')['paymentStatus'] === 'Unpaid', 'Test payment credited real money.');

    paymentSaveSettings(['enabled' => '1', 'mode' => 'live', 'site_url' => 'https://laundry.example.com', 'secret' => $liveKey]);
    payTestOrder('DC-LIVE-TEST'); $live = paymentStart('DC-LIVE-TEST', 'customer-1', 'test@example.invalid', $init);
    $response = array_replace($response, ['domain' => 'live', 'reference' => $live['reference'], 'id' => '2001', 'status' => 'pending']);
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $live['reference'], 'amount' => 1]]);
    payReject(fn() => paymentWebhook($body, str_repeat('0', 128), $verify));
    paymentWebhook($body, hash_hmac('sha512', $body, $liveKey), $verify);
    payCheck(savedOrder('DC-LIVE-TEST')['paymentStatus'] === 'Unpaid', 'Webhook success was trusted without API confirmation.');
    // Rotation must not break verification of an existing transaction.
    paymentSaveSettings(['enabled' => '1', 'mode' => 'live', 'site_url' => 'https://laundry.example.com', 'secret' => 'sk_live_' . str_repeat('c', 40)]);
    $response['status'] = 'success';
    paymentWebhook($body, hash_hmac('sha512', $body, $liveKey), $verify);
    $paid = savedOrder('DC-LIVE-TEST');
    payCheck($paid['paymentStatus'] === 'Paid' && (float)$paid['amountPaid'] === 25.0 && $paid['balanceDue'] === 0 && count($paid['paymentHistory']) === 1, 'Verified payment details missing.');
    paymentWebhook($body, hash_hmac('sha512', $body, $liveKey), $verify);
    payCheck(count(savedOrder('DC-LIVE-TEST')['paymentHistory']) === 1, 'Duplicate webhook credited twice.');
    payReject(fn() => paymentStart('DC-LIVE-TEST', 'customer-1', 'test@example.invalid', $init));
    payReject(fn() => updateOrderStatus('DC-LIVE-TEST', 'Ready', 'Unpaid', orderVersion($paid)));
    updateOrderStatus('DC-LIVE-TEST', 'Cancelled', 'Refunded', orderVersion($paid));
    paymentWebhook($body, hash_hmac('sha512', $body, $liveKey), $verify);
    payCheck(savedOrder('DC-LIVE-TEST')['paymentStatus'] === 'Refunded', 'Repeated webhook undid the refund record.');
    payCheck(str_contains(paymentResultMessage(paymentLatest('DC-LIVE-TEST', 'customer-1')), 'refunded'), 'Customer payment page hid the recorded refund.');

    payTestOrder('DC-FAILED-TEST'); $failed = paymentStart('DC-FAILED-TEST', 'customer-1', 'test@example.invalid', $init);
    $response = array_replace($response, ['reference' => $failed['reference'], 'id' => '2002', 'status' => 'failed']);
    paymentVerify($failed['reference'], 'customer-1', $verify, true);
    payCheck(savedOrder('DC-FAILED-TEST')['paymentStatus'] === 'Unpaid', 'Failed payment marked paid.');
    payCheck(paymentStart('DC-FAILED-TEST', 'customer-1', 'test@example.invalid', $init)['reference'] !== $failed['reference'], 'Confirmed failure cannot be retried.');
    payTestOrder('DC-TIMEOUT-TEST');
    payReject(fn() => paymentStart('DC-TIMEOUT-TEST', 'customer-1', 'test@example.invalid', function() { throw new RuntimeException('Simulated timeout'); }));
    $before = $initializations;
    payReject(fn() => paymentStart('DC-TIMEOUT-TEST', 'customer-1', 'test@example.invalid', $init));
    payCheck($initializations === $before, 'Uncertain timeout created another payment.');
    payTestOrder('DC-CANCEL-TEST'); $cancel = paymentStart('DC-CANCEL-TEST', 'customer-1', 'test@example.invalid', $init);
    updateOrderStatus('DC-CANCEL-TEST', 'Cancelled', 'Unpaid', orderVersion(savedOrder('DC-CANCEL-TEST')));
    $response = array_replace($response, ['reference' => $cancel['reference'], 'id' => '2003', 'status' => 'success']);
    payCheck(paymentVerify($cancel['reference'], 'customer-1', $verify, true)['status'] === 'paid_review' && savedOrder('DC-CANCEL-TEST')['status'] === 'Cancelled', 'Late payment resurrected a cancelled order.');
    echo "PASS: Paystack checkout, stored amounts, ownership, signature/API verification, mismatches, test/live separation, key rotation, duplicate events, refunds, failures, timeouts and late payments. All provider calls mocked; no money charged.\n";
} finally {
    foreach (glob($fixture . '/*') ?: [] as $file) unlink($file);
    if (is_dir($fixture)) { unlink($fixture . '/.htaccess'); rmdir($fixture); }
}
