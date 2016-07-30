<?php

error_reporting(E_ALL);
ini_set('display_errors', 'On');

use RedisRateLimiter\Limiter;

$container = require __DIR__ . '/app/bootstrap.php';

/** @var RedisRateLimiter\Limiter $limiter */
$limiter = $container->get(Limiter::class);


$requestApiKey = 'api:ip:' . $_SERVER['REMOTE_ADDR'];

// 1000 api calls per second
$limitCalls = 1000;
$timeLimit = 1;
// we will burst 10000 calls
$doCalls = 30000;

$limiter->reset($requestApiKey);

$response = null;
$timeMs = microtime(true);
$successCount = 0;
$failedCount = 0;

for ($i = 0; $i < $doCalls; $i++) {
    // $limitCalls + 1 attempt will throttle
    $response = $limiter->hit($requestApiKey, $limitCalls, $timeLimit);
    if($response) {
        if(true === $response['overlimit']) {
            $failedCount++;
        }
        else {
            $successCount++;
        }
    }
    else {
        $failedCount++;
    }
}

var_dump(number_format($doCalls) . ' calls performed');
var_dump('Successful calls: '.number_format($successCount));
var_dump('Throttled calls: '.number_format($failedCount));

$timeS = microtime(true) - $timeMs;
$reqS = (int)($doCalls / $timeS);

var_dump(($timeS) . ' seconds');
var_dump($reqS . ' requests/s');

var_dump($response);

$limiter->reset('login:failed');
var_dump($limiter->hit('login:failed', 2, 30));
var_dump($limiter->hit('login:failed', 2, 30));
var_dump($limiter->hit('login:failed', 2, 30));