<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/php-storage.php';
$root = dirname(__DIR__);
if (is_file(databaseDirectory() . '/store.php')) {
    echo "PHP data already exists. No data was overwritten.\n";
    exit;
}
if (!is_file($root . '/private/storage-maintenance.php')) throw new RuntimeException('Put the site into storage maintenance before migrating.');
$access = fopen($root . '/private/storage-access.lock', 'c');
if (!$access || !flock($access, LOCK_EX)) throw new RuntimeException('Could not obtain migration lock.');
$config = require $root . '/private/database.php';
$source = new PDO($config['dsn'], $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($source->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('This migration expects the current SQLite database.');
$dir = $root . '/private/backups';
if (!is_dir($dir)) mkdir($dir, 0700, true);
file_put_contents($dir . '/.htaccess', "Require all denied\n");
$backup = $dir . '/before-php-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.sqlite';
$source->exec('PRAGMA busy_timeout = 5000');
$source->exec('VACUUM INTO ' . $source->quote($backup));
$copy = new PDO('sqlite:' . $backup, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$copy->exec('PRAGMA query_only = ON');
if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('SQL backup verification failed.');
$store = databaseEmptyStore();
foreach ($copy->query('SELECT data_key, payload FROM application_data') as $row) $store['application_data'][$row['data_key']] = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
foreach ($copy->query('SELECT * FROM customers') as $row) {
    $store['customers'][$row['account_key']] = [
        'id' => $row['id'], 'name' => $row['name'], 'email' => $row['email'] ?? '', 'phone' => $row['phone'] ?? '',
        'hash' => $row['password_hash'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        'phone_verified_at' => $row['phone_verified_at'] ?? null, 'email_verified_at' => $row['email_verified_at'] ?? null
    ];
}
foreach ($copy->query('SELECT * FROM orders') as $row) $store['orders'][$row['id']] = ['customer_id' => $row['customer_id'], 'created_at' => $row['created_at'], 'payload' => json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR)];
databaseInitialize($store);
if (databaseSnapshot() !== $store) throw new RuntimeException('PHP migration verification failed.');
echo 'Migrated and verified: ' . count($store['customers']) . ' customers, ' . count($store['orders']) . ' orders, ' . count($store['application_data']) . " settings records.\n";
echo "Original SQL database retained. Verified pre-migration backup saved in private/backups.\n";
