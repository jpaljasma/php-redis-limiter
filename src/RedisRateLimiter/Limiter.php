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

    const STRATEGY_SIMPLE = 'simple';
    const STRATEGY_ROLLING = 'rolling';

    /** @var string Strategy - either "simple" or "rolling" */
    private $strategy = self::STRATEGY_SIMPLE;

    /**
     * @return string
     */
    public function getStrategy()
    {
        return $this->strategy;
    }

    /**
     * @param string $strategy
     * @return Limiter
     */
    public function setStrategy($strategy)
    {
        if(!in_array($strategy, [self::STRATEGY_SIMPLE, self::STRATEGY_ROLLING])) {
            throw new \InvalidArgumentException('$strategy should be either simple or rolling');
        }
        $this->strategy = $strategy;
        return $this;
    }

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

return {r2, r3}
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

        // optimistic approach
        $response = $this->redis->evalSha($luaSha, [$key, $window], 1);
        if($response) return $response;

        // load script when failed and execute
        $this->redis->script('LOAD', $this->getRedisLuaScript());
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
        $result = $this->redis->multi()
            ->setex($tmpKey, $window, 0)
            ->renameNx($tmpKey, $key)
            ->incr($key)
            ->pttl($key)
            ->exec();

        return $result ? [ $result[2], $result[3] ] : null;
    }

    private function runRedisRolling($key, $window, $limit) {
        static $counter = 0;
        static $counter2 = 0;
        static $lastTimestamp = 0;

        $timestamp = round(microtime(true)*1000);
        if($lastTimestamp !== $timestamp) {
            $lastTimestamp = $timestamp;
            $counter = 0;
        }
        $value = $timestamp .((++$counter) / 10000);

        // seek the timestamp of nth call
        $maxAllowedTimeStamp = $timestamp - ($window * 1000);
        $timeOfLastCallWithinWindow = $this->redis->zRevRange($key, $limit-1, $limit-1, true);

        $callCount = (int)$this->redis->zCount($key, $maxAllowedTimeStamp, $timestamp);

        if($timeOfLastCallWithinWindow) {
            $timeOfLastCallWithinWindow = array_shift($timeOfLastCallWithinWindow);

            if($timeOfLastCallWithinWindow > $maxAllowedTimeStamp) {
                // over limit, set new expiration in seconds
                $this->redis->expire($key, $window);
                return [
                    $callCount,
                    $timeOfLastCallWithinWindow - $maxAllowedTimeStamp
                ];
            }
        }

        // simply add to the sorted set and return that we're fine
        $this->redis->multi()
            ->zAdd($key, $timestamp, $value)
            ->expire($key, $window)
            ->exec();
        return [
            $callCount + 1,0
        ];
    }

    /**
     * Resets the counter
     * @param string $key
     */
    public function reset($key)
    {
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

        $runKey = $this->keyPrefix . $key;
        $runMethod = null;

        switch($this->strategy) {
            case self::STRATEGY_ROLLING:
                $runMethod = $this->useLua ? 'runRedisLuaScriptRolling' : 'runRedisRolling';
                break;
            default:
                $runMethod = $this->useLua ? 'runRedisLuaScript' : 'runRedis';
                break;
        }

        try {
            $response = $this->$runMethod($runKey, $window, $limit);
        } catch (\Exception $ex) {
            $response = null;
        }

        if ($response) {

            $currentCounter = (int)$response[0];
            $currentTTLms = (int)$response[1];

            $overLimit = ($currentTTLms > 0);

            return [
                'current' => $currentCounter,
                'remaining' => $currentCounter ? max(0, $limit - $currentCounter) : 0,
                'overlimit' => $overLimit,
                'waitms' => $overLimit ? max(0, $currentTTLms) : 0,
            ];

        } else {
            throw new \Exception('Could not set rate limit hit, no response');
        }
    }
}