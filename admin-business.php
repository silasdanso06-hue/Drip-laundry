<?php
declare(strict_types=1);
require __DIR__ . '/lib/owner.php';
header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
ownerSession();
if (!ownerSignedIn()) { header('Location: admin.php'); exit; }
$_SESSION['last_active']=time();
$error=''; $message=$_SESSION['business_message']??''; unset($_SESSION['business_message']);
$fields=['hours'=>'Opening hours','areas'=>'Pickup and service areas','fees'=>'Pickup charges','cancellations'=>'Cancellation and refund policy','privacy'=>'Customer data and privacy information'];
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!ownerCsrfValid($_POST['csrf']??null)) throw new InvalidArgumentException('Form expired. Refresh and try again.');
        if(($_POST['action']??'')==='backup') {
            $path=databaseBackup();
            header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.basename($path).'"'); readfile($path); exit;
        }
        $data=[];
        foreach($fields as $key=>$label) {
            $value=$_POST[$key]??'';
            if(!is_string($value)||mb_strlen($value)>4000) throw new InvalidArgumentException('Keep each entry under 4,000 characters.');
            $data[$key]=trim($value);
        }
        ownerWrite('business',$data); $_SESSION['business_message']='Business details saved.'; header('Location: admin-business.php',true,303);exit;
    } catch(InvalidArgumentException $e){$error=$e->getMessage();} catch(Throwable $e){$error='The operation could not be completed. Please try again.';}
}
$data=ownerRead('business')??[];
function businessEscape($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Business settings | Dripclean</title><link rel="stylesheet" href="assets/css/admin.css"></head><body><header class="owner-header"><a href="admin.php">Prices</a><a href="admin-orders.php">Orders</a><a href="business.php">View customer information</a></header><main><h1>Business settings</h1>
<?php if($error):?><p class="notice error" role="alert"><?=businessEscape($error)?></p><?php endif;?>
<?php if($message):?><p class="notice success"><?=businessEscape($message)?></p><?php endif;?>
<form method="post" class="customer-update sms-form"><input type="hidden" name="csrf" value="<?=businessEscape($_SESSION['csrf'])?>"><input type="hidden" name="action" value="save">
<?php foreach($fields as $key=>$label):?><label for="<?= $key ?>"><?= $label ?></label><textarea id="<?= $key ?>" name="<?= $key ?>" rows="4" maxlength="4000"><?=businessEscape($data[$key]??'')?></textarea><?php endforeach;?><button>Save business details</button></form>
<section class="customer-update"><h2>Data backup</h2><p>Download a PHP data backup containing customer accounts, orders, prices and settings. Keep backups private; they contain customer data and SMS credentials. A copy is also kept in the protected private/backups folder.</p><form method="post"><input type="hidden" name="csrf" value="<?=businessEscape($_SESSION['csrf'])?>"><input type="hidden" name="action" value="backup"><button>Create and download backup</button></form></section>
</main></body></html>
