<?php

declare(strict_types=1);

namespace Xakki\PHPWall\CacheDrivers;

interface CacheInterface
{
    /**
     * Retrieves an item from the cache by key.
     *
     * @param string $key The key of the item to retrieve.
     * @return mixed|null Returns the value of the item from the cache, or null if the key is not found.
     */
    public function get(string $key): mixed;

    /**
     * Stores an item in the cache.
     *
     * @param string $key The key of the item to store.
     * @param mixed $value The value to be stored. Must be serializable.
     * @param int $expirationSecond The number of seconds until the item expires. 0 means no expiration.
     * @return bool True on success, false on failure.
     */
    public function set(string $key, mixed $value, int $expirationSecond = 0): bool;

    /**
     * Increments a numeric item's value.
     *
     * @param string $key The key of the item to increment.
     * @return int The new value on success, or 0 on failure.
     */
    public function inc(string $key): int;

    /**
     * Deletes an item from the cache.
     *
     * @param string $key The key of the item to delete.
     * @return bool True on success, false on failure.
     */
    public function delete(string $key): bool;
}
