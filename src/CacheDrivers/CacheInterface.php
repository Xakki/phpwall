<?php

namespace Xakki\PHPWall\CacheDrivers;

interface CacheInterface
{
    /**
     * Retrieves an item from the cache by key.
     *
     * @param string $key The key of the item to retrieve.
     * @return mixed|null Returns the value of the item from the cache, or null if the key is not found.
     */
    public function get($key);

    /**
     * Stores an item in the cache.
     *
     * @param string $key The key of the item to store.
     * @param mixed $value The value to be stored. Must be serializable.
     * @param int $expirationSecond The number of seconds until the item expires. 0 means no expiration.
     * @return bool True on success, false on failure.
     */
    public function set($key, $value, $expirationSecond = 0);

    /**
     * Increments a numeric item's value.
     *
     * @param string $key The key of the item to increment.
     * @return int|false The new value on success, or false on failure.
     */
    public function inc($key);

    /**
     * Deletes an item from the cache.
     *
     * @param string $key The key of the item to delete.
     * @return bool True on success, false on failure.
     */
    public function delete($key);
}
