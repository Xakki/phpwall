<?php

declare(strict_types=1);

namespace Xakki\PHPWall\CacheDrivers;

interface CacheInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $expirationSecond = 0): bool;
    public function inc(string $key): int;

    public function delete(string $key): bool;
}
