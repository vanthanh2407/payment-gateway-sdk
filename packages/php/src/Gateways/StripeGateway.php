<?php

declare(strict_types=1);

namespace PaymentSdk\Gateways;

use PaymentSdk\Contracts\PaymentGatewayInterface;
use PaymentSdk\Data\GatewayCapabilities;
use PaymentSdk\Data\PaymentError;
use PaymentSdk\Data\PaymentResult;
use PaymentSdk\Data\RefundResult;
use PaymentSdk\Data\WebhookEvent;
use PaymentSdk\Enums\ErrorCode;
use PaymentSdk\Enums\PaymentStatus;
use PaymentSdk\Enums\RefundStatus;
use PaymentSdk\Enums\WebhookEventType;
use PaymentSdk\Exceptions\PaymentSDKException;
use PaymentSdk\Utils\Crypto;
use PaymentSdk\Utils\Http;

final class StripeGateway implements PaymentGatewayInterface
{
    private const BASE_URL           = 'https://api.stripe.com/v1';
    private const STRIPE_API_VERSION = '2023-10-16';

    /** Zero-decimal currencies: amounts already in smallest unit, no × 100 conversion */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
        'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** @var array<string, ErrorCode> Stripe decline_code / error.code → ErrorCode */
    private const DECLINE_CODE_MAP = [
        'insufficient_funds'                   => ErrorCode::INSUFFICIENT_FUNDS,
        'card_declined'                        => ErrorCode::CARD_DECLINED,
        'do_not_honor'                         => ErrorCode::CARD_DECLINED,
        'generic_decline'                      => ErrorCode::CARD_DECLINED,
        'lost_card'                            => ErrorCode::CARD_LOCKED,
        'stolen_card'                          => ErrorCode::CARD_LOCKED,
        'pickup_card'                          => ErrorCode::CARD_LOCKED,
        'restricted_card'                      => ErrorCode::CARD_LOCKED,
        'pin_try_exceeded'                     => ErrorCode::CARD_LOCKED,
        'card_velocity_exceeded'               => ErrorCode::PAYMENT_FAILED,
        'fraudulent'                           => ErrorCode::PAYMENT_FAILED,
        'not_permitted'                        => ErrorCode::PAYMENT_FAILED,
        'testmode_decline'                     => ErrorCode::PAYMENT_FAILED,
        'withdrawal_count_limit_exceeded'      => ErrorCode::PAYMENT_FAILED,
        'invalid_account'                      => ErrorCode::PAYMENT_FAILED,
        'new_account_information_available'    => ErrorCode::CARD_DECLINED,
        'reenter_transaction'                  => ErrorCode::PAYMENT_FAILED,
        'stop_payment_order'                   => ErrorCode::CARD_DECLINED,
        'call_issuer'                          => ErrorCode::CARD_DECLINED,
        'card_not_supported'                   => ErrorCode::CARD_DECLINED,
        'expired_card'                         => ErrorCode::CARD_DECLINED,
        'incorrect_cvc'                        => ErrorCode::AUTHENTICATION_FAILED,
        'invalid_cvc'                          => ErrorCode::AUTHENTICATION_FAILED,
        'authentication_required'              => ErrorCode::AUTHENTICATION_FAILED,
        'offline_pin_required'                 => ErrorCode::AUTHENTICATION_FAILED,
        'online_or_offline_pin_required'       => ErrorCode::AUTHENTICATION_FAILED,
        'incorrect_number'                     => ErrorCode::INVALID_INPUT,
        'invalid_number'                       => ErrorCode::INVALID_INPUT,
        'invalid_expiry_month'                 => ErrorCode::INVALID_INPUT,
        'invalid_expiry_year'                  => ErrorCode::INVALID_INPUT,
        'incorrect_zip'                        => ErrorCode::INVALID_INPUT,
        'country_code_invalid'                 => ErrorCode::INVALID_INPUT,
        'currency_not_supported'               => ErrorCode::INVALID_INPUT,
        'invalid_amount'                       => ErrorCode::INVALID_AMOUNT,
        'duplicate_transaction'                => ErrorCode::DUPLICATE_ORDER,
        'issuer_not_available'                 => ErrorCode::BANK_MAINTENANCE,
        'processing_error'                     => ErrorCode::GATEWAY_ERROR,
    ];

