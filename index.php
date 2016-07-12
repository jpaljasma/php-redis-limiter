<?php

error_reporting(E_ALL);
ini_set('display_errors', 'On');

use RedisRateLimiter\Limiter;

$container = require __DIR__ . '/app/bootstrap.php';

/** @var RedisRateLimiter\Limiter $limiter */
$limiter = $container->get(Limiter::class);


$requestApiKey = 'api:ip:' . $_SERVER['REMOTE_ADDR'];
$limitCalls = 3;
$timeLimit = 30;
$doCalls = 30000;

$limiter->reset($requestApiKey);

$response = null;
for ($i = 0; $i < $doCalls; $i++) {
    // $limitCalls + 1 attempt will throttle
    $response = $limiter->hit($requestApiKey, $limitCalls, $timeLimit);
}
$timeMs = $timeLimit * 1000 - $response['waitms'];
$reqS = (int)($doCalls / $timeMs * 1000);

var_dump(number_format($doCalls) . ' calls performed');
var_dump(($timeMs / 1000) . ' seconds');
var_dump($reqS . ' requests/s');

var_dump($limiter->hit($requestApiKey, 2, 30));