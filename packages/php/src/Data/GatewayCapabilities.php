<?php

declare(strict_types=1);

namespace PaymentSdk\Data;

final class GatewayCapabilities
{
    /**
     * @param string[] $currencies
     * @param string[] $paymentMethods
     */
    public function __construct(
        public readonly bool $supportRefund,
        public readonly bool $supportPartialRefund,
        public readonly bool $supportRecurring,
        public readonly bool $supportWebhook,
        public readonly bool $supportQRCode,
        public readonly bool $supportInstallment,
        public readonly array $currencies,
        public readonly array $paymentMethods,
    ) {}
}
