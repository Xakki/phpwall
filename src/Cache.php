<?php

namespace Xakki\PHPWall;

use Exception;
use Memcached;

class Cache
{
    /** @var Memcached  */
    private $memcached;
    /** @var PHPWall  */
    private $owner;
    /** @var array  */
    private $config;
    /** @var string|string  */
    private $cachePrefix;

    public function __construct(PHPWall $owner, $config, $cachePrefix)
    {
        $this->owner  = $owner;
        $this->config  = $config;
        $this->cachePrefix  = $cachePrefix;
    }

    /**
     * @param string $ip
     * @param int $trust
     * @return void
     */
    public function setIpIsTrust($ip, $trust)
    {
        $key = $this->getKeyIp($ip);
        $this->delete($key);
        $this->delete($key . '-bunTimeout');
        $this->set($key . '-trust', $trust);
    }

    /**
     * @param string $ip
     * @return string
     */
    protected function getKeyIp($ip)
    {
        return $this->cachePrefix . '-' . $ip;
    }

    /**
     * @param string $key
     * @return bool
     * @throws Exception
     */
    public function delete($key)
    {
        $this->connect();
        return $this->memcached->delete($key);
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function connect()
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

    /**
     * @param string $key
     * @param mixed $value
     * @param int $expiration
     * @return bool
     * @throws Exception
     */
    public function set($key, $value, $expiration = 0)
    {
        $this->connect();
        return $this->memcached->set($key, $value, $expiration);
    }

    /**
     * @param string $ip
     * @return false|int
     */
    public function getIpCacheFrequency($ip)
    {
        return $this->get($this->getKeyIp($ip));
    }

    /**************************************/

    /**
     * @param string $key
     * @return mixed
     * @throws Exception
     */
    public function get($key)
    {
        $this->connect();
        return $this->memcached->get($key);
    }

    /**
     * @param string $ip
     * @return false|int
     * @throws Exception
     */
    public function getIpCacheTrust($ip)
    {
        return $this->get($this->getKeyIp($ip) . '-trust');
    }

    /**
     * @param string $ip
     * @return false|int
     * @throws Exception
     */
    public function getIpCacheBunTimeout($ip)
    {
        return $this->get($this->getKeyIp($ip) . '-bunTimeout');
    }

    /**
     * @param string $ip
     * @param int $banTimeOut
     * @param int|null $trust
     * @return void
     * @throws Exception
     */
    public function setIpCache($ip, $banTimeOut, $trust = null)
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

    /**
     * @param string $key
     * @param int $expiration
     * @return false|int
     * @throws Exception
     */
    public function inc($key, $expiration = 0)
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

    /**
     * @param string $ip
     * @return array
     * @throws Exception
     */
    public function getIpInfo($ip)
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
