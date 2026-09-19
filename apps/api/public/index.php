<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$app = new think\App(dirname(__DIR__));
$app->debug(false);
$response = $app->http->run();
$response->send();
$app->http->end($response);
