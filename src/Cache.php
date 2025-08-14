<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use RuntimeException;
use Xakki\PHPWall\CacheDrivers\CacheInterface;
use Xakki\PHPWall\CacheDrivers\Memcached;
use Xakki\PHPWall\CacheDrivers\Redis;

/**
 * @phpstan-type CacheServer array{host: string, port: int}
 * @phpstan-type IpInfo array{ip: string, time: int, trust: int, bunTimeout: int, cnt: int}
 */
class Cache
{
    private ?CacheInterface $conn = null;

    public function __construct(
        private readonly PHPWall $owner,
        /** @var string[] */
        private readonly array $memCacheServers,
        /** @var CacheServer */
        private readonly array $redisCacheServer,
        private readonly string $cachePrefix
    ) {
    }

    /**
     * @throws RuntimeException
     */
    protected function connect(): CacheInterface
    {
        if ($this->conn) {
            return $this->conn;
        }

        if ($this->memCacheServers) {
            $this->conn = new Memcached($this->memCacheServers, $this->cachePrefix);
        } elseif ($this->redisCacheServer) {
            $this->conn = new Redis($this->redisCacheServer, $this->cachePrefix);
        } else {
            throw new RuntimeException('No cache driver configured.');
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

    public function setIpIsTrust(string $ip, int $trust, int $expirationSecond): void
    {
        $baseKey = $this->getKeyIp($ip);
        $this->delete($baseKey);
        $this->delete($baseKey . '-bunTimeout');
        $this->delete($baseKey . '-time');
        $this->set($baseKey . '-trust', $trust, $expirationSecond);
    }

    protected function getKeyIp(string $ip): string
    {
        // It is not necessary to add a prefix here, because the driver already adds it.
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

        // If a ban timeout is already cached, use it to extend the ban.
        $banTimeOutCache = (int) $this->get($key . '-bunTimeout');
        if ($banTimeOutCache > 0) {
            $banTimeOut = $banTimeOutCache;
        }

        if ($trust !== null) {
            $this->set($key . '-trust', $trust, $banTimeOut);
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
        // This could be optimized by using a `getMultiple` command if the cache driver supports it.
        return [
            'ip' => $ip,
            'time' => (int) $this->get($key . '-time'),
            'trust' => (int) $this->get($key . '-trust'),
            'bunTimeout' => (int) $this->get($key . '-bunTimeout'),
            'cnt' => (int) $this->get($key),
        ];
    }
}
