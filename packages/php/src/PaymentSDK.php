<?php

declare(strict_types=1);

namespace PaymentSdk;

use PaymentSdk\Contracts\PaymentGatewayInterface;
use PaymentSdk\Data\PaymentResult;
use PaymentSdk\Data\RefundResult;
use PaymentSdk\Data\WebhookEvent;
use PaymentSdk\Enums\ErrorCode;
use PaymentSdk\Exceptions\PaymentSDKException;

final class PaymentSDK
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function use(string $name, PaymentGatewayInterface $gateway): self
    {
        $this->gateways[$name] = $gateway;
        return $this;
    }

    /** @return string[] */
    public function listGateways(): array
    {
        return array_keys($this->gateways);
    }

    private function getGateway(string $name): PaymentGatewayInterface
    {
        if (!isset($this->gateways[$name])) {
            throw new PaymentSDKException(ErrorCode::INVALID_CONFIG, "Gateway '{$name}' not configured");
        }
        return $this->gateways[$name];
    }

    /** @param array<string, mixed> $input */
    public function createPayment(string $gateway, array $input): PaymentResult
    {
        return $this->getGateway($gateway)->createPayment($input);
    }

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $gateway, mixed $payload, array $headers): WebhookEvent
    {
        return $this->getGateway($gateway)->verifyWebhook($payload, $headers);
    }

    public function getTransaction(string $gateway, string $transactionId, ?string $orderId = null): PaymentResult
    {
        return $this->getGateway($gateway)->getTransaction($transactionId, $orderId);
    }

    /** @param array<string, mixed> $input */
    public function refund(string $gateway, array $input): RefundResult
    {
        return $this->getGateway($gateway)->refund($input);
    }
}
