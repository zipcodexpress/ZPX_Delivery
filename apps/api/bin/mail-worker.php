<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Zpx\Mail\Worker;
use Zpx\Identity\Secrets;
use Zpx\Infrastructure\Database\Connection;
if (getenv('APP_ENV')!=='development') { exit(1); }
$worker=new Worker(Connection::fromEnvironment(),new Secrets());
do {
    try { $worked=$worker->once(); }
    catch (Throwable $e) { error_log('Mail worker unavailable; no message details logged.');$worked=false; }
    if (!$worked) { sleep(3); }
} while (!in_array('--once',$argv,true));
