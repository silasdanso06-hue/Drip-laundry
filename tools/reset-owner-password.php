<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/owner.php';
$owner = ownerRead('owner');
if (!$owner) { echo "Owner account has not been created. Run tools/setup-owner.php.\n"; exit(1); }
$token = bin2hex(random_bytes(32));
ownerWrite('owner-reset', ['hash' => hash('sha256', $token), 'owner_version' => $owner['version'], 'expires' => time() + 1800]);
file_put_contents(ownerDirectory() . '/owner-reset.txt', "PRIVATE OWNER PASSWORD RESET\n\nOpen this complete link within 30 minutes and choose your new password:\nhttp://localhost/Driplaudary/owner-reset.php?token=" . $token . "\n\nThis link works once. Keep it private. Prices and customer records are not changed.\n", LOCK_EX);
echo "Created private/owner-reset.txt. The link expires in 30 minutes.\n";
