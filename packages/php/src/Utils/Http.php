<?php

declare(strict_types=1);

namespace PaymentSdk\Utils;

use PaymentSdk\Enums\ErrorCode;
use PaymentSdk\Exceptions\PaymentSDKException;

final class Http
{
    /**
     * POST JSON to a URL with retries and exponential backoff.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $extraHeaders
     * @param callable(string, array<string, mixed>, array<string, string>): array<string, mixed>|null $httpClient
     * @return array<string, mixed>
     * @throws PaymentSDKException
     */
    public static function post(
        string $url,
        array $body,
        int $timeoutMs = 30_000,
        int $retries = 2,
        array $extraHeaders = [],
        ?callable $httpClient = null,
    ): array {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $retries) {
            try {
                if ($httpClient !== null) {
                    /** @var array<string, mixed> */
                    return ($httpClient)($url, $body, $extraHeaders);
                }
                return self::curlPost($url, $body, $timeoutMs, $extraHeaders);
            } catch (PaymentSDKException $e) {
                if ($e->errorCode !== ErrorCode::NETWORK_ERROR && $e->errorCode !== ErrorCode::TIMEOUT) {
                    throw $e;
                }
                $lastException = $e;
                $attempt++;
                if ($attempt <= $retries) {
                    usleep((int) (min(1_000, 100 * (2 ** $attempt)) * 1_000));
                }
            }
        }

        throw $lastException ?? PaymentSDKException::networkError();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     * @throws PaymentSDKException
     */
    private static function curlPost(string $url, array $body, int $timeoutMs, array $extraHeaders): array
    {
        $ch = curl_init();

        $headers = array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            array_map(static fn($k, $v) => "{$k}: {$v}", array_keys($extraHeaders), array_values($extraHeaders)),
        );

        curl_setopt_array($ch, [
            CURLOPT_URL               => $url,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode($body),
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(10_000, $timeoutMs),
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw PaymentSDKException::timeoutError();
        }

        if ($errno !== CURLE_OK || $response === false) {
            throw PaymentSDKException::networkError();
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw PaymentSDKException::gatewayError('Invalid JSON response from gateway');
        }

        return $decoded;
    }
}
