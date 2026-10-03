<?php
declare(strict_types=1);
require __DIR__ . '/lib/customer.php';
require __DIR__ . '/lib/payments.php';
header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self' https://checkout.paystack.com; frame-ancestors 'none'; base-uri 'none'");
customerSession();
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: account.php'); exit; }
$id = is_string($_POST['order'] ?? $_GET['order'] ?? null) ? ($_POST['order'] ?? $_GET['order']) : '';
$order = savedOrder($id, $user['id']);
if (!$order) { http_response_code(404); exit('Order not found in your account.'); }
$error = ''; $message = ''; $config = paymentPublicSettings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!ownerCsrfValid($_POST['csrf'] ?? null)) throw new InvalidArgumentException('Form expired. Refresh and try again.');
        if (($_POST['action'] ?? '') === 'start') {
            $attempt = paymentStart($id, $user['id'], is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
            if (in_array($attempt['status'], ['paid', 'paid_review', 'review', 'test_success'], true)) $message = paymentResultMessage($attempt);
            else { header('Location: ' . $attempt['authorization_url'], true, 303); exit; }
        } elseif (($_POST['action'] ?? '') === 'verify') {
            $reference = (string)($_POST['reference'] ?? '');
            $a = (databaseRead('payment-attempts') ?? [])[$reference] ?? null;
            if (!$a || $a['order_id'] !== $id) throw new InvalidArgumentException('Payment not found for this order.');
            $attempt = paymentVerify($reference, $user['id']); $message = paymentResultMessage($attempt);
        } else throw new InvalidArgumentException('Unknown payment action.');
    } catch (InvalidArgumentException | RuntimeException $e) { $error = $e->getMessage(); }
}
$order = savedOrder($id, $user['id']); $latest = paymentLatest($id, $user['id']);
$payable = ($order['paymentStatus'] ?? 'Unpaid') === 'Unpaid' && ($order['status'] ?? '') !== 'Cancelled';
$receiptEmail = is_string($_POST['email'] ?? null) ? $_POST['email'] : ($latest['email'] ?? $user['email']);
function paymentEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Pay for your order | Dripclean</title><link rel="stylesheet" href="assets/css/admin.css"></head><body>
<header class="owner-header"><a href="index.html#dashboard">My orders</a><a href="index.html#pricing">Prices</a></header><main><p class="eyebrow">Dripclean Laundry</p><h1>Pay for your order</h1>
<section class="customer-update"><h2><?= paymentEscape($id) ?></h2><p><strong>Order total: GHS <?= number_format((float)$order['total'], 2) ?></strong></p><p><?= ($order['paymentStatus'] ?? '') === 'Paid' ? 'Paid in full' : paymentEscape($order['paymentStatus'] ?? 'Unpaid') ?></p>
<?php if(($order['discountAmount']??0)>0): ?><p>Subtotal: GHS <?= number_format((float)$order['subtotal'],2) ?><br>Discount (<?= (int)$order['discountRate'] ?>%): −GHS <?= number_format((float)$order['discountAmount'],2) ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error" role="alert"><?= paymentEscape($error) ?></p><?php endif; ?>
<?php if ($message): ?><p class="notice" role="status"><?= paymentEscape($message) ?></p><?php endif; ?>
<?php if ($latest): ?><p><?= paymentEscape(paymentResultMessage($latest)) ?></p><form method="post"><input type="hidden" name="csrf" value="<?= paymentEscape($_SESSION['csrf']) ?>"><input type="hidden" name="order" value="<?= paymentEscape($id) ?>"><input type="hidden" name="reference" value="<?= paymentEscape($latest['reference']) ?>"><input type="hidden" name="action" value="verify"><button class="secondary">Check payment status</button></form><?php endif; ?>
<?php if ($payable && $config['enabled']): ?>
<?php if ($config['mode'] === 'test'): ?><p class="notice">Test checkout — no real payment will be recorded. Use Paystack’s test payment details.</p><?php endif; ?>
<form method="post" class="login-card"><input type="hidden" name="csrf" value="<?= paymentEscape($_SESSION['csrf']) ?>"><input type="hidden" name="order" value="<?= paymentEscape($id) ?>"><input type="hidden" name="action" value="start">
<label for="email">Email for your payment receipt</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= paymentEscape($receiptEmail) ?>" required><p>You can keep signing in with your phone number. This email is used for your payment receipt.</p>
<button><?= $config['mode'] === 'test' ? 'Open test checkout' : 'Pay GHS ' . number_format((float)$order['total'], 2) ?></button><p>Pay with MTN MoMo, Telecel Cash or card through Paystack. For Mobile Money, enter your wallet number and choose your network on the next page, then follow the prompts to approve payment on your phone.</p></form>
<?php elseif ($payable): ?><p>Online checkout is not available yet. Call <a href="tel:0508103264">0508103264</a> to arrange payment.</p><?php endif; ?>
<p><a href="index.html#dashboard">Return to my orders</a></p></section></main></body></html>
