<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/catalog.php';
databaseBackup();
$lock = fopen(databaseDirectory() . '/catalog.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock prices.');
try {
    $catalog = databaseTransaction(function (array &$store): array {
        $catalog = array_values(array_filter($store['application_data']['catalog'],
            fn($item) => ($item['unit'] ?? '') !== 'load' && $item['category'] !== 'Laundry by weight'));
        $store['application_data']['catalog'] = $catalog;
        return $catalog;
    });
    file_put_contents(dirname(__DIR__) . '/assets/data/catalog.json', json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    echo "Removed weight packages; other prices and saved orders retained.\n";
} finally { flock($lock, LOCK_UN); fclose($lock); }
