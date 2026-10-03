<?php
declare(strict_types=1);
require __DIR__ . '/lib/payments.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
try { echo json_encode(paymentPublicSettings(), JSON_THROW_ON_ERROR); }
catch (Throwable $e) { http_response_code(503); echo '{"enabled":false}'; }
