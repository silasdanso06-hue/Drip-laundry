<?php
declare(strict_types=1);
require __DIR__ . '/lib/owner.php';
require __DIR__ . '/lib/order-management.php';
header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
ownerSession();
if (!ownerSignedIn()) { header('Location: admin.php'); exit; }
$_SESSION['last_active'] = time();
$error = ''; $message = $_SESSION['order_message'] ?? ''; unset($_SESSION['order_message']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!ownerCsrfValid($_POST['csrf'] ?? null)) throw new InvalidArgumentException('Form expired. Refresh and try again.');
        updateOrderStatus((string)($_POST['id'] ?? ''), (string)($_POST['status'] ?? ''), (string)($_POST['payment'] ?? ''), (string)($_POST['version'] ?? ''));
        $_SESSION['order_message'] = 'Order and payment status saved.';
        header('Location: admin-orders.php', true, 303); exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { $error = 'Order could not be updated. Please try again.'; }
}
$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$result = ownerOrders($search, $page); $rows = $result['orders']; $more = $result['more'];
function orderEscape($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Manage orders | Dripclean</title><link rel="stylesheet" href="assets/css/admin.css"></head><body>
<header class="owner-header"><a href="admin.php">Prices</a><a href="sms.php">Customer SMS</a><a href="admin-payments.php">Online payments</a><a href="admin-business.php">Business settings & backups</a></header>
<main><p class="eyebrow">Owner area</p><h1>Customer orders</h1>
<?php if ($error): ?><p class="notice error" role="alert"><?= orderEscape($error) ?></p><?php endif; ?>
<?php if ($message): ?><p class="notice success" role="status"><?= orderEscape($message) ?></p><?php endif; ?>
<form method="get" class="sms-form"><label for="search">Search order number or customer name, email or phone</label><input id="search" name="search" value="<?= orderEscape($search) ?>"><button>Search</button></form>
<?php if (!$rows): ?><p>No matching orders.</p><?php endif; ?>
<?php foreach ($rows as $o): ?>
<article class="customer-update"><h2><?= orderEscape($o['id']) ?></h2>
<p><strong><?= orderEscape($o['name']) ?></strong> · <?= orderEscape($o['phone']) ?><br>Pickup: <?= orderEscape($o['date']) ?><br><?= orderEscape($o['location']) ?></p>
<ul><?php foreach ($o['lines'] as $line): ?><li><?= orderEscape($line['quantity'].' × '.$line['name'].' — '.$line['service']) ?>: GHS <?= number_format((float)$line['total'],2) ?></li><?php endforeach; ?></ul>
<p><strong>Total: GHS <?= number_format((float)$o['total'],2) ?></strong> · <?= orderEscape($o['paymentMethod']) ?><br>
<?php if(($o['discountAmount']??0)>0): ?>Subtotal: GHS <?= number_format((float)$o['subtotal'],2) ?><br>Discount (<?= (int)$o['discountRate'] ?>%): −GHS <?= number_format((float)$o['discountAmount'],2) ?><br><?php endif; ?>
Payment: <?= ($o['paymentStatus']??'Unpaid') === 'Paid' ? 'Paid in full' : orderEscape($o['paymentStatus']??'Unpaid') ?><br>
Balance due: GHS <?= number_format(in_array($o['paymentStatus']??'Unpaid',['Paid','Refunded'],true) ? 0 : (float)$o['total'],2) ?>
<?php if(!empty($o['paidAt'])): ?><br>Payment confirmed: <?= orderEscape($o['paidAt']) ?><?php endif; ?></p>
<?php if(!empty($o['paymentReference'])): ?><p>Paystack reference: <?= orderEscape($o['paymentReference']) ?></p><?php endif; ?>
<?php if(!empty($o['paymentReview'])): ?><p class="notice error"><?= orderEscape($o['paymentReview']) ?> Review the transaction in <a href="admin-payments.php">Online payments</a>.</p><?php endif; ?>
<?php if(!empty($o['testPaymentConfirmed'])): ?><p>Test checkout completed. No real money was recorded by that test.</p><?php endif; ?>
<?php if(($o['paymentProvider']??'')==='Paystack'): ?><p>Issue any refund in Paystack first, then record it as Refunded here. This form does not send money back.</p><?php endif; ?>
<form method="post" class="sms-form"><input type="hidden" name="csrf" value="<?= orderEscape($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= orderEscape($o['id']) ?>"><input type="hidden" name="version" value="<?= orderVersion($o) ?>">
<label>Order status <select name="status"><?php foreach(['Received','Being cleaned','Ready','Delivered','Cancelled'] as $s): ?><option <?= ($o['status']??'Received')===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></label>
<label>Payment status <select name="payment"><?php foreach(['Unpaid','Paid','Refunded'] as $s): ?><option value="<?= $s ?>" <?= ($o['paymentStatus']??'Unpaid')===$s?'selected':'' ?>><?= $s==='Paid'?'Paid in full':$s ?></option><?php endforeach; ?></select></label><p>Choose “Paid in full” only after receiving the entire order total. This records payment; it does not charge the customer.</p><button>Save order &amp; payment</button></form>
<p><a href="sms.php?order=<?= rawurlencode($o['id']) ?>">Prepare customer SMS</a></p></article>
<?php endforeach; ?>
<?php if($page>1): ?><a href="?page=<?= $page-1 ?>&amp;search=<?= rawurlencode($search) ?>">Previous</a><?php endif; ?>
<?php if($more): ?><a href="?page=<?= $page+1 ?>&amp;search=<?= rawurlencode($search) ?>">Next</a><?php endif; ?>
</main></body></html>