    /** @var array<string, ErrorCode> Stripe error.type → ErrorCode (fallback) */
    private const ERROR_TYPE_MAP = [
        'card_error'            => ErrorCode::CARD_DECLINED,
        'authentication_error'  => ErrorCode::AUTHENTICATION_FAILED,
        'invalid_request_error' => ErrorCode::INVALID_INPUT,
        'validation_error'      => ErrorCode::INVALID_INPUT,
        'idempotency_error'     => ErrorCode::DUPLICATE_ORDER,
        'api_error'             => ErrorCode::GATEWAY_ERROR,
        'rate_limit_error'      => ErrorCode::GATEWAY_ERROR,
    ];

    /** @var array<string, WebhookEventType> Stripe event.type → WebhookEventType */
    private const EVENT_TYPE_MAP = [
        'payment_intent.succeeded'    => WebhookEventType::PAYMENT_SUCCESS,
        'payment_intent.payment_failed' => WebhookEventType::PAYMENT_FAILED,
        'payment_intent.canceled'     => WebhookEventType::PAYMENT_CANCELLED,
        'checkout.session.completed'  => WebhookEventType::PAYMENT_SUCCESS,
        'checkout.session.expired'    => WebhookEventType::PAYMENT_FAILED,
        'charge.refunded'             => WebhookEventType::REFUND_SUCCESS,
        'charge.dispute.created'      => WebhookEventType::DISPUTE_CREATED,
    ];

