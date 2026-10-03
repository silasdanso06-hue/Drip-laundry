<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/lib/sms.php';
if(empty($argv[1])){echo "Usage: php tools/test-sms.php PHONE (sends one paid SMS)\n";exit(1);}
$s=ownerRead('sms-settings')??[];
try {
    $result=smsSend(smsPayload($argv[1],'Dripclean Laundry SMS test: we are open 24 hours with free pickup and delivery. Thank you!', $s['sender']??''),$s['key']??'');
    ownerWrite('sms-delivery-test',['at'=>gmdate('c'),'result'=>$result]);echo $result,PHP_EOL;
}catch(Throwable $e){echo $e->getMessage(),PHP_EOL;exit(1);}
