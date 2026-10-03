<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/catalog.php';
databaseBackup();
$lock = fopen(databaseDirectory() . '/catalog.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock catalogue.');
try {
    $catalog = databaseTransaction(function (array &$store): array {
        $before = $store['application_data']['catalog'];
        $changes = [
            'item-1-11' => ['Hoody heavy', 12],
            'hoody-normal' => ['Normal hoody', 6],
            'jersey-shorts' => ['Jersey shorts', 3],
            'item-5-3' => ['Duvet heavy', 40],
            'duvet-normal' => ['Duvet normal', 25],
            'item-5-1' => ['Bedsheet complete', 25],
            'item-5-10' => ['Bedsheet light weight', 15],
            'bedsheet-heavy' => ['Bedsheet heavy', 20],
            'item-5-7' => ['Curtain heavy', 25],
            'curtain-normal' => ['Curtain normal', 15]
        ];
        $insertAfter = ['item-1-11' => ['hoody-normal'], 'item-1-14' => ['jersey-shorts'],
            'item-5-1' => ['bedsheet-heavy'], 'item-5-3' => ['duvet-normal'], 'item-5-7' => ['curtain-normal']];
        $newIds = array_merge(...array_values($insertAfter));
        $existing = array_column($before, null, 'id');
        $updated = [];
        foreach ($before as $item) {
            $id = $item['id'];
            if ($item['category'] === 'Footwear & floor items' || in_array($id, $newIds, true)) continue;
            if (isset($changes[$id])) [$item['name'], $item['fold']] = $changes[$id];
            $updated[] = $item;
            foreach ($insertAfter[$id] ?? [] as $newId) {
                [$name, $price] = $changes[$newId];
                $new = $existing[$newId] ?? ['id' => $newId, 'category' => $item['category']];
                $new['name'] = $name; $new['fold'] = $price;
                $updated[] = $new;
            }
        }
        foreach ($changes as $id => [$name, $price]) {
            $matches = array_values(array_filter($updated, fn($item) => $item['id'] === $id));
            if (count($matches) !== 1 || $matches[0]['name'] !== $name || $matches[0]['fold'] !== $price) throw new RuntimeException('Incomplete price update.');
        }
        $store['application_data']['catalog'] = $updated;
        return $updated;
    });
    file_put_contents(dirname(__DIR__) . '/assets/data/catalog.json', json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    echo 'Updated 10 requested prices; removed Footwear & floor items; retained other saved prices. Catalogue: ' . count($catalog) . " items.\n";
} finally { flock($lock, LOCK_UN); fclose($lock); }
