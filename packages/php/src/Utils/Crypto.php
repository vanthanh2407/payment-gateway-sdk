<?php

declare(strict_types=1);

namespace PaymentSdk\Utils;

final class Crypto
{
    public static function hmacSHA256(string $data, string $key): string
    {
        return hash_hmac('sha256', $data, $key);
    }

    public static function hmacSHA512(string $data, string $key): string
    {
        return hash_hmac('sha512', $data, $key);
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

    /**
     * Build a sorted query string for signing — keys sorted alphabetically,
     * values RFC 3986-encoded (matches JS encodeURIComponent).
     *
     * @param array<string, string> $params
     */
    public static function buildSortedQueryString(array $params): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . rawurlencode($value);
        }
        return implode('&', $parts);
    }

    /** Format a DateTimeInterface as VNPay's yyyyMMddHHmmss in local timezone */
    public static function formatVNPayDate(\DateTimeInterface $date): string
    {
        return $date->format('YmdHis');
    }

    public static function md5(string $data): string
    {
        return md5($data);
    }

    public static function timingSafeEqual(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
