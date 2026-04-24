<?php

declare(strict_types=1);

namespace PaymentSdk\Exceptions;

use PaymentSdk\Enums\ErrorCode;
use RuntimeException;

class PaymentSDKException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $code,
        string $message = '',
        public readonly ?string $gatewayCode = null,
        public readonly ?string $gatewayMessage = null,
        public readonly mixed $details = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $code->value, previous: $previous);
    }

    public static function invalidConfig(string $message): self
    {
        return new self(ErrorCode::INVALID_CONFIG, $message);
    }

    public static function invalidInput(string $message, mixed $details = null): self
    {
        return new self(ErrorCode::INVALID_INPUT, $message, details: $details);
    }

    public static function invalidSignature(string $message = 'Invalid signature'): self
    {
        return new self(ErrorCode::INVALID_SIGNATURE, $message);
    }

    public static function networkError(?\Throwable $previous = null): self
    {
        return new self(ErrorCode::NETWORK_ERROR, 'Network error', previous: $previous);
    }

    public static function timeoutError(): self
    {
        return new self(ErrorCode::TIMEOUT, 'Request timed out');
    }

    public static function gatewayError(string $message = 'Gateway error'): self
    {
        return new self(ErrorCode::GATEWAY_ERROR, $message);
    }

    public static function transactionNotFound(string $id): self
    {
        return new self(ErrorCode::TRANSACTION_NOT_FOUND, "Transaction not found: {$id}");
    }

    public static function fromUnknown(\Throwable $err): self
    {
        if ($err instanceof self) {
            return $err;
        }
        $message = $err->getMessage();
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return self::timeoutError();
        }
        return self::networkError($err);
    }
}
