# php-redis-limiter

Rate limit PHP operations, backed by Redis.
I got the inspiration from 

- [RedisGreen Simple rate limiter](http://www.redisgreen.net/library/ratelimit.html)
- [TabDigital/redis-rate-limiter](https://github.com/TabDigital/redis-rate-limiter)

### Requirements
Requires PHP 5.5+, Redis, PHP-DI

Installation: simply run `composer install`

### Usage

```php
use RedisRateLimiter\Limiter;

$container = require __DIR__ . '/app/bootstrap.php';

/** @var RedisRateLimiter\Limiter $limiter */
$limiter = $container->get(Limiter::class);
$requestApiKey = 'api:ip:' . $_SERVER['REMOTE_ADDR'];

// limit to 5 calls within 30 seconds for the same ip
$response = $limiter->hit($requestApiKey, 5, 30);

var_dump($response);
```

will output something like this:

```
array (size=4)
  'current' => int 1
  'remaining' => int 5
  'overlimit' => boolean false
  'waitms' => int 30000
```

After you hit the limit, the `remaining` property will read 0, and `overlimit` will be set to true. Your application will decide whether to bail out with error, or sleep X milliseconds with

```php
usleep($response['waitms'])`;
```
After trying again.

### Performance
Reasonable performance (measured with Redis running on localhost)

* Default: 5,500 calls/sec
* with optional LUA support: 12,000 calls/sec