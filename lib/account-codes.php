<?php
declare(strict_types=1);
require_once __DIR__.'/customer.php';
require_once __DIR__.'/sms.php';
function accountCodeStart(string $identifier, string $purpose, string $channel, string $address, ?array $user, ?callable $transport=null): string {
    if(!in_array($purpose,['reset','verify'],true)||!in_array($channel,['phone','email'],true)) throw new InvalidArgumentException('Invalid verification request.');
    $lock=fopen(ownerDirectory().'/account-codes.lock','c');flock($lock,LOCK_EX);
    try {
        $rateKey='code-rate-'.hash('sha256',$address);$rate=ownerRead($rateKey)??['since'=>time(),'count'=>0];
        if($rate['since']<time()-900)$rate=['since'=>time(),'count'=>0];
        if($rate['count']>=5)throw new InvalidArgumentException('Too many code requests. Please wait 15 minutes.');
        $rate['count']++;ownerWrite($rateKey,$rate);
        $account=null;$accountKey='';$identifier=strtolower(trim($identifier));
        try{$phone=customerPhone($identifier);}catch(Throwable $e){$phone='';}
        foreach(ownerRead('customers')??[] as $key=>$candidate){
            if($purpose==='verify' ? $user && $candidate['id']===$user['id'] : ($candidate['email']!=='' && $candidate['email']===$identifier)||($phone!==''&&($candidate['phone']??'')===$phone)){$account=$candidate;$accountKey=$key;break;}
        }
        $token=bin2hex(random_bytes(24));
        if(!$account) return '';
        $destination=$account[$channel]??'';
        if($destination==='') { if($purpose==='verify')throw new InvalidArgumentException('Add this contact detail to your account first.'); return ''; }
        $code=(string)random_int(100000,999999);
        $record=['customer_id'=>$account['id'],'account_key'=>$accountKey,'purpose'=>$purpose,'channel'=>$channel,'destination'=>$destination,'hash'=>password_hash($code,PASSWORD_DEFAULT),'expires'=>time()+600,'tries'=>0];
        ownerWrite('code-'.$token,$record);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    $message='Dripclean Laundry: your '.($purpose==='reset'?'password reset':'verification').' code is '.$code.'. It expires in 10 minutes. Do not share this code.';
    try {
        if($transport){$transport($channel,$destination,$message);}
        elseif($channel==='phone'){
            $s=ownerRead('sms-settings')??[];$result=smsSend(smsPayload($destination,$message,$s['sender']??''),$s['key']??'',8);
            if(strpos($result,'DS_REJECTED')!==false)throw new RuntimeException('SMS provider rejected the verification message.');
        }else{
            $from=getenv('DRIPCLEAN_MAIL_FROM');
            if(!$from||!filter_var($from,FILTER_VALIDATE_EMAIL)||!mail($destination,'Your Dripclean Laundry code',$message,'From: '.$from))throw new RuntimeException('Email delivery is not configured.');
        }
    }catch(Throwable $e){
        ownerWrite('code-'.$token,['expires'=>0,'tries'=>5]);
        ownerWrite('code-delivery-status',['at'=>gmdate('c'),'channel'=>$channel,'status'=>'failed']);
        return '';
    }
    return $token;
}
function accountCodeFinish(string $token,string $code,string $password='',?callable $apply=null): void {
    if(!preg_match('/^[a-f0-9]{48}$/D',$token))throw new InvalidArgumentException('Invalid or expired code.');
    $lock=fopen(ownerDirectory().'/account-codes.lock','c');flock($lock,LOCK_EX);
    try{
        $key='code-'.$token;$record=ownerRead($key);
        if(!$record||($record['expires']??0)<time()||($record['tries']??5)>=5)throw new InvalidArgumentException('Invalid or expired code. Request a new code.');
        $record['tries']++;ownerWrite($key,$record);
        if(!password_verify($code,$record['hash']))throw new InvalidArgumentException('Incorrect code.');
        if($record['purpose']==='reset') customerValidatePassword($password);
        if($apply){$apply($record,$password);}else{
            // Coordinate with signup/profile writes so a reset cannot be overwritten.
            $customersLock=fopen(ownerDirectory().'/customers.lock','c');
            if(!$customersLock||!flock($customersLock,LOCK_EX))throw new RuntimeException('Please try again.');
            try {
                databaseTransaction(function(array &$store) use($record,$password):void {
                    $field=$record['channel']==='phone'?'phone':'email';
                    foreach($store['customers'] as &$customer) {
                        if($customer['id']!==$record['customer_id']||($customer[$field]??'')!==$record['destination'])continue;
                        if($record['purpose']==='reset')$customer['hash']=password_hash($password,PASSWORD_DEFAULT);
                        else $customer[$field.'_verified_at']=gmdate('Y-m-d H:i:s');
                        $customer['updated_at']=gmdate('Y-m-d H:i:s');
                        return;
                    }
                    throw new InvalidArgumentException('Account contact changed. Request a new code.');
                });
            } finally {flock($customersLock,LOCK_UN);fclose($customersLock);}
        }
        ownerWrite($key,['expires'=>0,'tries'=>5]);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
