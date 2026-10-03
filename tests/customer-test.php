<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = dirname(__DIR__) . '/tmp/customer-tests-' . bin2hex(random_bytes(5));
mkdir($fixture, 0700, true);
file_put_contents($fixture . '/.htaccess', "Require all denied\n");
define('OWNER_DIRECTORY', $fixture);
require dirname(__DIR__) . '/lib/customer.php';
require dirname(__DIR__) . '/lib/sms.php';
function expectCustomer(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function rejectCustomer(callable $action): void {
    try { $action(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid account request was accepted.');
}
try {
    $input = ['name' => 'Test Customer', 'email' => 'Customer@Example.com', 'password' => 'Test123', 'confirm' => 'Test123'];
    try {
        customerAccount('login', $input, 'first-client');
        throw new RuntimeException('Login without an account must not succeed.');
    } catch (InvalidArgumentException $expected) {
        expectCustomer(str_contains($expected->getMessage(), 'Create account'), 'An empty customer store must explain how to register.');
    }
    foreach (['', str_repeat('a', 73), "test\0password"] as $invalid) {
        rejectCustomer(fn() => customerAccount('signup', array_replace($input, ['password' => $invalid, 'confirm' => $invalid]), 'length-client'));
    }
    foreach ([1, 5, 6, 8, 32, 72] as $length) {
        $chosen = array_replace($input, ['email' => 'length-' . $length . '@example.invalid', 'password' => str_repeat('a', $length), 'confirm' => str_repeat('a', $length)]);
        $created = customerAccount('signup', $chosen, 'length-client');
        expectCustomer(customerAccount('login', $chosen, 'length-client')['id'] === $created['id'], 'A chosen password length must work for signup and login.');
    }
    $user = customerAccount('signup', $input, 'signup-client');
    expectCustomer($user['email'] === 'customer@example.com' && !isset($user['hash']), 'Account response must normalize email and omit password hash.');
    $stored = ownerRead('customers');
    expectCustomer($stored[$user['email']]['phone'] === '', 'Email signup must work without an extra phone number.');
    expectCustomer(password_verify($input['password'], $stored[$user['email']]['hash']), 'Passwords must be hashed.');
    rejectCustomer(fn() => customerAccount('signup', $input, 'signup-client'));
    rejectCustomer(fn() => customerAccount('login', array_replace($input, ['password' => 'wrong-password-123']), 'login-client'));
    expectCustomer(customerAccount('login', $input, 'login-client')['id'] === $user['id'], 'Login must return the same account.');
    customerAccount('phone', $input + ['phone' => '0241234567'], 'phone-client');
    expectCustomer(customerAccount('login', array_replace($input, ['email' => '+233 24 123 4567']), 'phone-client')['id'] === $user['id'], 'International phone login must match local number.');
    expectCustomer(customerAccount('login', array_replace($input, ['email' => '0241234567']), 'phone-client')['id'] === $user['id'], 'Local phone login must work.');
    rejectCustomer(fn() => customerAccount('signup', array_replace($input, ['email' => 'duplicate@example.com', 'phone' => '+233241234567']), 'phone-client'));
    rejectCustomer(fn() => customerAccount('signup', array_replace($input, ['email' => 'second@example.com', 'confirm' => 'different']), 'signup-client'));
    for ($i = 0; $i < 10; $i++) rejectCustomer(fn() => customerAccount('login', array_replace($input, ['password' => 'wrong-password-123']), 'limited-client'));
    rejectCustomer(fn() => customerAccount('login', $input, 'limited-client'));
    $phoneOnly = customerAccount('signup', array_replace($input, ['email' => '0201234567']), 'phone-only-client');
    expectCustomer($phoneOnly['email'] === '', 'Phone signup must not invent an email address.');
    expectCustomer(customerAccount('login', array_replace($input, ['email' => '+233201234567']), 'phone-only-client')['id'] === $phoneOnly['id'], 'Phone-only account must support international-format login.');
    ownerWrite('sms-settings', ['key' => 'test-key-not-real-1234', 'sender' => 'Dripclean']);
    $phoneOnlySent = false;
    customerWelcomeSms($phoneOnly, function ($payload) use (&$phoneOnlySent): string {
        $phoneOnlySent = $payload['destinations'] === ['233201234567'];
        return 'Provider response: DS_PENDING_ENROUTE';
    });
    expectCustomer($phoneOnlySent, 'Welcome SMS must use the phone supplied as the signup identifier.');
    $sent = 0;
    $sender = function ($payload, $key) use (&$sent): string {
        expectCustomer($payload['destinations'] === ['233241234567'], 'Welcome SMS must use saved phone number.');
        $sent++;
        return 'Provider response: DS_PENDING_ENROUTE';
    };
    customerWelcomeSms($user, $sender);
    customerWelcomeSms($user, $sender);
    expectCustomer($sent === 1, 'Repeated logins must not duplicate welcome SMS.');
    ownerWrite('welcome-sms-' . $user['id'], ['attempted_at' => time() - 301]);
    customerWelcomeSms($user, function () { throw new RuntimeException('Simulated provider timeout'); });
    expectCustomer(ownerRead('welcome-sms-' . $user['id'])['status'] === 'unconfirmed', 'SMS failure must be recorded without blocking login.');
    echo "PASS: customer authentication, phone login, rate limiting, welcome SMS, duplicate suppression and provider failure. No live SMS sent.\n";
} finally {
    foreach (glob($fixture . '/*') as $file) unlink($file);
    unlink($fixture . '/.htaccess');
    rmdir($fixture);
}
