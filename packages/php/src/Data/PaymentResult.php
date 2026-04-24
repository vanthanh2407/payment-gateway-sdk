<?php

declare(strict_types=1);

namespace PaymentSdk\Data;

use PaymentSdk\Enums\PaymentStatus;

final class PaymentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $orderId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly PaymentStatus $status,
        public readonly string $gateway,
        public readonly mixed $rawResponse,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?string $paymentUrl = null,
        public readonly ?string $transactionId = null,
        public readonly ?PaymentError $error = null,
    ) {}
}
