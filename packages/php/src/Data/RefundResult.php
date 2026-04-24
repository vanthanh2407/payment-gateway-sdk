<?php

declare(strict_types=1);

namespace PaymentSdk\Data;

use PaymentSdk\Enums\RefundStatus;

final class RefundResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $orderId,
        public readonly int $amount,
        public readonly RefundStatus $status,
        public readonly mixed $rawResponse,
        public readonly ?string $refundId = null,
        public readonly ?string $transactionId = null,
        public readonly ?PaymentError $error = null,
    ) {}
}
