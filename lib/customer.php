<?php
declare(strict_types=1);
require_once __DIR__ . '/owner.php';

function customerSession(): void
{
    $directory = ownerDirectory() . '/customer-sessions';
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('dripclean_customer');
    session_save_path($directory);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Strict'
    ]);
    session_start();
    if (!empty($_SESSION['user'])) {
        $account = (ownerRead('customers') ?? [])[$_SESSION['user']['accountKey'] ?? $_SESSION['user']['email']] ?? null;
        if (!$account || !isset($_SESSION['password_version']) || !hash_equals(hash('sha256', $account['hash']), $_SESSION['password_version'])) $_SESSION = [];
    }
    if (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] > 3600) $_SESSION = [];
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['last_active'] = time();
}

function customerPasswordVersion(array $user): string {
    $account = (ownerRead('customers') ?? [])[$user['accountKey'] ?? $user['email']] ?? null;
    return hash('sha256', $account['hash'] ?? '');
}

function customerPhone(string $value): string
{
    $number = preg_replace('/[\s()+-]/', '', trim($value));
    if (preg_match('/^0\d{9}$/D', $number)) $number = '233' . substr($number, 1);
    if (!preg_match('/^[1-9]\d{7,14}$/D', $number)) throw new InvalidArgumentException('Enter a valid phone number, including country code for numbers outside Ghana.');
    return $number;
}

function customerValidatePassword(string $password): void
{
    if ($password === '') throw new InvalidArgumentException('Enter a password.');
    // Bcrypt must not silently truncate passwords after 72 bytes.
    if (strlen($password) > 72) throw new InvalidArgumentException('Your password is too long. Use up to 72 bytes (usually 72 characters).');
    if (strpos($password, "\0") !== false) throw new InvalidArgumentException('Your password contains an unsupported character.');
}

function customerAccount(string $action, array $input, string $address): array
{
    $email = strtolower(trim(is_string($input['email'] ?? null) ? $input['email'] : ''));
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    if ($email === '' || strlen($email) > 254) throw new InvalidArgumentException('Enter a valid email address or phone number.');
    $signupPhone = '';
    if ($action === 'signup' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $signupPhone = customerPhone($email);
        $email = $signupPhone;
    }
    if ($action === 'signup') customerValidatePassword($password);
    // Existing accounts can still log in with their original longer passwords.
    if ($password === '' || strlen($password) > 72) throw new InvalidArgumentException('Email or password is incorrect.');
    $lock = fopen(ownerDirectory() . '/customers.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Please try again.');
    try {
        $accounts = ownerRead('customers') ?? [];
        if ($action === 'login' && !$accounts) throw new InvalidArgumentException('No customer accounts are saved yet. Choose Create account to register first.');
        $attempts = ownerRead('customer-attempts') ?? [];
        foreach ($attempts as $key => $entry) if ($entry['since'] < time() - 900) unset($attempts[$key]);
        $key = hash('sha256', $address);
        $entry = $attempts[$key] ?? ['count' => 0, 'since' => time()];
        if ($entry['count'] >= 10) {
            $minutes = max(1, (int)ceil(($entry['since'] + 900 - time()) / 60));
            throw new InvalidArgumentException('Too many attempts. Please try again in ' . $minutes . ' minute' . ($minutes === 1 ? '.' : 's.'));
        }
        $entry['count']++;
        $attempts[$key] = $entry;
        ownerWrite('customer-attempts', $attempts);
        if ($action === 'login' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try { $number = customerPhone($email); } catch (InvalidArgumentException $error) { $number = ''; }
            $email = '';
            foreach ($accounts as $candidateEmail => $candidate) {
                if ($number !== '' && ($candidate['phone'] ?? '') === $number) { $email = $candidateEmail; break; }
            }
        }
        $phone = $signupPhone;
        if ($signupPhone !== '') $input['phone'] = $signupPhone;
        if (in_array($action, ['signup', 'phone'], true) && !empty($input['phone'])) {
            if (!is_string($input['phone'])) throw new InvalidArgumentException('Enter a valid phone number.');
            $phone = customerPhone($input['phone']);
            foreach ($accounts as $candidateEmail => $candidate) {
                if ($candidateEmail !== $email && ($candidate['phone'] ?? '') === $phone) throw new InvalidArgumentException('That phone number is already linked to another account.');
            }
        }
        if ($action === 'signup') {
            $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
            if ($name === '' || strlen($name) > 160) throw new InvalidArgumentException('Enter your name (up to 160 bytes).');
            if ($password !== ($input['confirm'] ?? null)) throw new InvalidArgumentException('The passwords must match.');
            if (isset($accounts[$email])) throw new InvalidArgumentException('An account already uses this email or phone number. Please log in or reset your password.');
            $accounts[$email] = ['id' => bin2hex(random_bytes(16)), 'name' => $name, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', 'phone' => $phone, 'hash' => password_hash($password, PASSWORD_DEFAULT)];
            ownerWrite('customers', $accounts);
        } elseif ($action === 'login' || $action === 'phone') {
            $hash = $accounts[$email]['hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid = password_verify($password, $hash);
            if (!$valid || !isset($accounts[$email])) throw new InvalidArgumentException('Email, phone number or password is incorrect.');
            if ($action === 'phone') {
                if ($phone === '') throw new InvalidArgumentException('Enter your phone number.');
                $accounts[$email]['phone'] = $phone;
                ownerWrite('customers', $accounts);
            }
        } else {
            throw new InvalidArgumentException('Unknown account action.');
        }
        $account = $accounts[$email];
        // Successful authentication must not count toward a failed-login lockout.
        unset($attempts[$key]);
        ownerWrite('customer-attempts', $attempts);
        return ['id' => $account['id'], 'name' => $account['name'], 'email' => $account['email'], 'accountKey' => $email];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
