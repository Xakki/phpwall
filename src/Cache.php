<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Exception;
use Memcached;

class Cache
{
    private ?Memcached $memcached = null;

    public function __construct(private PHPWall $owner, private array $config, private string $cachePrefix)
    {
    }

    public function setIpIsTrust(string $ip, int $trust): void
    {
        $key = $this->getKeyIp($ip);
        $this->delete($key);
        $this->delete($key . '-bunTimeout');
        $this->set($key . '-trust', $trust);
    }

    protected function getKeyIp(string $ip): string
    {
        return $this->cachePrefix . '-' . $ip;
    }

    public function delete(string $key): bool
    {
        $this->connect();
        return $this->memcached->delete($key);
    }

    protected function connect(): void
    {
        if ($this->memcached) {
            return;
        }

        $this->memcached = new Memcached();
        if (!$this->memcached->addServer($this->config[0], $this->config[1])) {
            throw new Exception('Cant connect to Memcached');
        }

        $this->owner->restoreCache();
    }

    public function set(string $key, mixed $value, int $expiration = 0): bool
    {
        $this->connect();
        return $this->memcached->set($key, $value, $expiration);
    }

    public function getIpCacheFrequency(string $ip): false|int
    {
        return $this->get($this->getKeyIp($ip));
    }

    /**************************************/

    public function get(string $key): mixed
    {
        $this->connect();
        return $this->memcached->get($key);
    }

    public function getIpCacheTrust(string $ip): false|int
    {
        return $this->get($this->getKeyIp($ip) . '-trust');
    }

    public function getIpCacheBunTimeout(string $ip): false|int
    {
        return $this->get($this->getKeyIp($ip) . '-bunTimeout');
    }

    public function setIpCache(string $ip, int $banTimeOut, ?int $trust = null): void
    {
        $key = $this->getKeyIp($ip);
        if (!is_null($trust)) {
            $this->set($key . '-trust', $trust, $banTimeOut);
        } else {
            $banTimeOutCache = $this->get($key . '-bunTimeout');
            if ($banTimeOutCache !== false) {
                $banTimeOut = $banTimeOutCache;
            }
        }
        $this->set($key . '-time', time(), $banTimeOut);
        $this->set($key . '-bunTimeout', $banTimeOut, $banTimeOut);
        $this->inc($key, $banTimeOut);
    }

    public function inc(string $key, int $expiration = 0): false|int
    {
        $this->connect();

        if ($this->memcached->get($key) === false) {
            $this->memcached->set($key, 1, $expiration);
            $v = 1;
        } else {
            $v = $this->memcached->increment($key);
            $this->memcached->touch($key, $expiration);
        }

        return $v;
    }

    public function getIpInfo(string $ip): array
    {
        $key = $this->getKeyIp($ip);
        return [
            'ip' => $ip,
            'time' => (int)$this->get($key . '-time'),
            'trust' => (int)$this->get($key . '-trust'),
            'bunTimeout' => (int)$this->get($key . '-bunTimeout'),
            'cnt' => (int)$this->get($key),
        ];
    }
}
