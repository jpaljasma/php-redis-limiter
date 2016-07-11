<?php

error_reporting(E_ALL);
ini_set('display_errors', 'On');

use RedisRateLimiter\Limiter;

$container = require __DIR__ . '/app/bootstrap.php';

/** @var RedisRateLimiter\Limiter $limiter */
$limiter = $container->get(Limiter::class);


var_dump($limiter->limit('ip:127.0.0.1', 1, 30));