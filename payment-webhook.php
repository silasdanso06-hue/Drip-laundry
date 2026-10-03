<?php
declare(strict_types=1);
require __DIR__ . '/lib/payments.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) { http_response_code(413); exit; }
$body = file_get_contents('php://input', false, null, 0, 262145);
if ($body === false || strlen($body) > 262144) { http_response_code(413); exit; }
try { paymentWebhook($body, (string)($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '')); echo '{"received":true}'; }
catch (InvalidArgumentException | JsonException $e) { http_response_code(400); echo '{"error":"Invalid webhook"}'; }
catch (Throwable $e) { http_response_code(503); echo '{"error":"Please retry"}'; }
