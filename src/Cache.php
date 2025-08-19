<?php

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
    /** @var CacheInterface|null */
    private $conn = null;

    /** @var PHPWall */
    private $owner;

    /** @var CacheServer[] */
    private $memCacheServers;

    /** @var CacheServer */
    private $redisCacheServer;

    /** @var string */
    private $cachePrefix;

    /**
     * Connecting to the cache only if you need to make a request
     * @param PHPWall $owner
     * @param string[] $memCacheServers
     * @param CacheServer $redisCacheServer
     * @param string $cachePrefix
     */
    public function __construct(PHPWall $owner, array $memCacheServers, array $redisCacheServer, $cachePrefix)
    {
        $this->owner = $owner;
        $this->memCacheServers = $memCacheServers;
        $this->redisCacheServer = $redisCacheServer;
        $this->cachePrefix = $cachePrefix;
    }

    /**
     * @return CacheInterface
     * @throws RuntimeException
     */
    protected function connect()
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

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return $this->connect()->get($key);
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $expirationSecond
     * @return bool
     */
    public function set($key, $value, $expirationSecond = 0)
    {
        return $this->connect()->set($key, $value, $expirationSecond);
    }

    /**
     * @param string $key
     * @return int|false
     */
    public function inc($key)
    {
        return $this->connect()->inc($key);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete($key)
    {
        return $this->connect()->delete($key);
    }

    /**************************************/

    /**
     * @param string $ip
     * @param int $trust
     * @param int $expirationSecond
     * @return void
     */
    public function setIpIsTrust($ip, $trust, $expirationSecond)
    {
        $baseKey = $this->getKeyIp($ip);
        $this->delete($baseKey);
        $this->delete($baseKey . '-bunTimeout');
        $this->delete($baseKey . '-time');
        $this->set($baseKey . '-trust', $trust, $expirationSecond);
    }

    /**
     * @param string $ip
     * @return string
     */
    protected function getKeyIp($ip)
    {
        // It is not necessary to add a prefix here, because the driver already adds it.
        return $ip;
    }

    /**
     * @param string $ip
     * @return int
     */
    public function getIpCacheFrequency($ip)
    {
        return (int) $this->get($this->getKeyIp($ip));
    }

    /**
     * @param string $ip
     * @return int
     */
    public function getIpCacheTrust($ip)
    {
        return (int) $this->get($this->getKeyIp($ip) . '-trust');
    }

    /**
     * @param string $ip
     * @return int
     */
    public function getIpCacheBunTimeout($ip)
    {
        return (int) $this->get($this->getKeyIp($ip) . '-bunTimeout');
    }

    /**
     * @param string $ip
     * @param int $banTimeOut
     * @param int|null $trust
     * @return void
     */
    public function setIpCache($ip, $banTimeOut, $trust = null)
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
     * @return IpInfo
     */
    public function getIpInfo($ip)
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
