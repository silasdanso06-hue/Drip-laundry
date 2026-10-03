<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/database.php';
require dirname(__DIR__) . '/lib/order-management.php';
$email = 'test-' . bin2hex(random_bytes(6)) . '@example.invalid';
$id = 'DC-TEST-' . strtoupper(bin2hex(random_bytes(6)));
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15, CURLOPT_INTERFACE => '127.0.0.2']);
function requestTest(string $endpoint, ?array $body = null): array {
    global $curl;
    curl_setopt($curl, CURLOPT_URL, 'http://127.0.0.1/Driplaudary/' . $endpoint);
    curl_setopt($curl, CURLOPT_POST, $body !== null);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($curl);
    $data = json_decode((string)$raw, true);
    if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200 || !is_array($data)) throw new RuntimeException('HTTP test failed at ' . $endpoint . ': ' . $raw);
    return $data;
}
function formTest(array $fields): void {
    global $curl;
    curl_setopt_array($curl, [
        CURLOPT_URL => 'http://127.0.0.1/Driplaudary/account.php',
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($fields)
    ]);
    $body = curl_exec($curl);
    if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 303 || !str_ends_with(curl_getinfo($curl, CURLINFO_REDIRECT_URL), 'index.html#dashboard')) {
        throw new RuntimeException('Account form did not redirect to the dashboard.');
    }
}
try {
    $session = requestTest('customer.php');
    formTest(['action' => 'signup', 'csrf' => $session['csrf'], 'email' => $email, 'name' => 'Database test', 'password' => 'My chosen test password', 'confirm' => 'My chosen test password']);
    $account = requestTest('customer.php');
    if (empty($account['user']['id'])) throw new RuntimeException('Signup failed.');
    $catalog = requestTest('catalog.php')['catalog'];
    $product = array_values(array_filter($catalog, fn($p) => $p['fold'] !== null && $p['fold'] > 0))[0];
    $quantity = min(99, max(1, (int)ceil(200 / $product['fold'])));
    $subtotal = round($product['fold'] * $quantity, 2);
    $discountRate = $subtotal >= 200 ? 10 : ($subtotal > 100 ? 5 : 0);
    $discount = round($subtotal * $discountRate / 100, 2);
    $expectedTotal = round($subtotal - $discount, 2);
    $request = ['id' => $id, 'name' => 'Database test', 'phone' => '0241234567', 'location' => 'Test address', 'date' => date('Y-m-d', time()+86400), 'paymentMethod' => 'Cash on pickup', 'items' => [['id' => $product['id'], 'service' => 'fold', 'quantity' => $quantity]], 'total' => $expectedTotal, 'subtotal' => 1, 'discountRate' => 99, 'discountAmount' => 999];
    curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1/Driplaudary/orders.php', CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['csrf' => $account['csrf'], 'order' => array_replace($request, ['total' => .01])])]);
    curl_exec($curl);
    if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 400) throw new RuntimeException('Tampered discounted total was accepted.');
    $retired = $request;
    $retired['items'][0]['service'] = 'iron';
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['csrf' => $account['csrf'], 'order' => $retired]));
    $retiredResult = json_decode((string)curl_exec($curl), true);
    if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 400 || !str_contains($retiredResult['error'] ?? '', 'no longer available')) {
        throw new RuntimeException('Removed ironing service was not rejected.');
    }
    $saved = requestTest('orders.php', ['csrf' => $account['csrf'], 'order' => $request]);
    if (!isset($saved['orders'][$id])) throw new RuntimeException('Order not persisted.');
    $savedOrder = $saved['orders'][$id];
    if ((float)$savedOrder['subtotal'] !== $subtotal || (float)$savedOrder['discountAmount'] !== $discount || $savedOrder['discountRate'] !== $discountRate || (float)$savedOrder['total'] !== $expectedTotal) throw new RuntimeException('Server discount calculation or storage failed.');
    $out = requestTest('customer.php', ['action' => 'logout', 'csrf' => $account['csrf']]);
    formTest(['action' => 'login', 'csrf' => $out['csrf'], 'email' => $email, 'password' => 'My chosen test password']);
    $login = requestTest('customer.php');
    $loaded = requestTest('orders.php');
    if (!isset($loaded['orders'][$id])) throw new RuntimeException('Order missing after login.');
    $version=orderVersion(savedOrder($id));
    updateOrderStatus($id,'Ready','Paid',$version);
    try { updateOrderStatus($id,'Delivered','Paid',$version); throw new RuntimeException('Stale update accepted.'); } catch(InvalidArgumentException $expected) {}
    $loaded=requestTest('orders.php');
    if($loaded['orders'][$id]['status']!=='Ready'||$loaded['orders'][$id]['paymentStatus']!=='Paid')throw new RuntimeException('Updated statuses were not visible to customer.');
    $paid=$loaded['orders'][$id];
    if((float)$paid['amountPaid']!==(float)$paid['total'] || (float)$paid['balanceDue']!==0.0 || empty($paid['paidAt']) || count($paid['paymentHistory'])!==1) throw new RuntimeException('Full payment amount, balance or audit record missing.');
    curl_setopt_array($curl,[CURLOPT_URL=>'http://127.0.0.1/Driplaudary/invoice.php',CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['id'=>$id,'total'=>0,'name'=>'Tampered'])]);
    $pdf=curl_exec($curl);
    if(curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200||!str_starts_with($pdf,'%PDF')||!str_contains($pdf,'PAID - payment confirmed')||str_contains($pdf,'Tampered'))throw new RuntimeException('Saved invoice or payment status failed.');
    if(savedOrder($id,'different-customer')!==null)throw new RuntimeException('Cross-customer order access allowed.');
    requestTest('customer.php', ['action' => 'logout', 'csrf' => $login['csrf']]);
    curl_setopt($curl, CURLOPT_URL, 'http://127.0.0.1/Driplaudary/orders.php');
    curl_setopt($curl, CURLOPT_POST, false);
    curl_exec($curl);
    if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 401) throw new RuntimeException('Logged-out orders must be rejected.');
    echo "PASS: signup/login redirects, discounted orders, tamper rejection, status/payment updates, saved paid invoices, customer isolation and logout.\n";
} finally {
    databaseTransaction(function(array &$store) use($id,$email):void { unset($store['orders'][$id], $store['customers'][$email]); });
    curl_close($curl);
}
