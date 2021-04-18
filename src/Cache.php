<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Exception;
use Memcached;

class Cache
{
    private PHPWall $owner;
    private ?Memcached $memcached = null;
    /**
     * @var array{0: string, 1: int}
     */
    private array $config;

    public function __construct(PHPWall $owner, array $config)
    {
        $this->config = $config;
        $this->owner = $owner;
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

    public function get(string $key): mixed
    {
        $this->connect();
        return $this->memcached->get($key);
    }

    public function delete(string $key): bool
    {
        $this->connect();
        return $this->memcached->delete($key);
    }

    public function inc(string $key, int $expiration = 0): false|int
    {
        $this->connect();
        $this->memcached->set($key . '-time', time(), $expiration);
        $this->memcached->touch($key, $expiration);
        return $this->memcached->increment($key);
    }
}
