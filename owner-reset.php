<?php
declare(strict_types=1);
require __DIR__ . '/lib/owner.php';
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
ownerSession(); $error = '';
if (isset($_GET['token']) && ownerResetAllowed($_GET['token'])) {
    session_regenerate_id(true); $_SESSION['owner_reset_token'] = $_GET['token'];
    header('Location: owner-reset.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!ownerCsrfValid($_POST['csrf'] ?? null)) throw new InvalidArgumentException('The form expired. Reload and try again.');
        $password = $_POST['password'] ?? '';
        if (!is_string($password) || $password !== ($_POST['confirm'] ?? null)) throw new InvalidArgumentException('Both passwords must match.');
        ownerResetPassword($_SESSION['owner_reset_token'] ?? '', $password);
        ownerAuthenticate(); $_SESSION['message'] = 'Your owner password has been changed. You can now update prices.';
        header('Location: admin.php', true, 303); exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { $error = 'Password could not be changed. Please try again.'; }
}
$allowed = ownerResetAllowed($_SESSION['owner_reset_token'] ?? null);
function resetEscape(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Reset owner password | Dripclean</title><link rel="stylesheet" href="assets/css/admin.css"></head><body><main><h1>Reset your owner password</h1>
<?php if ($error): ?><p class="notice error" role="alert"><?= resetEscape($error) ?></p><?php endif; ?>
<?php if ($allowed): ?><form method="post" class="login-card"><input type="hidden" name="csrf" value="<?= resetEscape($_SESSION['csrf']) ?>"><label for="password">New password (6–7 characters)</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="6" maxlength="7" required><label for="confirm">Confirm new password</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" minlength="6" maxlength="7" required><button>Save password and open price editor</button></form>
<?php else: ?><p>This link is invalid, expired or already used. Generate a new private link with <code>php tools/reset-owner-password.php</code> on your computer.</p><?php endif; ?>
</main></body></html>
