<?php
declare(strict_types=1);
require_once __DIR__ . '/order-management.php';

class PaymentRejected extends RuntimeException {}

function paymentSettings(): array {
    return databaseRead('payment-settings') ?? ['enabled' => false, 'mode' => 'test', 'site_url' => '', 'secret' => ''];
}

function paymentSaveSettings(array $input): void {
    $mode = $input['mode'] ?? 'test';
    if (!in_array($mode, ['test', 'live'], true)) throw new InvalidArgumentException('Choose test or live mode.');
    $previous = paymentSettings();
    $secret = trim(is_string($input['secret'] ?? null) ? $input['secret'] : '');
    if ($secret === '') $secret = $previous['secret'] ?? '';
    $enabled = ($input['enabled'] ?? '') === '1';
    $url = rtrim(trim(is_string($input['site_url'] ?? null) ? $input['site_url'] : ''), '/');
    if ($secret !== '' && !preg_match('/^sk_' . $mode . '_[a-zA-Z0-9]{16,128}$/D', $secret)) throw new InvalidArgumentException('Enter the Paystack secret key for the selected mode.');
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    if ($url !== '') {
        if (strlen($url) > 300 || !filter_var($url, FILTER_VALIDATE_URL) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) throw new InvalidArgumentException('Enter your site base URL without query parameters or a login.');
        if (($parts['scheme'] ?? '') !== 'https' && !($mode === 'test' && $local && ($parts['scheme'] ?? '') === 'http')) throw new InvalidArgumentException('Use HTTPS for your website, or HTTP localhost for testing.');
        if ($mode === 'live' && ($local || !str_contains($host, '.') || str_ends_with($host, '.local') || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)))) throw new InvalidArgumentException('Live payments need your public HTTPS website address.');
    }
    if ($enabled && ($secret === '' || $url === '')) throw new InvalidArgumentException('Save a Paystack secret key and website URL before enabling payments.');
    databaseWrite('payment-settings', ['enabled' => $enabled, 'mode' => $mode, 'site_url' => $url, 'secret' => $secret]);
}

function paymentPublicSettings(): array {
    $s = paymentSettings();
    return ['enabled' => !empty($s['enabled']) && !empty($s['secret']) && !empty($s['site_url']), 'mode' => $s['mode'] ?? 'test'];
}

function paymentAmount(array $order): int {
    $total = $order['total'] ?? null;
    if (!is_numeric($total) || !is_finite((float)$total) || $total <= 0 || $total > 10000000) throw new InvalidArgumentException('This order cannot be paid online. Contact us for help.');
    return (int)round((float)$total * 100);
}

function paystackRequest(string $method, string $path, array $payload, string $secret, ?callable $transport = null): array {
    if ($transport) return $transport($method, $path, $payload, $secret);
    $curl = curl_init('https://api.paystack.co' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'Content-Type: application/json', 'Accept: application/json']]);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR)]);
    $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if ($raw === false) throw new RuntimeException('Paystack could not be reached. Check payment status before trying to pay again.');
    $response = json_decode($raw, true);
    if ($status >= 400 && $status < 500 && is_array($response) && ($response['status'] ?? null) === false) throw new PaymentRejected('Paystack could not accept this request. Please check the payment settings or contact us.');
    if ($status !== 200 || !is_array($response) || ($response['status'] ?? false) !== true || !is_array($response['data'] ?? null)) throw new RuntimeException('Paystack has not confirmed this request. Check payment status shortly.');
    return $response['data'];
}

function paymentReferenceValid(string $reference): bool {
    return (bool)preg_match('/^DC-PAY-[A-F0-9]{32}$/D', $reference);
}

function paymentCheckoutUrl(string $url): bool {
    $p = parse_url($url);
    return ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === 'checkout.paystack.com' && !isset($p['user']) && !isset($p['pass']) && !isset($p['port']) && !preg_match('/[\r\n]/', $url);
}

function paymentLatest(string $orderId, string $customerId): ?array {
    $rows = array_reverse(databaseRead('payment-attempts') ?? []);
    foreach ($rows as $row) if ($row['order_id'] === $orderId && $row['customer_id'] === $customerId) return $row;
    return null;
}

