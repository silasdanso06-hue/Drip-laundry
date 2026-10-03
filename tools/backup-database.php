<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/lib/database.php';
$path=databaseBackup();
echo 'PHP data backup created and verified: private/backups/'.basename($path).PHP_EOL;
