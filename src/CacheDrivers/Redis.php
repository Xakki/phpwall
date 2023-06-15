<?php

namespace Xakki\PHPWall\CacheDrivers;

use InvalidArgumentException;
use Exception;
use RuntimeException;

/**
 * Redis cache driver for PHPWall.
 * Implements the CacheInterface using the phpredis extension.
 */
class Redis implements CacheInterface
{
    /** @var \Redis */
    protected $redis;

    /**
     * Redis constructor.
     *
     * @param array{host?: string, port?: int, connectTimeout?: float, persistent?: bool, readTimeout?: float, database?: int} $options Connection options for Redis.
     * @param string $cachePrefix A prefix for all cache keys to avoid collisions.
     * @throws InvalidArgumentException if required options are missing.
     * @throws RuntimeException if the connection to Redis fails.
     */
    public function __construct(array $options, $cachePrefix)
    {
        $this->redis = new \Redis();

        $host = isset($options['host']) ? $options['host'] : '127.0.0.1';
        $port = (int)(isset($options['port']) ? $options['port'] : 6379);
        $connectTimeout = (float)(isset($options['connectTimeout']) ? $options['connectTimeout'] : 2.5);
        $persistent = !empty($options['persistent']);

        try {
            if ($persistent) {
                $this->redis->pconnect($host, $port, $connectTimeout, 'phpwall_redis');
            } else {
                $this->redis->connect($host, $port, $connectTimeout);
            }

            if (isset($options['readTimeout'])) {
                $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, (float)$options['readTimeout']);
            }

            if (isset($options['database'])) {
                $this->redis->select((int)$options['database']);
            }

            // Use a separator for better readability in redis-cli
            $this->redis->setOption(\Redis::OPT_PREFIX, $cachePrefix . ':');
            // Automatically serialize/unserialize data
            $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to connect or configure Redis: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get($key)
    {
        // With OPT_SERIALIZER, phpredis handles unserialization automatically.
        // It returns false if the key does not exist.
        $value = $this->redis->get($key);
        return $value !== false ? $value : null;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expirationSecond = 0)
    {
        // With OPT_SERIALIZER, phpredis handles serialization automatically.
        if ($expirationSecond > 0) {
            return $this->redis->setex($key, $expirationSecond, $value);
        }
        return $this->redis->set($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function inc($key)
    {
        // incr command is atomic and returns the new value.
        // It initializes the key to 0 if it doesn't exist before incrementing.
        return (int)$this->redis->incr($key);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        // del returns the number of keys that were removed.
        // We return true if at least one key was deleted.
        return $this->redis->del($key) > 0;
    }
}
