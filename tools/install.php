<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/php-storage.php';
$root = dirname(__DIR__);
foreach (['private', 'tmp', 'output'] as $directory) {
    if (!is_dir($root . '/' . $directory)) mkdir($root . '/' . $directory, 0700, true);
    file_put_contents($root . '/' . $directory . '/.htaccess', "Require all denied\n");
}
if (is_file(databaseDirectory() . '/store.php')) {
    echo "Already installed. Existing accounts, orders and settings were preserved.\n";
    exit;
}
$store = databaseEmptyStore();
$store['application_data']['catalog'] = json_decode(file_get_contents($root . '/assets/data/catalog.json'), true, 32, JSON_THROW_ON_ERROR);
databaseInitialize($store);
echo "Created empty private storage with the starter catalogue. Run php tools/setup-owner.php next.\n";
