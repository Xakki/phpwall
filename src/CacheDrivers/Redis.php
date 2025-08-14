<?php

declare(strict_types=1);

namespace Xakki\PHPWall\CacheDrivers;

use InvalidArgumentException;
use RedisException;
use RuntimeException;

/**
 * Redis cache driver for PHPWall.
 * Implements the CacheInterface using the phpredis extension.
 */
class Redis implements CacheInterface
{
    protected \Redis $redis;

    /**
     * Redis constructor.
     *
     * @param array<string, mixed> $options Connection options for Redis.
     * @param string $cachePrefix A prefix for all cache keys to avoid collisions.
     * @throws InvalidArgumentException if required options are missing.
     * @throws RuntimeException if the connection to Redis fails.
     */
    public function __construct(array $options, string $cachePrefix)
    {
        $this->redis = new \Redis();

        $host = (string)($options['host'] ?? '127.0.0.1');
        $port = (int)($options['port'] ?? 6379);
        $connectTimeout = (float)($options['connectTimeout'] ?? 2.5);
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

        } catch (RedisException $e) {
            throw new RuntimeException('Failed to connect or configure Redis: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        // With OPT_SERIALIZER, phpredis handles unserialization automatically.
        // It returns false if the key does not exist.
        $value = $this->redis->get($key);
        return $value !== false ? $value : null;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, int $expirationSecond = 0): bool
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
    public function inc(string $key): int
    {
        // incr command is atomic and returns the new value.
        // It initializes the key to 0 if it doesn't exist before incrementing.
        return (int)$this->redis->incr($key);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        // del returns the number of keys that were removed.
        // We return true if at least one key was deleted.
        // @phpstan-ignore greater.invalid
        return $this->redis->del($key) > 0;
    }
}
