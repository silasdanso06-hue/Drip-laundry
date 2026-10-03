<?php
declare(strict_types=1);

// Data is saved as a PHP return array in a web-protected directory. No SQL is used.
function databaseDirectory(): string {
    return defined('DATA_DIRECTORY') ? DATA_DIRECTORY : dirname(__DIR__) . '/private/data';
}

function databaseEmptyStore(): array {
    return ['format' => 'dripclean-php-v1', 'application_data' => [], 'customers' => [], 'orders' => []];
}

function databaseValidateStore(array $store): void {
    if (($store['format'] ?? '') !== 'dripclean-php-v1') throw new RuntimeException('Unsupported data file.');
    foreach (['application_data', 'customers', 'orders'] as $section) {
        if (!isset($store[$section]) || !is_array($store[$section])) throw new RuntimeException('Incomplete data file.');
    }
    $validate = function ($value) use (&$validate): void {
        if (is_array($value)) { foreach ($value as $item) $validate($item); return; }
        if ($value !== null && !is_scalar($value)) throw new RuntimeException('Invalid stored value.');
        if (is_float($value) && !is_finite($value)) throw new RuntimeException('Invalid stored number.');
    };
    $validate($store);
    $ids = []; $emails = []; $phones = [];
    foreach ($store['customers'] as $customer) {
        foreach (['id', 'name', 'email', 'phone', 'hash'] as $field) {
            if (!isset($customer[$field]) || !is_string($customer[$field])) throw new RuntimeException('Invalid customer record.');
        }
        if ($customer['id'] === '' || $customer['hash'] === '' || isset($ids[$customer['id']])) throw new RuntimeException('Invalid or duplicate customer ID.');
        $ids[$customer['id']] = true;
        $email = strtolower($customer['email']); $phone = $customer['phone'];
        if (($email !== '' && isset($emails[$email])) || ($phone !== '' && isset($phones[$phone]))) throw new InvalidArgumentException('An account already uses this email or phone number.');
        if ($email !== '') $emails[$email] = true;
        if ($phone !== '') $phones[$phone] = true;
    }
    foreach ($store['orders'] as $id => $row) {
        if (!is_array($row) || !is_string($row['customer_id'] ?? null) || !is_string($row['created_at'] ?? null) || !is_array($row['payload'] ?? null) || ($row['payload']['id'] ?? null) !== (string)$id) throw new RuntimeException('Invalid order record.');
    }
    foreach ($store['application_data'] as $value) if (!is_array($value)) throw new RuntimeException('Invalid settings record.');
}

function databaseLoadFile(string $path): array {
    if (!is_file($path)) throw new RuntimeException('The PHP data file is missing. Restore a backup or run the migration tool.');
    // Avoid stale data if the host enables PHP's opcode cache for included files.
    if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
    $store = (static function (string $file) { return require $file; })($path);
    if (!is_array($store)) throw new RuntimeException('Invalid PHP data file.');
    databaseValidateStore($store);
    return $store;
}

function databaseSaveFile(string $path, array $store): void {
    databaseValidateStore($store);
    $contents = "<?php\n// Private application data. Manage records through the site.\nreturn " . var_export($store, true) . ";\n";
    $temporary = tempnam(dirname($path), '.store-');
    if ($temporary === false) throw new RuntimeException('Could not prepare data file.');
    try {
        $file = fopen($temporary, 'wb');
        if (!$file) throw new RuntimeException('Could not open data file.');
        try {
            $length = strlen($contents); $offset = 0;
            while ($offset < $length) {
                $written = fwrite($file, substr($contents, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Could not write data file.');
                $offset += $written;
            }
            if (!fflush($file) || (function_exists('fsync') && !fsync($file))) throw new RuntimeException('Could not flush data file.');
        } finally { fclose($file); }
        if (databaseLoadFile($temporary) !== $store) throw new RuntimeException('Data verification failed.');
        // Replacing the complete file leaves the previous version intact if saving fails.
        if (!rename($temporary, $path)) throw new RuntimeException('Could not replace data file.');
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
    } finally { if (is_file($temporary)) unlink($temporary); }
}

function databaseLock(int $mode) {
    $lock = fopen(databaseDirectory() . '/store.lock', 'c');
    if (!$lock || !flock($lock, $mode)) {
        if ($lock) fclose($lock);
        throw new RuntimeException('Could not lock stored data.');
    }
    return $lock;
}

function databaseInitialize(array $store): void {
    $dir = databaseDirectory();
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Could not create data directory.');
    if (file_put_contents($dir . '/.htaccess', "Require all denied\n") === false) throw new RuntimeException('Could not protect data directory.');
    $lock = databaseLock(LOCK_EX);
    try {
        if (is_file($dir . '/store.php')) throw new RuntimeException('PHP data already exists. Migration will not overwrite it.');
        databaseSaveFile($dir . '/store.php', $store);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function databaseSnapshot(): array {
    $lock = databaseLock(LOCK_SH);
    try { return databaseLoadFile(databaseDirectory() . '/store.php'); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}

function databaseTransaction(callable $change) {
    $lock = databaseLock(LOCK_EX);
    try {
        $store = databaseLoadFile(databaseDirectory() . '/store.php');
        $result = $change($store);
        databaseSaveFile(databaseDirectory() . '/store.php', $store);
        return $result;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function databaseRead(string $key): ?array {
    $store = databaseSnapshot();
    return $key === 'customers' ? $store['customers'] : ($store['application_data'][$key] ?? null);
}

function databaseWrite(string $key, array $value): void {
    databaseTransaction(function (array &$store) use ($key, $value): void {
        if ($key !== 'customers') { $store['application_data'][$key] = $value; return; }
        $existing = [];
        foreach ($store['customers'] as $customer) $existing[$customer['id']] = $customer;
        foreach ($value as $accountKey => &$customer) {
            $previous = $existing[$customer['id']] ?? [];
            $customer['phone'] = $customer['phone'] ?? '';
            $customer['created_at'] = $previous['created_at'] ?? gmdate('Y-m-d H:i:s');
            $customer['updated_at'] = gmdate('Y-m-d H:i:s');
            foreach (['phone', 'email'] as $field) {
                $customer[$field . '_verified_at'] = ($previous[$field] ?? '') === $customer[$field] ? ($previous[$field . '_verified_at'] ?? null) : null;
            }
        }
        unset($customer);
        $store['customers'] = $value;
    });
}

function databaseBackup(?string $directory = null): string {
    $store = databaseSnapshot();
    $dir = $directory ?? dirname(__DIR__) . '/private/backups';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Could not create backup directory.');
    if (file_put_contents($dir . '/.htaccess', "Require all denied\n") === false) throw new RuntimeException('Could not protect backups.');
    $path = $dir . '/dripclean-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.php';
    databaseSaveFile($path, $store);
    return $path;
}
