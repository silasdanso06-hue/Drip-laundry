<?php
declare(strict_types=1);
require __DIR__ . '/lib/invoice.php';
require __DIR__ . '/lib/customer.php';
require __DIR__ . '/lib/order-management.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
    http_response_code(413);
    exit;
}
try {
    customerSession();
    if (empty($_SESSION['user'])) { http_response_code(401); throw new InvalidArgumentException('Sign in to download your invoice.'); }
    $request = json_decode(file_get_contents('php://input', false, null, 0, 32769), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new InvalidArgumentException('Invalid invoice request.');
    }
    $order = savedOrder(is_string($request['id'] ?? null) ? $request['id'] : '', $_SESSION['user']['id']);
    if (!$order) { http_response_code(404); throw new InvalidArgumentException('Order not found in your account.'); }
    $pdf = invoicePdf($order, __DIR__ . '/assets/images/dripclean-logo.png');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $order['id'] . '-invoice.pdf"');
    echo $pdf;
} catch (InvalidArgumentException | JsonException $error) {
    if (http_response_code() < 400) http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invoice could not be created. Please try again.']);
}
