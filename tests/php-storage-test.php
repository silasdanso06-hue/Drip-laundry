<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = dirname(__DIR__) . '/tmp/php-storage-' . bin2hex(random_bytes(6));
define('DATA_DIRECTORY', $fixture);
require dirname(__DIR__) . '/lib/php-storage.php';
function storageCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function storageReject(callable $action): void {
    try { $action(); } catch (RuntimeException | InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid storage operation succeeded.');
}
try {
    databaseInitialize(databaseEmptyStore());
    databaseWrite('business', ['text' => "Quotes ' \" and \\ backslashes; <?php exit; ?>", 'unicode' => 'Laundry care']);
    $before = databaseSnapshot();
    storageReject(function () { databaseTransaction(function (array &$s) { $s['application_data'] = []; throw new RuntimeException('Rollback'); }); });
    storageCheck(databaseSnapshot() === $before, 'Failed transaction changed data.');
    storageReject(fn() => databaseInitialize(databaseEmptyStore()));
    $customer = ['id' => 'customer-1', 'name' => 'Test', 'email' => 'test@example.invalid', 'phone' => '', 'hash' => password_hash('Chosen test password', PASSWORD_DEFAULT)];
    databaseWrite('customers', ['test@example.invalid' => $customer]);
    databaseTransaction(function (array &$s) { $s['customers']['test@example.invalid']['email_verified_at'] = '2026-10-01 00:00:00'; });
    databaseWrite('customers', databaseRead('customers'));
    storageCheck(databaseRead('customers')['test@example.invalid']['email_verified_at'] !== null, 'Unchanged contact lost verification.');
    $customers = databaseRead('customers'); $customers['test@example.invalid']['email'] = 'changed@example.invalid';
    databaseWrite('customers', $customers);
    storageCheck(databaseRead('customers')['test@example.invalid']['email_verified_at'] === null, 'Changed contact kept verification.');
    $duplicate = $customer; $duplicate['email'] = 'changed@example.invalid'; $duplicate['id'] = 'customer-2';
    storageReject(fn() => databaseWrite('customers', $customers + ['duplicate@example.invalid' => $duplicate]));
    $before = databaseSnapshot();
    $backup = databaseBackup($fixture . '/backups');
    storageCheck(databaseLoadFile($backup) === $before, 'Backup differs from stored data.');
    databaseWrite('business', ['text' => 'New data']);
    storageCheck(databaseLoadFile($backup) === $before, 'Backup changed after later writes.');
    // Independent processes exercise the file lock: no increment may be lost.
    $worker = $fixture . '/worker.php';
    file_put_contents($worker, '<?php define("DATA_DIRECTORY", ' . var_export($fixture, true) . '); require ' . var_export(dirname(__DIR__) . '/lib/php-storage.php', true) . '; for ($i=0;$i<20;$i++) databaseTransaction(function(array &$s) { $s["application_data"]["counter"]["value"]++; });');
    databaseWrite('counter', ['value' => 0]);
    $processes = [];
    for ($i = 0; $i < 4; $i++) {
        $process = proc_open([PHP_BINARY, $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not launch concurrent writer.');
        fclose($pipes[0]); $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        storageCheck(proc_close($process) === 0 && $output === '', 'Concurrent writer failed.');
    }
    storageCheck(databaseRead('counter')['value'] === 80, 'Concurrent writes lost updates.');
    echo "PASS: PHP storage persistence, literal text, rollback, overwrite protection, customer uniqueness, contact verification, consistent backups and concurrent writes.\n";
} finally {
    foreach (glob($fixture . '/backups/*') ?: [] as $file) unlink($file);
    if (is_dir($fixture . '/backups')) { unlink($fixture . '/backups/.htaccess'); rmdir($fixture . '/backups'); }
    foreach (glob($fixture . '/*') ?: [] as $file) unlink($file);
    if (is_dir($fixture)) { unlink($fixture . '/.htaccess'); rmdir($fixture); }
}