function paymentStart(string $orderId, string $customerId, string $email, ?callable $transport = null): array {
    $s = paymentSettings();
    if (!paymentPublicSettings()['enabled']) throw new InvalidArgumentException('Online payments are not available yet. You can still arrange payment with us.');
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) throw new InvalidArgumentException('Enter an email address for your payment receipt.');
    $reservation = databaseTransaction(function (array &$store) use ($orderId, $customerId, $email, $s): array {
        $row = $store['orders'][$orderId] ?? null;
        if (!$row || $row['customer_id'] !== $customerId) throw new InvalidArgumentException('Order not found in your account.');
        $order = $row['payload'];
        if (($order['paymentStatus'] ?? 'Unpaid') !== 'Unpaid' || ($order['status'] ?? '') === 'Cancelled') throw new InvalidArgumentException('This order is already paid, refunded or cancelled.');
        $attempts = $store['application_data']['payment-attempts'] ?? [];
        foreach (array_reverse($attempts) as $attempt) {
            if ($attempt['order_id'] !== $orderId || $attempt['mode'] !== $s['mode']) continue;
            if (in_array($attempt['status'], ['initializing', 'pending', 'unknown'], true)) return ['new' => false, 'attempt' => $attempt];
        }
        $reference = 'DC-PAY-' . strtoupper(bin2hex(random_bytes(16)));
        $keyId = hash('sha256', $s['secret']);
        $attempt = ['reference' => $reference, 'order_id' => $orderId, 'customer_id' => $customerId, 'email' => $email,
            'amount_minor' => paymentAmount($order), 'currency' => 'GHS', 'mode' => $s['mode'], 'key_id' => $keyId,
            'status' => 'initializing', 'created_at' => gmdate('c'), 'authorization_url' => ''];
        $store['application_data']['payment-keys'][$keyId] = $s['secret'];
        $store['application_data']['payment-attempts'][$reference] = $attempt;
        return ['new' => true, 'attempt' => $attempt];
    });
    $attempt = $reservation['attempt']; $reference = $attempt['reference'];
    if (!$reservation['new']) {
        if (!empty($attempt['authorization_url']) && paymentCheckoutUrl($attempt['authorization_url'])) return $attempt;
        throw new InvalidArgumentException('A payment request is already being checked. Use Check payment status before trying again, or contact us with your order number.');
    }
    try {
        $data = paystackRequest('POST', '/transaction/initialize', ['email' => $email, 'amount' => (string)$attempt['amount_minor'],
            'currency' => 'GHS', 'reference' => $reference, 'callback_url' => $s['site_url'] . '/payment-return.php',
            'channels' => ['card', 'mobile_money'], 'metadata' => ['order_id' => $orderId]], $s['secret'], $transport);
        if (($data['reference'] ?? '') !== $reference || !is_string($data['authorization_url'] ?? null) || !paymentCheckoutUrl($data['authorization_url'])) throw new RuntimeException('Paystack returned an invalid checkout link. Check payment status or contact us.');
        return databaseTransaction(function (array &$store) use ($reference, $data): array {
            $a = &$store['application_data']['payment-attempts'][$reference];
            if ($a['status'] === 'initializing') $a['status'] = 'pending';
            $a['authorization_url'] = $data['authorization_url'];
            return $a;
        });
    } catch (Throwable $error) {
        databaseTransaction(function (array &$store) use ($reference, $error): void {
            $a = &$store['application_data']['payment-attempts'][$reference];
            if ($a['status'] === 'initializing') $a['status'] = $error instanceof PaymentRejected ? 'initialization_failed' : 'unknown';
        });
        throw $error;
    }
}