    /** @var callable|null */
    private $httpClient;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret,
        private readonly bool   $sandbox   = false,
        private readonly int    $timeoutMs = 30_000,
        private readonly int    $retries   = 2,
        ?callable $httpClient = null,
    ) {
        if ($secretKey === '')     throw PaymentSDKException::invalidConfig('Stripe: secretKey is required');
        if ($webhookSecret === '') throw PaymentSDKException::invalidConfig('Stripe: webhookSecret is required');

        $this->httpClient = $httpClient;
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function getCapabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            supportRefund: true,
            supportPartialRefund: true,
            supportRecurring: true,
            supportWebhook: true,
            supportQRCode: false,
            supportInstallment: false,
            currencies: ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'SGD', 'VND', 'THB', 'MYR'],
            paymentMethods: ['card', 'bank_transfer'],
        );
    }

    /** @return array<string, string> */
    private function defaultHeaders(): array
    {
        return [
            'Authorization'  => 'Bearer ' . $this->secretKey,
            'Stripe-Version' => self::STRIPE_API_VERSION,
        ];
    }

    private function isZeroDecimal(string $currency): bool
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true);
    }

    private function toStripeAmount(int $amount, string $currency): int
    {
        return $this->isZeroDecimal($currency) ? $amount : $amount * 100;
    }

    private function fromStripeAmount(int $amount, string $currency): int
    {
        return $this->isZeroDecimal($currency) ? $amount : (int) round($amount / 100);
    }

    /** @param array<string, mixed> $err */
    private function mapStripeError(array $err): ErrorCode
    {
        $declineCode = (string) ($err['decline_code'] ?? '');
        if ($declineCode !== '' && isset(self::DECLINE_CODE_MAP[$declineCode])) {
            return self::DECLINE_CODE_MAP[$declineCode];
        }

        $code = (string) ($err['code'] ?? '');
        if ($code !== '' && isset(self::DECLINE_CODE_MAP[$code])) {
            return self::DECLINE_CODE_MAP[$code];
        }

        $type = (string) ($err['type'] ?? '');
        return self::ERROR_TYPE_MAP[$type] ?? ErrorCode::PAYMENT_FAILED;
    }

    private function mapIntentStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'succeeded'                                          => PaymentStatus::SUCCESS,
            'processing'                                        => PaymentStatus::PROCESSING,
            'requires_action', 'requires_confirmation',
            'requires_capture', 'requires_payment_method'      => PaymentStatus::PENDING,
            'canceled'                                          => PaymentStatus::CANCELLED,
            default                                             => PaymentStatus::FAILED,
        };
    }

    private function mapEventStatus(string $stripeEventType): PaymentStatus
    {
        return match ($stripeEventType) {
            'payment_intent.succeeded',
            'checkout.session.completed'    => PaymentStatus::SUCCESS,
            'payment_intent.canceled',
            'checkout.session.expired'      => PaymentStatus::CANCELLED,
            'charge.refunded'               => PaymentStatus::REFUNDED,
            default                         => PaymentStatus::FAILED,
        };
    }

    /**
     * POST form-encoded data to Stripe, returning either data or a Stripe error envelope.
     *
     * @param array<string, string> $params
     * @return array{data: array<string, mixed>, error: null}|array{data: null, error: array<string, mixed>}
     */
    private function stripePost(string $path, array $params): array
    {
        $raw = Http::formPost(
            self::BASE_URL . $path,
            $params,
            $this->timeoutMs,
            $this->retries,
            extraHeaders: $this->defaultHeaders(),
            httpClient: $this->httpClient,
        );

        if (isset($raw['error']) && is_array($raw['error'])) {
            return ['data' => null, 'error' => $raw['error']];
        }

        return ['data' => $raw, 'error' => null];
    }

    /**
     * GET a Stripe resource, returning either data or a Stripe error envelope.
     *
     * @param array<string, string> $params
     * @return array{data: array<string, mixed>, error: null}|array{data: null, error: array<string, mixed>}
     */
    private function stripeGet(string $path, array $params = []): array
    {
        $raw = Http::get(
            self::BASE_URL . $path,
            $params,
            $this->timeoutMs,
            $this->retries,
            extraHeaders: $this->defaultHeaders(),
            httpClient: $this->httpClient,
        );

        if (isset($raw['error']) && is_array($raw['error'])) {
            return ['data' => null, 'error' => $raw['error']];
        }

        return ['data' => $raw, 'error' => null];
    }

    public function createPayment(array $input): PaymentResult
    {
        if (empty($input['orderId']))  throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');
        if (empty($input['returnUrl'])) throw PaymentSDKException::invalidInput('returnUrl is required');

        $now      = new \DateTimeImmutable();
        $currency = strtolower((string) ($input['currency'] ?? 'USD'));
        $stripeAmount = $this->toStripeAmount((int) $input['amount'], $currency);

        $description = !empty($input['description']) ? (string) $input['description'] : (string) $input['orderId'];
        $cancelUrl   = !empty($input['cancelUrl']) ? (string) $input['cancelUrl'] : (string) $input['returnUrl'];

        $params = [
            'mode'                                             => 'payment',
            'success_url'                                      => (string) $input['returnUrl'],
            'cancel_url'                                       => $cancelUrl,
            'line_items[0][price_data][currency]'              => $currency,
            'line_items[0][price_data][unit_amount]'           => (string) $stripeAmount,
            'line_items[0][price_data][product_data][name]'    => $description,
            'line_items[0][quantity]'                          => '1',
            'metadata[orderId]'                                => (string) $input['orderId'],
        ];

        if (!empty($input['customerInfo']['email'])) {
            $params['customer_email'] = (string) $input['customerInfo']['email'];
        }

        if (!empty($input['expireAt']) && $input['expireAt'] instanceof \DateTimeInterface) {
            $params['expires_at'] = (string) $input['expireAt']->getTimestamp();
        }

        $result = $this->stripePost('/checkout/sessions', $params);

        if ($result['error'] !== null) {
            $err        = $result['error'];
            $gatewayCode = (string) ($err['decline_code'] ?? $err['code'] ?? '');
            return new PaymentResult(
                success: false,
                orderId: (string) $input['orderId'],
                amount: (int) $input['amount'],
                currency: strtoupper($currency),
                status: PaymentStatus::FAILED,
                gateway: $this->getName(),
                rawResponse: ['error' => $err],
                createdAt: $now,
                error: new PaymentError(
                    code: $this->mapStripeError($err),
                    message: (string) ($err['message'] ?? ''),
                    gatewayCode: $gatewayCode !== '' ? $gatewayCode : null,
                    gatewayMessage: isset($err['message']) ? (string) $err['message'] : null,
                ),
            );
        }

        $session  = $result['data'];
        $isOpen   = ($session['status'] ?? '') === 'open';
        $sessionCurrency = strtoupper((string) ($session['currency'] ?? $currency));

        return new PaymentResult(
            success: $isOpen,
            orderId: (string) $input['orderId'],
            amount: (int) $input['amount'],
            currency: $sessionCurrency,
            status: $isOpen ? PaymentStatus::PENDING : PaymentStatus::FAILED,
            gateway: $this->getName(),
            rawResponse: $session,
            createdAt: $now,
            paymentUrl: isset($session['url']) ? (string) $session['url'] : null,
            transactionId: isset($session['payment_intent']) ? (string) $session['payment_intent'] : null,
        );
    }

    public function verifyWebhook(mixed $payload, array $headers): WebhookEvent
    {
        // Stripe verifies against the raw request body — callers should pass the raw string.
        // An array is accepted (parsed JSON fallback) but may fail with non-canonical whitespace.
        if (is_string($payload)) {
            $rawBody = $payload;
        } elseif (is_array($payload)) {
            $rawBody = (string) json_encode($payload);
        } else {
            throw new PaymentSDKException(
                ErrorCode::WEBHOOK_PROCESSING_FAILED,
                'Stripe webhook payload must be a string or object',
            );
        }

        $sigHeader = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? '';
        if ($sigHeader === '') {
            throw new PaymentSDKException(
                ErrorCode::WEBHOOK_PROCESSING_FAILED,
                'Stripe webhook missing Stripe-Signature header',
            );
        }

        $timestamp    = '';
        $v1Signatures = [];

        foreach (explode(',', $sigHeader) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) continue;
            $key = substr($part, 0, $eq);
            $val = substr($part, $eq + 1);
            if ($key === 't')  $timestamp      = $val;
            if ($key === 'v1') $v1Signatures[] = $val;
        }

        if ($timestamp === '' || $v1Signatures === []) {
            throw new PaymentSDKException(
                ErrorCode::WEBHOOK_PROCESSING_FAILED,
                'Stripe-Signature header is malformed',
            );
        }

        $expectedSig = Crypto::hmacSHA256("{$timestamp}.{$rawBody}", $this->webhookSecret);

        $isValid = false;
        foreach ($v1Signatures as $sig) {
            if (Crypto::timingSafeEqual($expectedSig, $sig)) {
                $isValid = true;
                break;
            }
        }
        if (!$isValid) {
            throw PaymentSDKException::invalidSignature();
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new PaymentSDKException(ErrorCode::WEBHOOK_PROCESSING_FAILED, 'Stripe webhook payload is not valid JSON');
        }

        $stripeEventType = (string) ($event['type'] ?? '');
        $eventType       = self::EVENT_TYPE_MAP[$stripeEventType] ?? WebhookEventType::PAYMENT_FAILED;
        $status          = $this->mapEventStatus($stripeEventType);
        $obj             = (array) ($event['data']['object'] ?? []);

        $metadata      = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
        $orderId       = (string) ($metadata['orderId'] ?? $obj['client_reference_id'] ?? '');
        $transactionId = (string) ($obj['payment_intent'] ?? $obj['id'] ?? '');
        $rawCurrency   = strtoupper((string) ($obj['currency'] ?? $obj['amount_currency'] ?? ''));

        $rawAmount = 0;
        if (is_int($obj['amount'] ?? null)) {
            $rawAmount = $obj['amount'];
        } elseif (is_int($obj['amount_total'] ?? null)) {
            $rawAmount = $obj['amount_total'];
        }
        $amount = $this->fromStripeAmount($rawAmount, $rawCurrency);

        return new WebhookEvent(
            gateway: $this->getName(),
            eventType: $eventType,
            orderId: $orderId,
            transactionId: $transactionId,
            amount: $amount,
            currency: $rawCurrency,
            status: $status,
            rawData: $event,
            receivedAt: new \DateTimeImmutable(),
        );
    }

    public function getTransaction(string $transactionId, ?string $orderId = null): PaymentResult
    {
        if ($transactionId === '') throw PaymentSDKException::invalidInput('transactionId is required');

        $now  = new \DateTimeImmutable();
        // Route by ID prefix: cs_ = Checkout Session, pi_ = PaymentIntent
        $path = str_starts_with($transactionId, 'cs_')
            ? '/checkout/sessions/' . $transactionId
            : '/payment_intents/' . $transactionId;

        $result = $this->stripeGet($path);

        if ($result['error'] !== null) {
            $err       = $result['error'];
            $errorCode = ($err['code'] ?? '') === 'resource_missing'
                ? ErrorCode::TRANSACTION_NOT_FOUND
                : $this->mapStripeError($err);
            $gatewayCode = isset($err['code']) ? (string) $err['code'] : null;
            return new PaymentResult(
                success: false,
                orderId: $orderId ?? '',
                amount: 0,
                currency: 'UNKNOWN',
                status: PaymentStatus::FAILED,
                gateway: $this->getName(),
                rawResponse: ['error' => $err],
                createdAt: $now,
                error: new PaymentError(
                    code: $errorCode,
                    message: (string) ($err['message'] ?? ''),
                    gatewayCode: $gatewayCode,
                    gatewayMessage: isset($err['message']) ? (string) $err['message'] : null,
                ),
            );
        }

        $raw    = $result['data'];
        $object = (string) ($raw['object'] ?? '');

        if ($object === 'payment_intent') {
            $currency        = strtoupper((string) ($raw['currency'] ?? ''));
            $metadata        = is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [];
            $resolvedOrderId = (string) ($metadata['orderId'] ?? $orderId ?? '');
            $intentStatus    = $this->mapIntentStatus((string) ($raw['status'] ?? ''));

            return new PaymentResult(
                success: $intentStatus === PaymentStatus::SUCCESS,
                orderId: $resolvedOrderId,
                amount: $this->fromStripeAmount((int) ($raw['amount'] ?? 0), $currency),
                currency: $currency,
                status: $intentStatus,
                gateway: $this->getName(),
                rawResponse: $raw,
                createdAt: $now,
                transactionId: (string) ($raw['id'] ?? ''),
            );
        }

        // Checkout Session
        $currency        = strtoupper((string) ($raw['currency'] ?? ''));
        $isSuccess       = ($raw['payment_status'] ?? '') === 'paid';
        $metadata        = is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [];
        $resolvedOrderId = (string) ($metadata['orderId'] ?? $orderId ?? '');
        $amountTotal     = isset($raw['amount_total']) ? (int) $raw['amount_total'] : 0;

        return new PaymentResult(
            success: $isSuccess,
            orderId: $resolvedOrderId,
            amount: $this->fromStripeAmount($amountTotal, $currency),
            currency: $currency,
            status: $isSuccess ? PaymentStatus::SUCCESS : $this->mapIntentStatus((string) ($raw['status'] ?? '')),
            gateway: $this->getName(),
            rawResponse: $raw,
            createdAt: $now,
            transactionId: isset($raw['payment_intent']) ? (string) $raw['payment_intent'] : null,
        );
    }

    public function refund(array $input): RefundResult
    {
        if (empty($input['transactionId'])) throw PaymentSDKException::invalidInput('transactionId is required');
        if (empty($input['orderId']))       throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');

        $params = [
            'payment_intent' => (string) $input['transactionId'],
            'amount'         => (string) $input['amount'],
        ];

        if (!empty($input['reason'])) {
            $reason = (string) $input['reason'];
            $params['reason'] = match ($reason) {
                'fraudulent' => 'fraudulent',
                'duplicate'  => 'duplicate',
                default      => 'requested_by_customer',
            };
            $params['metadata[reason]'] = $reason;
        }

        $result = $this->stripePost('/refunds', $params);

        if ($result['error'] !== null) {
            $err       = $result['error'];
            $errorCode = match ((string) ($err['code'] ?? '')) {
                'charge_already_refunded'                       => ErrorCode::REFUND_ALREADY_PROCESSED,
                'charge_exceeds_source_amount', 'amount_too_large' => ErrorCode::REFUND_AMOUNT_EXCEEDED,
                'refund_not_supported'                          => ErrorCode::REFUND_NOT_SUPPORTED,
                'missing_charge'                                => ErrorCode::TRANSACTION_NOT_FOUND,
                default                                         => $this->mapStripeError($err),
            };
            $gatewayCode = isset($err['code']) ? (string) $err['code'] : null;
            return new RefundResult(
                success: false,
                orderId: (string) $input['orderId'],
                amount: (int) $input['amount'],
                status: RefundStatus::FAILED,
                rawResponse: ['error' => $err],
                transactionId: (string) $input['transactionId'],
                error: new PaymentError(
                    code: $errorCode,
                    message: (string) ($err['message'] ?? ''),
                    gatewayCode: $gatewayCode,
                    gatewayMessage: isset($err['message']) ? (string) $err['message'] : null,
                ),
            );
        }

        $refund = $result['data'];
        $refundStatus = match ((string) ($refund['status'] ?? '')) {
            'succeeded'                      => RefundStatus::SUCCESS,
            'pending', 'requires_action'     => RefundStatus::PENDING,
            'canceled'                       => RefundStatus::REJECTED,
            default                          => RefundStatus::FAILED,
        };

        $isFailed       = $refundStatus === RefundStatus::FAILED;
        $failureReason  = isset($refund['failure_reason']) ? (string) $refund['failure_reason'] : null;

        return new RefundResult(
            success: $refundStatus === RefundStatus::SUCCESS || $refundStatus === RefundStatus::PENDING,
            orderId: (string) $input['orderId'],
            amount: (int) $input['amount'],
            status: $refundStatus,
            rawResponse: $refund,
            refundId: isset($refund['id']) ? (string) $refund['id'] : null,
            transactionId: (string) $input['transactionId'],
            error: $isFailed ? new PaymentError(
                code: ErrorCode::REFUND_FAILED,
                message: $failureReason ?? 'Refund failed',
                gatewayCode: $failureReason,
                gatewayMessage: $failureReason ?? 'Refund failed',
            ) : null,
        );
    }
}
