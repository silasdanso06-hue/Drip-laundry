<?php
declare(strict_types=1);
// Hold a shared lock for each web request so migration can wait for writes to finish.
if (PHP_SAPI !== 'cli') {
    $storageAccessLock = fopen(dirname(__DIR__) . '/private/storage-access.lock', 'c');
    if (!$storageAccessLock || !flock($storageAccessLock, LOCK_SH)) { http_response_code(503); exit('Please try again shortly.'); }
    if (is_file(dirname(__DIR__) . '/private/storage-maintenance.php')) {
        http_response_code(503); header('Retry-After: 30');
        exit('Site data is being upgraded. Please try again shortly.');
    }
}