function paymentVerify(string $reference, ?string $customerId = null, ?callable $transport = null, bool $force = false): array {
    if (!paymentReferenceValid($reference)) throw new InvalidArgumentException('Invalid payment reference.');
    $reservation = databaseTransaction(function (array &$store) use ($reference, $customerId, $force): array {
        $attempt = $store['application_data']['payment-attempts'][$reference] ?? null;
        if (!$attempt || ($customerId !== null && $attempt['customer_id'] !== $customerId)) throw new InvalidArgumentException('Payment not found.');
        if (in_array($attempt['status'], ['paid', 'paid_review', 'review', 'test_success'], true) || (!$force && ($attempt['verify_after'] ?? 0) > time())) return ['check' => false, 'attempt' => $attempt];
        $store['application_data']['payment-attempts'][$reference]['verify_after'] = time() + 5;
        return ['check' => true, 'attempt' => $attempt];
    });
    $attempt = $reservation['attempt'];
    if (!$reservation['check']) return $attempt;
    $key = (databaseRead('payment-keys') ?? [])[$attempt['key_id']] ?? '';
    if ($key === '') throw new RuntimeException('The payment verification key is unavailable. Contact us.');
    $data = paystackRequest('GET', '/transaction/verify/' . rawurlencode($reference), [], $key, $transport);
    if (($data['reference'] ?? '') !== $reference || ($data['currency'] ?? '') !== 'GHS' || ($data['domain'] ?? '') !== $attempt['mode'] || !is_numeric($data['amount'] ?? null) || (float)$data['amount'] !== (float)$attempt['amount_minor']) throw new RuntimeException('Payment details did not match this order. Contact us to check the transaction.');
    $success = ($data['status'] ?? '') === 'success';
    if ($success && (!isset($data['id']) || !preg_match('/^[0-9]+$/D', (string)$data['id']))) throw new RuntimeException('Payment transaction ID was missing.');
    return databaseTransaction(function (array &$store) use ($reference, $data, $success): array {
        $a = &$store['application_data']['payment-attempts'][$reference];
        if (in_array($a['status'], ['paid', 'paid_review', 'review', 'test_success'], true)) return $a;
        $a['checked_at'] = gmdate('c');
        if (!$success) {
            $a['status'] = in_array($data['status'] ?? '', ['failed', 'abandoned', 'reversed'], true) ? $data['status'] : 'pending';
            return $a;
        }
        $transactionId = (string)$data['id'];
        foreach ($store['application_data']['payment-attempts'] as $other) {
            if ($other['reference'] !== $reference && ($other['transaction_id'] ?? null) === $transactionId && $other['key_id'] === $a['key_id']) throw new RuntimeException('This transaction is already linked to another payment.');
        }
        $order = &$store['orders'][$a['order_id']]['payload'];
        if (!is_array($order) || paymentAmount($order) !== $a['amount_minor']) throw new RuntimeException('Order changed. Contact us to reconcile the payment.');
        $a['transaction_id'] = $transactionId; $a['verified_at'] = gmdate('c');
        $a['channel'] = in_array($data['channel'] ?? '', ['card', 'mobile_money'], true) ? $data['channel'] : 'Paystack';
        if ($a['mode'] === 'test') { $a['status'] = 'test_success'; $order['testPaymentConfirmed'] = true; return $a; }
        if (($order['paymentStatus'] ?? 'Unpaid') !== 'Unpaid') {
            $a['status'] = 'review'; $order['paymentReview'] = 'An additional online payment needs review.';
            return $a;
        }
        $order['paymentStatus'] = 'Paid'; $order['amountPaid'] = $a['amount_minor'] / 100; $order['balanceDue'] = 0;
        $order['paidAt'] = gmdate('c'); $order['updatedAt'] = gmdate('c'); $order['paymentMethod'] = 'Paystack';
        $order['paymentProvider'] = 'Paystack'; $order['paymentReference'] = $reference;
        $order['paymentHistory'][] = ['from' => 'Unpaid', 'to' => 'Paid', 'amount' => $a['amount_minor'] / 100, 'confirmedAt' => gmdate('c'), 'source' => 'Paystack', 'reference' => $reference];
        $a['status'] = 'paid';
        if (($order['status'] ?? '') === 'Cancelled') { $order['paymentReview'] = 'Online payment arrived after cancellation.'; $a['status'] = 'paid_review'; }
        return $a;
    });
}

function paymentWebhook(string $body, string $signature, ?callable $transport = null): void {
    $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    $reference = $event['data']['reference'] ?? '';
    $attempt = is_string($reference) ? (databaseRead('payment-attempts') ?? [])[$reference] ?? null : null;
    $key = $attempt ? ((databaseRead('payment-keys') ?? [])[$attempt['key_id']] ?? '') : (paymentSettings()['secret'] ?? '');
    if ($key === '' || !preg_match('/^[a-f0-9]{128}$/Di', $signature) || !hash_equals(hash_hmac('sha512', $body, $key), strtolower($signature))) throw new InvalidArgumentException('Invalid webhook signature.');
    if (($event['event'] ?? '') === 'charge.success' && $attempt) paymentVerify($reference, null, $transport, true);
}

function paymentStatusMessage(string $status): string {
    $messages = [
        'paid' => 'Payment confirmed. Your order is paid in full.',
        'paid_review' => 'Payment received after cancellation. Please contact us to arrange a refund or confirm your order.',
        'review' => 'Payment received and needs review. Please contact us before making another payment.',
        'test_success' => 'Test payment completed. No real payment has been recorded for this order.',
        'failed' => 'Payment was unsuccessful. Your order is still unpaid.',
        'abandoned' => 'Payment was not completed. Your order is still unpaid.',
        'reversed' => 'Paystack reports that this payment was reversed. Please contact us.',
        'initialization_failed' => 'Checkout could not start. You can try again or contact us.'
    ];
    return $messages[$status] ?? 'Payment has not been confirmed yet. Complete checkout or check the status again shortly. If you were debited, do not pay again until the status is confirmed.';
}

function paymentResultMessage(array $attempt): string {
    $order = savedOrder($attempt['order_id']);
    if (($order['paymentReference'] ?? '') === $attempt['reference'] && ($order['paymentStatus'] ?? '') === 'Refunded') return 'This payment was confirmed and has now been recorded as refunded.';
    return paymentStatusMessage($attempt['status']);
}
