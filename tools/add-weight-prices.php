<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/catalog.php';

// Run explicitly to add the owner's three wash-and-fold load packages.
databaseBackup();
$lock = fopen(databaseDirectory() . '/catalog.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock prices.');
try {
    $catalog = databaseTransaction(function (array &$store): array {
        $catalog = $store['application_data']['catalog'] ?? null;
        if (!is_array($catalog) || !$catalog) throw new RuntimeException('Current prices are missing.');
        foreach ([6 => 50, 7 => 60, 8 => 70] as $kg => $price) {
            $id = 'load-' . $kg . 'kg';
            $package = ['id' => $id, 'category' => 'Laundry by weight', 'name' => $kg . ' kg load', 'fold' => $price, 'iron' => null, 'unit' => 'load'];
            $found = false;
            foreach ($catalog as &$item) {
                if ($item['id'] === $id) { $item = $package; $found = true; break; }
            }
            unset($item);
            if (!$found) $catalog[] = $package;
        }
        $store['application_data']['catalog'] = $catalog;
        return $catalog;
    });
    $path = dirname(__DIR__) . '/assets/data/catalog.json';
    $temporary = tempnam(dirname($path), 'catalog-');
    try {
        if (file_put_contents($temporary, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n") === false || !rename($temporary, $path)) throw new RuntimeException('Could not refresh the preview catalogue.');
    } finally { if (is_file($temporary)) unlink($temporary); }
    echo "Added wash & fold loads: 6 kg = GHS 50, 7 kg = GHS 60, 8 kg = GHS 70. Other prices retained.\n";
} finally { flock($lock, LOCK_UN); fclose($lock); }
