<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function catalogPath(): string
{
    return defined('CATALOG_PATH') ? CATALOG_PATH : dirname(__DIR__) . '/assets/data/catalog.json';
}

function catalogSnapshot(): array
{
    $raw = defined('CATALOG_PATH') ? file_get_contents(catalogPath()) : json_encode(databaseRead('catalog'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if ($raw === false) throw new RuntimeException('The price list could not be read.');
    return ['version' => hash('sha256', $raw), 'catalog' => json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
}

function updateCatalog(array $prices, string $version): void
{
    $lockPath = defined('CATALOG_PATH') ? catalogPath() . '.lock' : databaseDirectory() . '/catalog.lock';
    $lock = fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Please try saving again.');
    try {
        $current = catalogSnapshot();
        if (!hash_equals($current['version'], $version)) {
            throw new InvalidArgumentException('Items or prices changed in another window. Reload this page before editing again.');
        }
        if (count($prices) !== count($current['catalog'])) throw new InvalidArgumentException('The price list is incomplete.');
        $updated = $current['catalog'];
        foreach ($updated as &$item) {
            $row = $prices[$item['id']] ?? null;
            if (!is_array($row)) throw new InvalidArgumentException('The price list is incomplete.');
            foreach (['name' => 80, 'category' => 60] as $field => $maxLength) {
                // Older open price forms can still save without changing item details.
                if (!array_key_exists($field, $row)) continue;
                $value = $row[$field];
                if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                    throw new InvalidArgumentException('Enter a valid item name and category.');
                }
                $value = trim(preg_replace('/\s+/u', ' ', $value));
                if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength) {
                    throw new InvalidArgumentException($field === 'name' ? 'Item names must be 1–80 characters.' : 'Categories must be 1–60 characters.');
                }
                $item[$field] = $value;
            }
            foreach (['fold'] as $service) {
                $value = $row[$service] ?? null;
                if (!is_string($value)) throw new InvalidArgumentException('Please enter valid prices.');
                $value = trim($value);
                if ($value === '') {
                    $item[$service] = null;
                    continue;
                }
                if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $value) || (float) $value > 100000) {
                    throw new InvalidArgumentException('Prices must be between GH₵ 0 and GH₵ 100,000, with at most two decimal places.');
                }
                $item[$service] = round((float) $value, 2);
            }
        }
        unset($item);
        $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (!defined('CATALOG_PATH')) { databaseWrite('catalog', $updated); return; }
        $temporary = tempnam(dirname(catalogPath()), 'prices-');
        if ($temporary === false) throw new RuntimeException('Could not prepare the new price list.');
        if (file_put_contents($temporary, $json) === false || !rename($temporary, catalogPath())) {
            if (is_file($temporary)) unlink($temporary);
            throw new RuntimeException('Prices were not saved. Please try again.');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
