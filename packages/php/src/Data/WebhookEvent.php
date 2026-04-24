<?php

declare(strict_types=1);

namespace PaymentSdk\Data;

use PaymentSdk\Enums\PaymentStatus;
use PaymentSdk\Enums\WebhookEventType;

final class WebhookEvent
{
    public function __construct(
        public readonly string $gateway,
        public readonly WebhookEventType $eventType,
        public readonly string $orderId,
        public readonly string $transactionId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly PaymentStatus $status,
        public readonly mixed $rawData,
        public readonly \DateTimeImmutable $receivedAt,
    ) {}
}
