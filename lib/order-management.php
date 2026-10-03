<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
function savedOrder(string $id, ?string $customerId = null): ?array {
    $row = databaseSnapshot()['orders'][$id] ?? null;
    return $row && ($customerId === null || $row['customer_id'] === $customerId) ? $row['payload'] : null;
}
function orderVersion(array $order): string {
    return hash('sha256', json_encode($order, JSON_THROW_ON_ERROR));
}
function createSavedOrder(string $customerId, array $order): void {
    databaseTransaction(function (array &$store) use ($customerId, $order): void {
        $id = $order['id'];
        if (isset($store['orders'][$id])) throw new InvalidArgumentException('This order number is already saved. Reload your dashboard before trying again.');
        $knownCustomer = false;
        foreach ($store['customers'] as $customer) if ($customer['id'] === $customerId) { $knownCustomer = true; break; }
        if (!$knownCustomer) throw new InvalidArgumentException('Log in again before saving an order.');
        $store['orders'][$id] = ['customer_id' => $customerId, 'created_at' => gmdate('Y-m-d H:i:s'), 'payload' => $order];
    });
}
function customerOrders(string $customerId): array {
    $rows = array_filter(databaseSnapshot()['orders'], fn($row) => $row['customer_id'] === $customerId);
    uasort($rows, fn($a, $b) => [$a['created_at'], $a['payload']['id']] <=> [$b['created_at'], $b['payload']['id']]);
    return array_map(fn($row) => $row['payload'], $rows);
}
function ownerOrders(string $search, int $page): array {
    $store = databaseSnapshot(); $matching = [];
    foreach ($store['customers'] as $customer) {
        foreach (['name', 'phone', 'email'] as $field) {
            if ($search === '' || mb_stripos($customer[$field] ?? '', $search) !== false) { $matching[$customer['id']] = true; break; }
        }
    }
    $rows = array_filter($store['orders'], fn($row) => $search === '' || stripos($row['payload']['id'], $search) !== false || isset($matching[$row['customer_id']]));
    uasort($rows, fn($a, $b) => [$b['created_at'], $b['payload']['id']] <=> [$a['created_at'], $a['payload']['id']]);
    $offset = (max(1, $page) - 1) * 20;
    return ['orders' => array_map(fn($row) => $row['payload'], array_slice($rows, $offset, 20, true)), 'more' => count($rows) > $offset + 20];
}
function updateOrderStatus(string $id, string $status, string $payment, string $version): void {
    if (!in_array($status, ['Received', 'Being cleaned', 'Ready', 'Delivered', 'Cancelled'], true) || !in_array($payment, ['Unpaid', 'Paid', 'Refunded'], true)) throw new InvalidArgumentException('Choose a valid order and payment status.');
    databaseTransaction(function (array &$store) use ($id, $status, $payment, $version): void {
    if (!isset($store['orders'][$id])) throw new InvalidArgumentException('Order not found.');
    $order = $store['orders'][$id]['payload'];
    if (!hash_equals(orderVersion($order), $version)) throw new InvalidArgumentException('This order changed in another window. Refresh before updating.');
    $previousPayment = $order['paymentStatus'] ?? 'Unpaid';
    if (($order['paymentProvider'] ?? '') === 'Paystack' && $payment === 'Unpaid') throw new InvalidArgumentException('A verified Paystack payment cannot be changed to unpaid. If needed, refund it in Paystack and record the refund here.');
    $order['status'] = $status; $order['paymentStatus'] = $payment; $order['updatedAt'] = gmdate('c');
    $order['amountPaid'] = $payment === 'Paid' ? (float)$order['total'] : 0;
    $order['balanceDue'] = ($payment === 'Paid' || $payment === 'Refunded') ? 0 : (float)$order['total'];
    if ($previousPayment !== $payment) {
        $order['paymentHistory'][] = ['from' => $previousPayment, 'to' => $payment, 'amount' => (float)$order['total'], 'confirmedAt' => gmdate('c')];
    }
    if ($payment === 'Paid') $order['paidAt'] = $previousPayment === 'Paid' ? ($order['paidAt'] ?? gmdate('c')) : gmdate('c');
    else unset($order['paidAt']);
    $store['orders'][$id]['payload'] = $order;
    });
}
