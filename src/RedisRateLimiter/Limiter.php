<?php

namespace RedisRateLimiter;

use Redis;

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
    private function _lua()
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
    private function _luaSha()
    {
        return sha1($this->_lua());
    }

    /**
     * Runs rate limiter script via LUA
     *
     * @param string $key
     * @param int $window time in seconds
     * @return array
     */
    private function runLua($key, $window)
    {
        echo __METHOD__.PHP_EOL;
        $luaSha = $this->_luaSha();
        $response = $this->redis->script('EXISTS', $luaSha);

        if (0 === $response[0]) {
            // load LUA script into redis registry
            $this->redis->script('LOAD', $this->_lua());
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
        echo __METHOD__.PHP_EOL;
        $tmpKey = 'tmp:' . $key;
        return $this->redis->multi()
            ->setex($tmpKey, $window, 0)
            ->renameNx($tmpKey, $key)
            ->incr($key)
            ->pttl($key)
            ->exec();
    }

    /**
     * @param string $key Redis key
     * @param int $limit How many times the key can be accessed within TTL ms
     * @param int $window TTL in seconds
     * @return array
     * @throws \Exception
     */
    public function limit($key, $limit, $window)
    {

        $runMethod = $this->useLua ? 'runLua' : 'runRedis';

        $runKey = $this->keyPrefix.$key;
        $response = $this->$runMethod($runKey, $window);

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
                'wait' => $overLimit ? max(0, $currentTTLms) : 0,
                'over' => $overLimit,
            ];
        } else {
            throw new \Exception('Could not set the limit');
        }
    }
}