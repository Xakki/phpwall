<?php

namespace Xakki\PHPWall\CacheDrivers;

use InvalidArgumentException;
use RuntimeException;

/**
 * Memcached cache driver for PHPWall.
 * Implements the CacheInterface.
 */
class Memcached implements CacheInterface
{
    /** @var \Memcached */
    protected $memcached;

    /**
     * A persistent ID for the Memcached connection to allow connection reuse across PHP requests.
     * @var string
     */
    private $memcacheId = 'phpwall_cache';

    /**
     * Memcached constructor.
     *
     * @param string[] $servers An array of Memcached servers, e.g., ['127.0.0.1:11211'].
     * @param string $cachePrefix A prefix for all cache keys to avoid collisions.
     * @throws InvalidArgumentException if the servers array is empty.
     * @throws RuntimeException if connection to Memcached servers fails.
     */
    public function __construct(array $servers, $cachePrefix)
    {
        if (empty($servers)) {
            throw new InvalidArgumentException('Memcached server list cannot be empty.');
        }

        $this->memcached = new \Memcached($this->memcacheId);

        // If the server list is empty, it means we have a persistent connection that hasn't been configured yet.
        if (empty($this->memcached->getServerList())) {
            $this->memcached->setOption(\Memcached::OPT_PREFIX_KEY, $cachePrefix);
            $this->memcached->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
            $this->memcached->setOption(\Memcached::OPT_NO_BLOCK, true);
            $this->memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 200); // 200ms

            $serverList = [];
            foreach ($servers as $server) {
                $parts = explode(':', $server);
                $host = $parts[0];
                $port = isset($parts[1]) ? $parts[1] : 11211;
                $serverList[] = [$host, (int)$port];
            }

            if (!$this->memcached->addServers($serverList)) {
                throw new RuntimeException('Could not add Memcached servers to the pool.');
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get($key)
    {
        return $this->memcached->get($key);
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expirationSecond = 0)
    {
        return $this->memcached->set($key, $value, $expirationSecond);
    }

    /**
     * {@inheritdoc}
     * This implementation handles race conditions by attempting to atomically add the key
     * if it doesn't exist, and then incrementing it.
     */
    public function inc($key)
    {
        // Atomically add the key with a value of 1 if it doesn't exist.
        // The expiration is 0 (never expires), assuming TTL is managed elsewhere.
        if ($this->memcached->add($key, 1)) {
            return 1;
        }

        // If the key already exists, increment it.
        $newValue = $this->memcached->increment($key);

        // increment returns false on failure.
        return $newValue !== false ? $newValue : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        return $this->memcached->delete($key);
    }
}
