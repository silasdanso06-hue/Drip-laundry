<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function ownerDirectory(): string
{
    return defined('OWNER_DIRECTORY') ? OWNER_DIRECTORY : dirname(__DIR__) . '/private';
}

function ownerRead(string $name): ?array
{
    if (!defined('OWNER_DIRECTORY') || defined('DATA_DIRECTORY')) return databaseRead($name);
    $file = ownerDirectory() . '/' . $name . '.php';
    return is_file($file) ? require $file : null;
}

function ownerWrite(string $name, array $data): void
{
    if (!defined('OWNER_DIRECTORY') || defined('DATA_DIRECTORY')) { databaseWrite($name, $data); return; }
    $path = ownerDirectory() . '/' . $name . '.php';
    $temp = tempnam(ownerDirectory(), 'owner-');
    if ($temp === false || file_put_contents($temp, "<?php\nreturn " . var_export($data, true) . ";\n", LOCK_EX) === false || !rename($temp, $path)) {
        throw new RuntimeException('Owner settings could not be saved.');
    }
}

function ownerSession(): void
{
    if (!is_dir(ownerDirectory() . '/sessions')) {
        mkdir(ownerDirectory() . '/sessions', 0700, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('dripclean_owner');
    session_save_path(ownerDirectory() . '/sessions');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function ownerSignedIn(): bool
{
    $owner = ownerRead('owner');
    return $owner !== null && isset($_SESSION['owner_version'], $_SESSION['last_active'])
        && hash_equals($owner['version'], $_SESSION['owner_version'])
        && time() - $_SESSION['last_active'] < 1800;
}

function ownerCsrfValid($token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function ownerSetupAllowed($token): bool
{
    $setup = ownerRead('setup');
    return ownerRead('owner') === null && is_string($token) && $setup !== null
        && hash_equals($setup['hash'], hash('sha256', $token));
}

function ownerCreate(string $password, string $token): void
{
    $lock = fopen(ownerDirectory() . '/setup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Please try setup again.');
    try {
        if (!ownerSetupAllowed($token)) throw new InvalidArgumentException('This setup link is invalid or has already been used.');
        if (mb_strlen($password, 'UTF-8') < 6 || mb_strlen($password, 'UTF-8') > 7) {
            throw new InvalidArgumentException('Choose a password between 6 and 7 characters.');
        }
        ownerWrite('owner', ['hash' => password_hash($password, PASSWORD_DEFAULT), 'version' => bin2hex(random_bytes(24))]);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function ownerLogin(string $password, string $address): bool
{
    // Rate limiting is enforced on the server and cannot be reset by clearing cookies.
    $lock = fopen(ownerDirectory() . '/login.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Please try signing in again.');
    try {
        $attempts = ownerRead('attempts') ?? [];
        foreach ($attempts as $key => $entry) {
            if ($entry['since'] < time() - 900) unset($attempts[$key]);
        }
        $key = hash('sha256', $address);
        $entry = $attempts[$key] ?? ['count' => 0, 'since' => time()];
        if ($entry['count'] >= 5) throw new InvalidArgumentException('Too many sign-in attempts. Please wait 15 minutes.');
        $owner = ownerRead('owner');
        $valid = $owner !== null && strlen($password) <= 72 && password_verify($password, $owner['hash']);
        if ($valid) {
            unset($attempts[$key]);
        } else {
            $entry['count']++;
            $attempts[$key] = $entry;
        }
        ownerWrite('attempts', $attempts);
        return $valid;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function ownerAuthenticate(): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'owner_version' => ownerRead('owner')['version'],
        'last_active' => time(),
        'csrf' => bin2hex(random_bytes(32))
    ];
}

function ownerResetAllowed($token): bool {
    $reset = ownerRead('owner-reset');
    $owner = ownerRead('owner');
    return is_string($token) && $reset !== null && $owner !== null
        && ($reset['expires'] ?? 0) > time()
        && hash_equals($reset['owner_version'], $owner['version'])
        && hash_equals($reset['hash'], hash('sha256', $token));
}

function ownerResetPassword(string $token, string $password): void {
    $lock = fopen(ownerDirectory() . '/setup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Please try again.');
    try {
        if (!ownerResetAllowed($token)) throw new InvalidArgumentException('This reset link is invalid, expired or already used.');
        if (mb_strlen($password, 'UTF-8') < 6 || mb_strlen($password, 'UTF-8') > 7) throw new InvalidArgumentException('Choose a password between 6 and 7 characters.');
        ownerWrite('owner', ['hash' => password_hash($password, PASSWORD_DEFAULT), 'version' => bin2hex(random_bytes(24))]);
        ownerWrite('attempts', []);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
