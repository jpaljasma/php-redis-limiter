<?php

namespace RedisRateLimiter;

use Redis;

/**
 * Class Limiter
 * @package RedisRateLimiter
 */
class Limiter
{

    private $redis;
    private $keyPrefix = '';
    private $useLua = false;

    /**
     * @return string
     */
    public function getKeyPrefix()
    {
        return $this->keyPrefix;
    }

    /**
     * @param string $keyPrefix
     * @return $this Provides fluent interface
     */
    public function setKeyPrefix($keyPrefix)
    {
        $this->keyPrefix = $keyPrefix;
        return $this;
    }

    /**
     * Limiter constructor.
     * @param Redis $redis
     * @param string $keyPrefix
     * @param boolean $useLua
     */
    public function __construct(Redis $redis, $keyPrefix = '', $useLua = false)
    {
        $this->redis = $redis;
        $this->keyPrefix = $keyPrefix;
        $this->useLua = $useLua;
    }

    /**
     * LUA script
     * @return string
     */
    private function getRedisLuaScript()
    {
        $lua = <<<EOT
local key = KEYS[1]
local ttl = ARGV[1]
local tmp_key = string.format('tmp:%s', key)
local r0 = redis.call('setex', tmp_key, ttl, 0)
local r1 = redis.call('renamenx', tmp_key, key)
local r2 = redis.call('incr', key)
local r3 = redis.call('pttl', key)

return {r0, r1, r2, r3}
EOT;
        return $lua;
    }

    /**
     * SHA1 hash of the LUA script
     * @return string
     */
    private function getRedisLuaSHA()
    {
        return sha1($this->getRedisLuaScript());
    }

    /**
     * Runs rate limiter script via LUA
     *
     * @param string $key
     * @param int $window time in seconds
     * @return array
     */
    private function runRedisLuaScript($key, $window)
    {
        $luaSha = $this->getRedisLuaSHA();
        $response = $this->redis->script('EXISTS', $luaSha);

        if (0 === $response[0]) {
            // load LUA script into redis registry
            $this->redis->script('LOAD', $this->getRedisLuaScript());
        }

        return $this->redis->evalSha($luaSha, [$key, $window], 1);
    }

    /**
     * Runs rate limiter script via Redis MULTI/EXEC
     *
     * @param string $key
     * @param int $window time in seconds
     * @return array
     */
    private function runRedis($key, $window)
    {
        $tmpKey = 'tmp:' . $key;
        return $this->redis->multi()
            ->setex($tmpKey, $window, 0)
            ->renameNx($tmpKey, $key)
            ->incr($key)
            ->pttl($key)
            ->exec();
    }

    /**
     * Resets the counter
     * @param string $key
     */
    public function reset($key) {
        $runKey = $this->keyPrefix . $key;
        $this->redis->delete($runKey);
    }

    /**
     * @param string $key Redis key
     * @param int $limit How many times the key can be accessed within TTL ms
     * @param int $window TTL in seconds
     * @return array
     * @throws \Exception
     */
    public function hit($key, $limit, $window)
    {

        $runMethod = $this->useLua ? 'runRedisLuaScript' : 'runRedis';

        $runKey = $this->keyPrefix . $key;
        try {
            $response = $this->$runMethod($runKey, $window);
        } catch (\Exception $ex) {
            $response = null;
        }

        if ($response) {

            $currentCounter = (int)$response[2];
            $currentTTLms = (int)$response[3];

            if ($currentTTLms < 0) {
                $this->redis->expire($runKey, $window);
                $currentTTLms = 0;
            }

            $overLimit = ($currentCounter > $limit);

            return [
                'current' => $currentCounter,
                'remaining' => max(0, $limit - $currentCounter),
                'overlimit' => $overLimit,
                'waitms' => $overLimit ? max(0, $currentTTLms) : 0,
            ];

        } else {
            throw new \Exception('Could not set rate limit hit, no response');
        }
    }
}