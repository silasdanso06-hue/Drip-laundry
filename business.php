<?php
require __DIR__.'/lib/owner.php';
$data=ownerRead('business')??[];
header('Cache-Control: no-store');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer information | Dripclean</title><link rel="stylesheet" href="assets/css/admin.css"></head><body><header class="owner-header"><a href="index.html">Dripclean Laundry</a><a href="index.html#cart">Book a pickup</a></header><main><h1>Customer information</h1><p>Contact us on <a href="tel:0508103264">0508103264</a> or <a href="tel:0556333654">0556333654</a>.</p>
<?php foreach(['hours'=>'Opening hours','areas'=>'Service areas','fees'=>'Pickup charges','cancellations'=>'Cancellations and refunds','privacy'=>'Your data and privacy'] as $key=>$label): ?>
<section class="customer-update"><h2><?= $label ?></h2><p><?= nl2br(htmlspecialchars($data[$key]??'Please contact us for details before booking.',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')) ?></p></section>
<?php endforeach; ?></main></body></html>
