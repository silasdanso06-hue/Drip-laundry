<?php
declare(strict_types=1);
require __DIR__ . '/lib/payments.php';
// Do not start a new customer session here: Strict cookies are not sent on the external redirect.
header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$reference = is_string($_GET['reference'] ?? null) ? $_GET['reference'] : '';
try { $result = paymentVerify($reference); $message = paymentResultMessage($result); }
catch (InvalidArgumentException $e) { http_response_code(400); $message = 'We could not find this payment. Open My orders to check your saved order.'; }
catch (Throwable $e) { $message = 'Payment is not confirmed yet. If you were debited, do not pay again. Open My orders and choose Check payment status, or contact us.'; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Payment status | Dripclean</title><link rel="stylesheet" href="assets/css/admin.css"></head><body><main><h1>Payment status</h1><section class="customer-update"><p role="status"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><p><a href="index.html#dashboard">Return to My orders</a></p><p>Need help? Call <a href="tel:0508103264">0508103264</a>.</p></section></main></body></html>
