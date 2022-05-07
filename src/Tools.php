<?php

namespace Xakki\PHPWall;

use Exception;

class Tools
{
    /**
     * @param string $str
     * @return string|false
     */
    public static function convertIp2String($str)
    {
        $l = strlen($str);
        $format = 'A4';
        if ($l > 5) {
            $format = 'A16';
        }
        return inet_ntop(pack($format, $str));
    }

    /**
     * @param string $ip
     * @return string
     * @throws Exception
     */
    public static function convertIp2Number($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return current(unpack("A4", inet_pton($ip)));
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return current(unpack("A16", inet_pton($ip)));
        }
        throw new Exception("Please supply a valid IPv4 or IPv6 address");
    }

    /**
     * @param string $txt
     * @param string $word
     * @return string
     */
    public static function highLight($txt, $word)
    {
        return str_replace($word, '<b>' . $word . '</b>', $txt);
    }
}
