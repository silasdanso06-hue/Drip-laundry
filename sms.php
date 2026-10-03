<?php
declare(strict_types=1);
require __DIR__ . '/lib/sms.php';
require __DIR__ . '/lib/order-management.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
ownerSession();
if (!ownerSignedIn()) { header('Location: admin.php'); exit; }
$_SESSION['last_active'] = time();
$settings = ownerRead('sms-settings') ?? ['key' => '', 'sender' => ''];
$notice = $_SESSION['sms_notice'] ?? '';
unset($_SESSION['sms_notice']);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_string($_GET['order'] ?? null)) {
    $order = savedOrder($_GET['order']);
    if ($order) {
        $_POST['phone'] = $order['phone'];
        $_POST['message'] = 'Dripclean Laundry: your order ' . $order['id'] . ' is ' . strtolower($order['status']) . '. Call 0508103264 for assistance. Thank you!';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!ownerCsrfValid($_POST['csrf'] ?? null)) throw new InvalidArgumentException('Your form expired. Reload and try again.');
        if (($_POST['action'] ?? '') === 'settings') {
            $key = is_string($_POST['key'] ?? null) ? trim($_POST['key']) : '';
            $sender = is_string($_POST['sender'] ?? null) ? trim($_POST['sender']) : '';
            if ($key === '') $key = $settings['key'];
            if (!preg_match('/^[A-Za-z0-9_-]{16,256}$/D', $key)) throw new InvalidArgumentException('Enter your SMSOnlineGH API key.');
            smsPayload('0241234567', 'Validation only', $sender);
            ownerWrite('sms-settings', ['key' => $key, 'sender' => $sender]);
            $_SESSION['sms_notice'] = 'SMS settings saved. No message was sent.';
        } elseif (($_POST['action'] ?? '') === 'send') {
            $token = $_POST['send_token'] ?? null;
            if (!is_string($token) || !hash_equals($_SESSION['sms_send_token'] ?? '', $token)) throw new InvalidArgumentException('This send form has already been used or expired. Reload before composing a new message.');
            $phone = is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '';
            $message = is_string($_POST['message'] ?? null) ? $_POST['message'] : '';
            $payload = smsPayload($phone, $message, $settings['sender']);
            if ($settings['key'] === '') throw new InvalidArgumentException('Save your SMS settings first.');
            // Consume before contacting the provider: a timeout must not trigger an automatic retry.
            unset($_SESSION['sms_send_token']);
            $_SESSION['sms_notice'] = smsSend($payload, $settings['key']);
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        header('Location: sms.php', true, 303); exit;
    } catch (InvalidArgumentException | RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'SMS could not be processed. Check your provider history before retrying.';
    }
}
if (empty($_SESSION['sms_send_token'])) $_SESSION['sms_send_token'] = bin2hex(random_bytes(32));
function smsEscape($value): string { return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Customer SMS | Dripclean Laundry</title>
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<header class="owner-header"><a href="admin.php">← Owner price editor</a><a href="index.html#dashboard">Customer dashboard</a></header>
<main>
    <p class="eyebrow">Private owner area</p><h1>Send a customer SMS.</h1>
    <p>Send pickup reminders, laundry-ready messages or price updates through SMSOnlineGH.</p>
    <?php $test = ownerRead('sms-delivery-test'); if ($test): ?>
        <p class="notice">Last connection test: <?= smsEscape($test['result'] ?? 'No confirmed result') ?>. A rejected sender must be approved in SMSOnlineGH before messages can be delivered.</p>
    <?php endif; ?>
    <?php if ($error): ?><p class="notice error" role="alert"><?= smsEscape($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="notice success" role="status"><?= smsEscape($notice) ?></p><?php endif; ?>
    <section class="customer-update">
        <h2>SMS connection</h2>
        <p><?= $settings['key'] ? 'API key saved. Leave the key field blank to keep it.' : 'Enter your API key and registered sender name from SMSOnlineGH. Enable API messaging in your provider account.' ?></p>
        <form method="post" class="sms-form">
            <input type="hidden" name="csrf" value="<?= smsEscape($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="settings">
            <label for="sms-key">API key</label><input id="sms-key" name="key" type="password" autocomplete="new-password" maxlength="256" <?= $settings['key'] ? '' : 'required' ?>>
            <label for="sms-sender">Registered sender name</label><input id="sms-sender" name="sender" maxlength="11" value="<?= smsEscape($settings['sender']) ?>" required>
            <button type="submit">Save SMS settings</button>
        </form>
    </section>
    <section class="customer-update">
        <h2>Compose a message</h2>
        <form method="post" class="sms-form">
            <input type="hidden" name="csrf" value="<?= smsEscape($_SESSION['csrf']) ?>">
            <input type="hidden" name="send_token" value="<?= smsEscape($_SESSION['sms_send_token']) ?>">
            <input type="hidden" name="action" value="send">
            <label for="sms-phone">Customer phone number</label><input id="sms-phone" name="phone" type="tel" placeholder="0241234567 or +233241234567" maxlength="30" value="<?= smsEscape($_POST['phone'] ?? '') ?>" required>
            <label for="sms-message">Message</label><textarea id="sms-message" name="message" rows="6" maxlength="480" required placeholder="Hi, your Dripclean Laundry order is ready for collection. Call 0508103264 to arrange pickup."><?= smsEscape($_POST['message'] ?? '') ?></textarea>
            <p>Up to 480 plain English characters. Long messages may use multiple SMS credits. Review the number and message before sending.</p>
            <button type="submit" <?= $settings['key'] ? '' : 'disabled' ?>>Send SMS</button>
        </form>
        <p>Sending uses your provider balance. Delivery is confirmed in your SMSOnlineGH account. This does not change an order's status.</p>
    </section>
</main>
</body></html>
