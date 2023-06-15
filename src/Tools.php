<?php

namespace Xakki\PHPWall;

use InvalidArgumentException;

class Tools
{
    /**
     * Converts a binary IP representation to a human-readable string.
     *
     * @param string $binaryIp The packed binary representation of an IP address.
     * @return string The string representation of the IP address (e.g., \"127.0.0.1\" or \"::1\").
     * @throws InvalidArgumentException if the binary string is not a valid IPv4 or IPv6 address.
     */
    public static function convertIp2String($binaryIp)
    {
        $length = strlen($binaryIp);
        if ($length !== 4 && $length !== 16) {
            throw new InvalidArgumentException('Invalid binary IP address length.');
        }

        $ipString = inet_ntop($binaryIp);

        if ($ipString === false) {
            throw new InvalidArgumentException('Failed to convert binary IP to string.');
        }

        return $ipString;
    }

    /**
     * Converts a human-readable IP address string to its packed binary representation.
     *
     * @param string $ip The IP address string (e.g., \"127.0.0.1\" or \"::1\").
     * @return string The packed binary representation.
     * @throws InvalidArgumentException if the string is not a valid IPv4 or IPv6 address.
     */
    public static function convertIp2Number($ip)
    {
        $binaryIp = inet_pton($ip);
        if ($binaryIp === false) {
            throw new InvalidArgumentException("The provided string is not a valid IPv4 or IPv6 address: {$ip}");
        }
        return $binaryIp;
    }

    /**
     * Highlights a word within a text string by wrapping it in <b> tags.
     * The text is escaped to prevent XSS.
     *
     * @param string $text The text to search within.
     * @param string $word The word to highlight.
     * @return string The text with the highlighted word.
     */
    public static function highLight($text, $word)
    {
        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $escapedWord = htmlspecialchars($word, ENT_QUOTES, 'UTF-8');

        if (empty($escapedWord)) {
            return $escapedText;
        }

        return str_replace($escapedWord, '<b>' . $escapedWord . '</b>', $escapedText);
    }
}
