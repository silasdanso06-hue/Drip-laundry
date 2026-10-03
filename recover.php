<?php
declare(strict_types=1);
require __DIR__.'/lib/account-codes.php';
header('Cache-Control: no-store');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
customerSession();$purpose=($_GET['purpose']??$_POST['purpose']??'')==='verify'?'verify':'reset';
if($purpose==='verify'&&empty($_SESSION['user'])){header('Location: account.php');exit;}
$error='';$message='';
if($_SERVER['REQUEST_METHOD']==='POST')try{
    if(!ownerCsrfValid($_POST['csrf']??null))throw new InvalidArgumentException('Form expired. Refresh and try again.');
    if(($_POST['action']??'')==='request'){
        $token = accountCodeStart((string)($_POST['identifier']??''),$purpose,(string)($_POST['channel']??''),$_SERVER['REMOTE_ADDR']??'',$_SESSION['user']??null);
        if($token === '') throw new InvalidArgumentException('We could not send the code. Check the saved contact details or your SMS/email setup and try again.');
        $_SESSION['account_code']=$token;
        $_SESSION['account_code_purpose']=$purpose;
        $message='If the account has that contact method and delivery is available, a code will arrive shortly. Check SMS or email. Codes expire in 10 minutes.';
    }else{
        if(($_SESSION['account_code_purpose']??'')!==$purpose)throw new InvalidArgumentException('Request a new code.');
        if($purpose==='reset'&&($_POST['password']??'')!==($_POST['confirm']??''))throw new InvalidArgumentException('Passwords must match.');
        accountCodeFinish($_SESSION['account_code']??'',(string)($_POST['code']??''),(string)($_POST['password']??''));
        unset($_SESSION['account_code'],$_SESSION['account_code_purpose']);
        $message=$purpose==='reset'?'Password changed. You can now log in.':'Contact detail verified.';
    }
}catch(InvalidArgumentException $e){$error=$e->getMessage();}catch(Throwable $e){$error='We could not complete this request. Try again or contact us.';}
function codeEscape($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account help | Dripclean</title><link rel="stylesheet" href="assets/css/admin.css"></head><body><header class="owner-header"><a href="account.php">Log in</a><a href="index.html#dashboard">Dashboard</a></header><main><h1><?= $purpose==='verify'?'Verify your contact details':'Reset your password' ?></h1>
<?php if($error):?><p class="notice error" role="alert"><?=codeEscape($error)?></p><?php endif;?><?php if($message):?><p class="notice success" role="status"><?=codeEscape($message)?></p><?php endif;?>
<form method="post" class="login-card"><input type="hidden" name="csrf" value="<?=codeEscape($_SESSION['csrf'])?>"><input type="hidden" name="purpose" value="<?=$purpose?>"><input type="hidden" name="action" value="request">
<?php if($purpose==='reset'):?><label for="identifier">Account email or phone number</label><input id="identifier" name="identifier" maxlength="254" autocomplete="username" required><?php endif;?>
<label for="channel">Send a code to my saved</label><select id="channel" name="channel"><option value="phone">Phone number (SMS)</option><option value="email">Email address</option></select><p>The code goes only to the contact already saved on your account.</p><button>Send code</button></form>
<?php if(isset($_SESSION['account_code'])&&($_SESSION['account_code_purpose']??'')===$purpose):?>
<form method="post" class="login-card"><input type="hidden" name="csrf" value="<?=codeEscape($_SESSION['csrf'])?>"><input type="hidden" name="purpose" value="<?=$purpose?>"><input type="hidden" name="action" value="confirm"><label for="code">Six-digit code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
<?php if($purpose==='reset'):?><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" maxlength="72" required><label for="confirm">Confirm password</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" maxlength="72" required><?php endif;?><button>Confirm</button></form><?php endif;?></main></body></html>
