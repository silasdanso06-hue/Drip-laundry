<?php
declare(strict_types=1);
require __DIR__ . '/lib/customer.php';
require __DIR__ . '/lib/sms.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
customerSession();
$hasCustomers = !empty(ownerRead('customers'));
$requestedMode = $_POST['action'] ?? $_GET['mode'] ?? '';
$mode = $requestedMode === 'signup' || ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestedMode === '' && !$hasCustomers) ? 'signup' : 'login';
if (($_POST['action'] ?? $_GET['mode'] ?? '') === 'phone') {
    if (empty($_SESSION['user'])) { header('Location: account.php'); exit; }
    $mode = 'phone';
    $_POST['email'] = $_SESSION['user']['accountKey'] ?? $_SESSION['user']['email'];
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!ownerCsrfValid($_POST['csrf'] ?? null)) throw new InvalidArgumentException('Your form expired. Please submit it again.');
        $user = customerAccount($mode, $_POST, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        session_regenerate_id(true);
        $_SESSION = ['user' => $user, 'csrf' => bin2hex(random_bytes(32)), 'last_active' => time()];
        $_SESSION['password_version'] = customerPasswordVersion($user);
        if ($mode === 'login' || $mode === 'signup') customerWelcomeSms($user);
        header('Location: index.html#dashboard', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'We could not open your account. Please try again.';
    }
}
function accountEscape($value): string { return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $mode === 'signup' ? 'Create account' : 'Log in' ?> | Dripclean Laundry</title><link rel="stylesheet" href="assets/css/admin.css"></head>
<body><header class="owner-header"><a href="index.html">Dripclean Laundry</a><a href="index.html#pricing">View prices</a></header>
<main><h1><?= $mode === 'signup' ? 'Create your account.' : ($mode === 'phone' ? 'Your sign-in phone number.' : 'Welcome back.') ?></h1>
<?php if (!$hasCustomers): ?><p class="notice">No customer accounts have been saved yet. <a href="account.php?mode=signup">Create your customer account</a> first, then use that email or phone number and password to sign in.</p><?php endif; ?>
<p><?= $mode === 'phone' ? 'Enter your phone number and current password to save it for future sign-ins.' : 'Sign in with your email or saved phone number and password.' ?></p>
<?php if ($error): ?><p class="notice error" role="alert"><?= accountEscape($error) ?></p><?php endif; ?>
<form method="post" action="account.php" class="login-card">
<input type="hidden" name="csrf" value="<?= accountEscape($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="<?= $mode ?>">
<?php if ($mode === 'signup'): ?><label for="name">Full name</label><input id="name" name="name" autocomplete="name" maxlength="80" value="<?= accountEscape($_POST['name'] ?? '') ?>" required><?php endif; ?>
<?php if ($mode !== 'phone'): ?>
<label for="email">Phone number or email address</label><input id="email" type="text" name="email" autocomplete="username" maxlength="254" placeholder="0241234567 or your email address" value="<?= accountEscape($_POST['email'] ?? '') ?>" required>
<?php if ($mode === 'login'): ?><p>Use the phone number saved on your account, such as 0241234567 or +233241234567, and your password. If you registered with email only, log in with email first and add your number from the dashboard.</p><?php endif; ?>
<?php if ($mode === 'signup'): ?><p>Use your email or phone number with a password. If you use a phone number, we also use it for welcome SMS.</p><?php endif; ?>
<?php endif; ?>
<?php if ($mode === 'phone'): ?><label for="phone">Phone number</label><input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="30" placeholder="0241234567 or +233241234567" value="<?= accountEscape($_POST['phone'] ?? '') ?>" required><?php endif; ?>
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="<?= $mode === 'signup' ? 'new-password' : 'current-password' ?>" maxlength="72" required>
<?php if ($mode === 'signup'): ?><label for="confirm">Confirm password</label><input id="confirm" type="password" name="confirm" autocomplete="new-password" maxlength="72" required><?php endif; ?>
<button type="submit"><?= $mode === 'signup' ? 'Create account' : ($mode === 'phone' ? 'Save phone number' : 'Log in') ?></button>
<p><a href="account.php?mode=<?= $mode === 'signup' ? 'login' : 'signup' ?>"><?= $mode === 'signup' ? 'Already registered? Log in' : 'New customer? Create an account' ?></a></p>
<p><a href="recover.php">Forgot your password?</a></p></form></main></body></html>
