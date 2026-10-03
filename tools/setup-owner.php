<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/lib/owner.php';
if (ownerRead('owner') !== null) {
    echo "The owner account is already set up. Sign in at admin.php.\n";
    exit;
}
if (ownerRead('setup') !== null) {
    echo "A setup link already exists in private/owner-setup.txt.\n";
    exit;
}
$token = bin2hex(random_bytes(24));
ownerWrite('setup', ['hash' => hash('sha256', $token)]);
$url = 'http://localhost/Driplaudary/admin.php?setup=' . $token;
file_put_contents(ownerDirectory() . '/owner-setup.txt', "OWNER SETUP - keep this file private.\n\nOpen this one-time link and choose your own password:\n" . $url . "\n\nAfter setup, sign in at http://localhost/Driplaudary/admin.php\nThe setup link cannot be used again once the password is created.\n", LOCK_EX);
echo "Created a private one-time setup link in private/owner-setup.txt.\n";
