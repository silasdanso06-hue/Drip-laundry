<?php
declare(strict_types=1);
require __DIR__ . '/lib/owner.php';
require __DIR__ . '/lib/payments.php';
header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
ownerSession();
if (!ownerSignedIn()) { header('Location: admin.php'); exit; }
$_SESSION['last_active'] = time(); $error = ''; $message = $_SESSION['payment_message'] ?? ''; unset($_SESSION['payment_message']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!ownerCsrfValid($_POST['csrf'] ?? null)) throw new InvalidArgumentException('Form expired. Refresh and try again.');
        if (($_POST['action'] ?? '') === 'verify') {
            $result = paymentVerify((string)($_POST['reference'] ?? ''));
            $_SESSION['payment_message'] = paymentResultMessage($result);
        } else {
            paymentSaveSettings($_POST);
            $_SESSION['payment_message'] = 'Payment settings saved.';
        }
        header('Location: admin-payments.php', true, 303); exit;
    } catch (InvalidArgumentException | RuntimeException $e) { $error = $e->getMessage(); }
}
$settings = paymentSettings();
$attempts = array_slice(array_reverse(databaseRead('payment-attempts') ?? []), 0, 50);
function payEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Online payments | Dripclean</title><link rel="stylesheet" href="assets/css/admin.css"></head><body>
<header class="owner-header"><a href="admin.php">Prices</a><a href="admin-orders.php">Orders</a><a href="admin-business.php">Business settings</a></header>
<main><p class="eyebrow">Private owner area</p><h1>Online payments</h1><p>Accept Ghana cedi payments with MTN MoMo, Telecel Cash and cards through Paystack.</p><p>Use your Paystack secret key for both Mobile Money networks. Separate MTN or Telecel API keys are not needed for this checkout. Customers choose their network on Paystack. Enable Mobile Money in your Ghana Paystack account before testing.</p>
<?php if ($error): ?><p class="notice error" role="alert"><?= payEscape($error) ?></p><?php endif; ?>
<?php if ($message): ?><p class="notice success" role="status"><?= payEscape($message) ?></p><?php endif; ?>
<form method="post" class="customer-update sms-form">
<input type="hidden" name="csrf" value="<?= payEscape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="settings">
<label for="enabled">Online checkout</label><select id="enabled" name="enabled"><option value="0" <?= empty($settings['enabled']) ? 'selected' : '' ?>>Disabled</option><option value="1" <?= !empty($settings['enabled']) ? 'selected' : '' ?>>Enabled</option></select>
<label for="mode">Payment mode</label><select id="mode" name="mode"><option value="test" <?= ($settings['mode'] ?? 'test') === 'test' ? 'selected' : '' ?>>Test — no real payments</option><option value="live" <?= ($settings['mode'] ?? '') === 'live' ? 'selected' : '' ?>>Live — real payments</option></select>
<label for="secret">Paystack secret key</label><input id="secret" name="secret" type="password" autocomplete="new-password" maxlength="150" placeholder="sk_test_… or sk_live_…"><p><?= !empty($settings['secret']) ? 'A key is saved. Leave this blank to keep it.' : 'Add your secret key from your Paystack dashboard.' ?> The key is saved privately and is never sent to the customer’s browser.</p>
<label for="site_url">Website base URL</label><input id="site_url" name="site_url" type="url" maxlength="300" value="<?= payEscape($settings['site_url'] ?: 'http://localhost/Driplaudary') ?>" placeholder="https://your-domain.com"><p>Use localhost in test mode. Live payments need your public HTTPS website.</p>
<button>Save payment settings</button></form>
<section class="customer-update"><h2>Connect your Paystack account</h2><ol><li>Open your <a href="https://dashboard.paystack.com/#/settings/developer">Paystack API settings</a> and copy the secret key for your selected mode into the form above.</li><li>In Paystack, set the webhook URL to <code><?= payEscape(($settings['site_url'] ?: 'https://your-domain.com') . '/payment-webhook.php') ?></code>.</li><li>Enable Mobile Money and cards in your Paystack account, then test a saved order.</li></ol><p>Paystack cannot send webhooks to localhost. Local test payments can be checked after returning from checkout or with “Check payment status”. Switch to live mode after your Paystack business is activated and your public site is ready.</p><p>Test payments stay separate from real payment records. Refunds must be issued from Paystack first; updating the order’s refund status only records the refund.</p></section>
<section><h2>Recent payment attempts</h2><?php if (!$attempts): ?><p>No online payments have been started.</p><?php endif; ?>
<?php foreach ($attempts as $attempt): ?><article class="customer-update"><h3><?= payEscape($attempt['order_id']) ?></h3><p><?= payEscape(strtoupper($attempt['mode'])) ?> · GHS <?= number_format($attempt['amount_minor'] / 100, 2) ?><br>Reference: <?= payEscape($attempt['reference']) ?><br><?= payEscape(paymentStatusMessage($attempt['status'])) ?></p><form method="post"><input type="hidden" name="csrf" value="<?= payEscape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="reference" value="<?= payEscape($attempt['reference']) ?>"><button>Check payment status</button></form></article><?php endforeach; ?></section>
</main></body></html>
