<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$fixture = $root . '/tmp/catalog-editor-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700, true);
file_put_contents($fixture . '/.htaccess', "Require all denied\n");
define('OWNER_DIRECTORY', $fixture);
define('CATALOG_PATH', $fixture . '/catalog.json');
// The live catalogue includes items with no legacy ironing field.
file_put_contents(CATALOG_PATH, json_encode([
    ['id' => 'load-test', 'name' => 'Test load', 'category' => 'Loads', 'unit' => 'load', 'fold' => 50],
    ['id' => 'shirt-test', 'name' => 'Test shirt', 'category' => 'Clothes', 'fold' => 5, 'iron' => 10]
]));
set_error_handler(static function ($severity, $message, $file, $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    require $root . '/lib/owner.php';
    ownerWrite('owner', ['hash' => password_hash('Test123', PASSWORD_DEFAULT), 'version' => 'fixture']);
    $_SERVER['SCRIPT_NAME'] = '/Driplaudary/admin.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ownerSession();
    ownerAuthenticate();
    session_write_close();
    ob_start();
    require $root . '/admin.php';
    $html = ob_get_clean();
    session_write_close();
    if (!str_contains($html, 'prices[load-test][fold]') || str_contains($html, 'Wash &amp; iron') || str_contains($html, 'Wash & iron')) {
        throw new RuntimeException('Price editor or customer message advertises unavailable services.');
    }
    $snapshot = catalogSnapshot();
    updateCatalog(['load-test' => ['fold' => '55'], 'shirt-test' => ['fold' => '6']], $snapshot['version']);
    $saved = catalogSnapshot()['catalog'];
    if ($saved[0]['fold'] !== 55 || isset($saved[0]['iron']) || $saved[1]['fold'] !== 6 || $saved[1]['iron'] !== 10) {
        throw new RuntimeException('Fold-only editing failed or changed legacy prices.');
    }
    echo "PASS: owner page renders without warnings for missing ironing fields; fold-only prices save and legacy data stays intact.\n";
} finally {
    restore_error_handler();
    foreach (glob($fixture . '/sessions/*') ?: [] as $file) unlink($file);
    if (is_dir($fixture . '/sessions')) rmdir($fixture . '/sessions');
    foreach (glob($fixture . '/*') ?: [] as $file) unlink($file);
    unlink($fixture . '/.htaccess');
    rmdir($fixture);
}
