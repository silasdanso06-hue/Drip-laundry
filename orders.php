<?php
declare(strict_types=1);
require __DIR__ . '/lib/customer.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/invoice.php';
require_once __DIR__ . '/lib/order-management.php';
require_once __DIR__ . '/lib/payments.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    customerSession();
    $user = $_SESSION['user'] ?? null;
    if (!$user) { http_response_code(401); throw new InvalidArgumentException('Log in from the dashboard before saving or viewing orders.'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !ownerCsrfValid($input['csrf'] ?? null)) { http_response_code(403); throw new InvalidArgumentException('Your session expired. Reload and try again.'); }
        $request = $input['order'] ?? [];
        if (!is_array($request)) throw new InvalidArgumentException('Invalid order.');
        foreach (is_array($request['items'] ?? null) ? $request['items'] : [] as $item) {
            if (!is_array($item) || ($item['service'] ?? null) !== 'fold') {
                throw new InvalidArgumentException('This service is no longer available. Please choose wash and fold.');
            }
        }
        $order = invoiceOrder($request, catalogSnapshot()['catalog']);
        if ($order['paymentMethod'] === 'Online payment' && !paymentPublicSettings()['enabled']) throw new InvalidArgumentException('Online payments are not enabled yet. Choose cash on pickup or arrange Mobile Money with us.');
        if ($order['date'] < date('Y-m-d')) throw new InvalidArgumentException('Choose today or a future pickup date.');
        if (!isset($request['total']) || abs((float)$request['total'] - $order['total']) > .001) throw new InvalidArgumentException('Prices changed. Review your cart and try again.');
        $order += ['items' => $request['items'], 'status' => 'Received', 'paymentStatus' => 'Unpaid', 'createdAt' => gmdate('c')];
        createSavedOrder($user['id'], $order);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405); header('Allow: GET, POST'); throw new InvalidArgumentException('Method not allowed.');
    }
    $orders = customerOrders($user['id']);
    echo json_encode(['orders' => (object)$orders], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException | JsonException $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['error' => 'Orders could not be saved or loaded. Reload your dashboard to check whether your order was saved before retrying.']);
}
