<?php

declare(strict_types=1);

namespace PaymentSdk\Data;

use PaymentSdk\Enums\ErrorCode;

final class PaymentError
{
    public function __construct(
        public readonly ErrorCode $code,
        public readonly string $message,
        public readonly ?string $gatewayCode = null,
        public readonly ?string $gatewayMessage = null,
    ) {}
}
