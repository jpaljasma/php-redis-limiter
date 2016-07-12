<?php

use DI\Scope;
use RedisRateLimiter\Limiter;
use Interop\Container\ContainerInterface;

return [
    'redis.port' => 6379,
    'redis.host' => '127.0.0.1',
    'limiter.keyprefix' => 'rlimit:',
    Redis::class => function (ContainerInterface $c) {
        $redis = new Redis();
        $redis->connect($c->get('redis.host'), $c->get('redis.port'), 500);
        return $redis;
    },
    Limiter::class => DI\object(Limiter::class)
        ->constructorParameter('keyPrefix', DI\get('limiter.keyprefix'))
        ->constructorParameter('useLua', true)
        ->scope(Scope::PROTOTYPE),
];