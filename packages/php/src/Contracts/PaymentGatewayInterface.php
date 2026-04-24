<?php

declare(strict_types=1);

namespace PaymentSdk\Contracts;

use PaymentSdk\Data\GatewayCapabilities;
use PaymentSdk\Data\PaymentResult;
use PaymentSdk\Data\RefundResult;
use PaymentSdk\Data\WebhookEvent;

interface PaymentGatewayInterface
{
    public function getName(): string;

    public function getCapabilities(): GatewayCapabilities;

    /**
     * @param array{
     *   orderId: string,
     *   amount: int,
     *   currency?: string,
     *   description: string,
     *   returnUrl: string,
     *   ipnUrl?: string,
     *   locale?: string,
     *   metadata?: array<string, mixed>,
     * } $input
     */
    public function createPayment(array $input): PaymentResult;

    /**
     * @param array<string, string> $headers
     */
    public function verifyWebhook(mixed $payload, array $headers): WebhookEvent;

    public function getTransaction(string $transactionId, ?string $orderId = null): PaymentResult;

    /**
     * @param array{
     *   transactionId: string,
     *   orderId: string,
     *   amount: int,
     *   reason?: string,
     * } $input
     */
    public function refund(array $input): RefundResult;
}
