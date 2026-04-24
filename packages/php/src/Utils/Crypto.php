<?php

declare(strict_types=1);

namespace PaymentSdk\Utils;

final class Crypto
{
    public static function hmacSHA256(string $data, string $key): string
    {
        return hash_hmac('sha256', $data, $key);
    }

    /**
     * @param array<string, string> $params
     * @param string[] $keys Keys in the required signing order
     */
    public static function buildRawString(array $params, array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = "{$key}={$params[$key]}";
        }
        return implode('&', $parts);
    }

    public static function timingSafeEqual(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
