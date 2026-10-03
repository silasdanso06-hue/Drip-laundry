<?php
declare(strict_types=1);
require __DIR__ . '/lib/catalog.php';
require_once __DIR__ . '/lib/discounts.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'This price list is read-only.']);
    exit;
}
try {
    echo json_encode(catalogSnapshot() + ['discountPolicy' => discountPolicy()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['error' => 'Prices are temporarily unavailable. Please try again.']);
}
