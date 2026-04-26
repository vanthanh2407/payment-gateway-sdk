<?php

declare(strict_types=1);

namespace App\Services;

use PaymentSdk\Data\WebhookEvent;

final class WebhookStore
{
    private const FILE = 'app/webhooks.json';
    private const MAX  = 100;

    public static function add(WebhookEvent $event, string $rawBody, string $gateway): void
    {
        $file = storage_path(self::FILE);
        $fp   = fopen($file, 'c+');
        if ($fp === false) {
            return;
        }

        flock($fp, LOCK_EX);
        $size   = filesize($file) ?: 0;
        $json   = $size > 0 ? fread($fp, $size) : '[]';
        $events = json_decode((string) $json, true) ?? [];

        array_unshift($events, [
            'id'         => uniqid('', true),
            'gateway'    => $gateway,
            'eventType'  => $event->eventType->value,
            'orderId'    => $event->orderId,
            'transactionId' => $event->transactionId,
            'amount'     => $event->amount,
            'currency'   => $event->currency,
            'status'     => $event->status->value,
            'rawBody'    => $rawBody,
            'receivedAt' => $event->receivedAt->format('c'),
        ]);

        $events = array_slice($events, 0, self::MAX);

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, (string) json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $file = storage_path(self::FILE);
        if (!file_exists($file)) {
            return [];
        }

        $fp = fopen($file, 'r');
        if ($fp === false) {
            return [];
        }

        flock($fp, LOCK_SH);
        $size   = filesize($file) ?: 0;
        $json   = $size > 0 ? fread($fp, $size) : '[]';
        flock($fp, LOCK_UN);
        fclose($fp);

        return json_decode((string) $json, true) ?? [];
    }

    public static function clear(): void
    {
        $file = storage_path(self::FILE);
        $fp   = fopen($file, 'w');
        if ($fp === false) {
            return;
        }

        flock($fp, LOCK_EX);
        fwrite($fp, '[]');
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
