<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/catalog.php';
databaseBackup();
$lock = fopen(databaseDirectory() . '/catalog.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock prices.');
try {
    $catalog = databaseTransaction(function (array &$store): array {
        $catalog = $store['application_data']['catalog'];
        foreach (['item-2-1' => 'Doormat', 'item-2-2' => 'Shoe'] as $id => $name) {
            $item = ['id' => $id, 'category' => 'Doormats & shoes', 'name' => $name, 'fold' => 20];
            $found = false;
            foreach ($catalog as &$existing) {
                if ($existing['id'] === $id) { $existing = $item; $found = true; break; }
            }
            unset($existing);
            if (!$found) $catalog[] = $item;
        }
        $store['application_data']['catalog'] = $catalog;
        return $catalog;
    });
    $path = dirname(__DIR__) . '/assets/data/catalog.json';
    file_put_contents($path, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    echo "Added Doormat and Shoe at GHS 20 each.\n";
} finally { flock($lock, LOCK_UN); fclose($lock); }
