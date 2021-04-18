<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Exception;

class Tools
{
    public static function convertIp2String(string $str): string|false
    {
        $l = strlen($str);
        $format = 'A4';
        if ($l > 5) {
            $format = 'A16';
        }
        return inet_ntop(pack($format, $str));
    }

    public static function convertIp2Number(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return current(unpack("A4", (string) inet_pton($ip)) ?: []);
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return current(unpack("A16", (string) inet_pton($ip)) ?: []);
        }
        throw new Exception("Please supply a valid IPv4 or IPv6 address");
    }

    public static function highLight(string $txt, string $word): string
    {
        return str_replace($word, '<b>' . $word . '</b>', $txt);
    }
}
