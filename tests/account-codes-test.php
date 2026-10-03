<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dir=dirname(__DIR__).'/tmp/codes-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);file_put_contents($dir.'/.htaccess',"Require all denied\n");define('OWNER_DIRECTORY',$dir);
require dirname(__DIR__).'/lib/account-codes.php';
function codeCheck($yes,$message){if(!$yes)throw new RuntimeException($message);}
function codeReject($f){try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected invalid code to be rejected.');}
try{
    ownerWrite('customers',['test@example.invalid'=>['id'=>'test','name'=>'Test','email'=>'test@example.invalid','phone'=>'233501234567','hash'=>password_hash('Test123',PASSWORD_DEFAULT)]]);
    $code='';$send=function($channel,$to,$text)use(&$code){preg_match('/\b\d{6}\b/',$text,$m);$code=$m[0];};
    $token=accountCodeStart('0501234567','reset','phone','test',null,$send);
    $newPassword='My chosen password is longer';
    codeReject(fn()=>accountCodeFinish($token,$code==='000000'?'000001':'000000',$newPassword,fn()=>null));
    codeReject(fn()=>accountCodeFinish($token,$code,'',fn()=>null));
    codeReject(fn()=>accountCodeFinish($token,$code,str_repeat('a',73),fn()=>null));
    $changed=false;accountCodeFinish($token,$code,$newPassword,function($r,$p)use(&$changed,$newPassword){$changed=$r['customer_id']==='test'&&$p===$newPassword;});codeCheck($changed,'Reset failed');
    codeReject(fn()=>accountCodeFinish($token,$code,$newPassword,fn()=>null));
    $token=accountCodeStart('','verify','email','verify',['id'=>'test'],$send);
    $r=ownerRead('code-'.$token);$r['expires']=time()-1;ownerWrite('code-'.$token,$r);codeReject(fn()=>accountCodeFinish($token,$code,'',fn()=>null));
    $failed=accountCodeStart('0501234567','reset','phone','test',null,function(){throw new RuntimeException('delivery failed');});
    codeCheck($failed === '', 'Failed SMS delivery should be reported as a failed reset-code request.');
    echo "PASS: saved-contact codes, incorrect code rejection, reset, single-use and expiration; no messages sent.\n";
}finally{foreach(glob($dir.'/*')as $f)unlink($f);unlink($dir.'/.htaccess');rmdir($dir);}
