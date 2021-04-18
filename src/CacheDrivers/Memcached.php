<?php

declare(strict_types=1);

namespace Xakki\PHPWall\CacheDrivers;

class Memcached implements CacheInterface
{
    protected \Memcached $memcached;
    private string $memcacheId = 'wall';

    public function __construct(array $servers, string $cachePrefix)
    {
        $this->memcached = new \Memcached($this->memcacheId);
        $this->memcached->setOption(\Memcached::OPT_PREFIX_KEY, $cachePrefix);
        $this->memcached->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        $this->memcached->setOption(\Memcached::OPT_NO_BLOCK, true);
        $this->memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 4);

        foreach ($servers as $server) {
            $server = explode(':', $server);
            if (!$this->memcached->addServer($server[0], (int) $server[1])) {
                throw new \Error('Cant connect to Memcached');
            }
        }
    }

    public function get(string $key): mixed
    {
        return $this->memcached->get($key);
    }

    public function set(string $key, mixed $value, int $expirationSecond = 0): bool
    {
        return $this->memcached->set($key, $value, $expirationSecond);
    }

    public function inc(string $key): int
    {
        return (int) $this->memcached->increment($key);
    }

    public function delete(string $key): bool
    {
        return $this->memcached->delete($key);
    }
}
