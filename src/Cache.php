<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Xakki\PHPWall\CacheDrivers\CacheInterface;
use Xakki\PHPWall\CacheDrivers\Memcached;
use Xakki\PHPWall\CacheDrivers\Redis;

class Cache
{
    private ?CacheInterface $conn = null;

    public function __construct(
        private readonly PHPWall $owner,
        /** string[] */
        private readonly array $memCacheServers,
        /** array<string, string|numeric|bool> */
        private readonly array $redisCacheServer,
        private readonly string $cachePrefix
    ) {
    }

    protected function connect(): CacheInterface
    {
        if ($this->conn) {
            return $this->conn;
        }

        if ($this->memCacheServers) {
            $this->conn = new Memcached($this->memCacheServers, $this->cachePrefix);
        } else {
            $this->conn = new Redis($this->redisCacheServer, $this->cachePrefix);
        }

        $this->owner->restoreCache();

        return $this->conn;
    }

    public function get(string $key): mixed
    {
        return $this->connect()->get($key);
    }

    public function set(string $key, mixed $value, int $expirationSecond = 0): bool
    {
        return $this->connect()->set($key, $value, $expirationSecond);
    }

    public function inc(string $key): int
    {
        return $this->connect()->inc($key);
    }

    public function delete(string $key): bool
    {
        return $this->connect()->delete($key);
    }

    /**************************************/

    public function setIpIsTrust(string $ip, int $trust): void
    {
        $key = $this->getKeyIp($ip);
        $this->delete($key);
        $this->delete($key . '-bunTimeout');
        $this->set($key . '-trust', $trust);
    }

    protected function getKeyIp(string $ip): string
    {
        return $ip;
    }

    public function getIpCacheFrequency(string $ip): int
    {
        return (int) $this->get($this->getKeyIp($ip));
    }

    public function getIpCacheTrust(string $ip): int
    {
        return (int) $this->get($this->getKeyIp($ip) . '-trust');
    }

    public function getIpCacheBunTimeout(string $ip): int
    {
        return (int) $this->get($this->getKeyIp($ip) . '-bunTimeout');
    }

    public function setIpCache(string $ip, int $banTimeOut, ?int $trust = null): void
    {
        $key = $this->getKeyIp($ip);
        if (!is_null($trust)) {
            $this->set($key . '-trust', $trust, $banTimeOut);
        } else {
            $banTimeOutCache = (int) $this->get($key . '-bunTimeout');
            if ($banTimeOutCache) {
                $banTimeOut = $banTimeOutCache;
            }
        }
        $this->set($key . '-time', time(), $banTimeOut);
        $this->set($key . '-bunTimeout', $banTimeOut, $banTimeOut);
        $this->inc($key);
    }

    /**
     * @param string $ip
     * @return array{ip: string, time: int, trust: int, bunTimeout: int, cnt: int}
     */
    public function getIpInfo(string $ip): array
    {
        $key = $this->getKeyIp($ip);
        return [
            'ip' => $ip,
            'time' => (int) $this->get($key . '-time'),
            'trust' => (int) $this->get($key . '-trust'),
            'bunTimeout' => (int) $this->get($key . '-bunTimeout'),
            'cnt' => (int) $this->get($key),
        ];
    }
}
