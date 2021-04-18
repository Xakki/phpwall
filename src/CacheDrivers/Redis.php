<?php

declare(strict_types=1);

namespace Xakki\PHPWall\CacheDrivers;

class Redis implements CacheInterface
{
    protected \Redis $redis;
    public function __construct(array $options, string $cachePrefix)
    {
        $database = 0;
        if (isset($options['database'])) {
            $database = (int) $options['database'];
            unset($options['database']);
        }
        $this->redis = new \Redis($options);
        if ($database) {
            $this->redis->select($database);
        }
        $this->redis->_prefix($cachePrefix);
    }

    public function get(string $key): mixed
    {
        return $this->redis->get($key);
    }

    public function set(string $key, mixed $value, int $expirationSecond = 0): bool
    {
        $option = [];
        if ($expirationSecond > 0) {
            $option = ['EX' => $expirationSecond];
        }
        $v = $this->redis->set($key, $value, $option);
        if ($v !== true) {
            throw new \Error('PHPWall: error redis set: ' . json_encode($v));
        }
        return true;
    }

    public function inc(string $key): int
    {
        return (int) $this->redis->incr($key);
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($key);
    }
}
