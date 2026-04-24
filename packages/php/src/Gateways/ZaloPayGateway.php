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

final class ZaloPayGateway implements PaymentGatewayInterface
{
    private const API_BASE_SANDBOX    = 'https://sb-openapi.zalopay.vn/v2';
    private const API_BASE_PRODUCTION = 'https://openapi.zalopay.vn/v2';

    /** @var array<int, ErrorCode> ZaloPay return_code → ErrorCode (per specs/error-codes.md) */
    private const RETURN_CODE_MAP = [
        2    => ErrorCode::GATEWAY_ERROR,
        -1   => ErrorCode::GATEWAY_ERROR,
        -2   => ErrorCode::INVALID_INPUT,
        -3   => ErrorCode::AUTHENTICATION_FAILED,
        -4   => ErrorCode::AUTHENTICATION_FAILED,
        -5   => ErrorCode::INVALID_INPUT,
        -6   => ErrorCode::PAYMENT_EXPIRED,
        -7   => ErrorCode::INVALID_AMOUNT,
        -9   => ErrorCode::INVALID_CONFIG,
        -10  => ErrorCode::INVALID_SIGNATURE,
        -11  => ErrorCode::DUPLICATE_ORDER,
        -12  => ErrorCode::REFUND_ALREADY_PROCESSED,
        -13  => ErrorCode::REFUND_AMOUNT_EXCEEDED,
        -14  => ErrorCode::REFUND_WINDOW_EXPIRED,
        -15  => ErrorCode::TRANSACTION_NOT_FOUND,
        -16  => ErrorCode::PAYMENT_FAILED,
        -49  => ErrorCode::TRANSACTION_NOT_FOUND,
        -58  => ErrorCode::CARD_DECLINED,
    ];

    /** @var callable|null */
    private $httpClient;

    public function __construct(
        private readonly int $appId,
        private readonly string $key1,
        private readonly string $key2,
        private readonly bool $sandbox = false,
        private readonly int $timeoutMs = 30_000,
        private readonly int $retries = 2,
        ?callable $httpClient = null,
    ) {
        if ($appId === 0)   throw PaymentSDKException::invalidConfig('ZaloPay: appId is required');
        if ($key1 === '')   throw PaymentSDKException::invalidConfig('ZaloPay: key1 is required');
        if ($key2 === '')   throw PaymentSDKException::invalidConfig('ZaloPay: key2 is required');

        $this->httpClient = $httpClient;
    }

    public function getName(): string
    {
        return 'zalopay';
    }

    public function getCapabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            supportRefund: true,
            supportPartialRefund: true,
            supportRecurring: false,
            supportWebhook: true,
            supportQRCode: true,
            supportInstallment: false,
            currencies: ['VND'],
            paymentMethods: ['wallet', 'card', 'banking', 'qr'],
        );
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? self::API_BASE_SANDBOX : self::API_BASE_PRODUCTION;
    }

    private function mapReturnCode(int $code): ErrorCode
    {
        return self::RETURN_CODE_MAP[$code] ?? ErrorCode::UNKNOWN_ERROR;
    }

    private function mapReturnCodeToStatus(int $code): PaymentStatus
    {
        return match (true) {
            $code === 1   => PaymentStatus::SUCCESS,
            $code === 2   => PaymentStatus::PROCESSING,
            $code === -6  => PaymentStatus::EXPIRED,
            default       => PaymentStatus::FAILED,
        };
    }

    private function buildAppTransId(string $orderId): string
    {
        $now = new \DateTimeImmutable();
        return $now->format('ymd') . '_' . $orderId;
    }

    private function buildMRefundId(string $orderId, int $timestamp): string
    {
        $now = new \DateTimeImmutable();
        return $now->format('ymd') . '_' . $this->appId . '_' . $orderId . '_' . $timestamp;
    }

    public function createPayment(array $input): PaymentResult
    {
        if (empty($input['orderId'])) throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');
        if (!empty($input['currency']) && $input['currency'] !== 'VND') {
            throw PaymentSDKException::invalidInput('ZaloPay only supports VND currency');
        }

        $now        = new \DateTimeImmutable();
        $appTime    = (int) (microtime(true) * 1000);
        $orderId    = (string) $input['orderId'];
        $appTransId = $this->buildAppTransId($orderId);
        $appUser    = (string) ($input['customerInfo']['name'] ?? 'user');
        $embedData  = json_encode(['redirecturl' => (string) $input['returnUrl']]);
        $item       = '[]';

        // Signing: `${app_id}|${app_trans_id}|${app_user}|${amount}|${app_time}|${embed_data}|${item}`
        $signData = implode('|', [
            $this->appId,
            $appTransId,
            $appUser,
            $input['amount'],
            $appTime,
            $embedData,
            $item,
        ]);
        $mac = Crypto::hmacSHA256($signData, $this->key1);

        $body = [
            'app_id'       => $this->appId,
            'app_trans_id' => $appTransId,
            'app_user'     => $appUser,
            'amount'       => $input['amount'],
            'app_time'     => $appTime,
            'embed_data'   => $embedData,
            'item'         => $item,
            'description'  => (string) $input['description'],
            'callback_url' => (string) ($input['ipnUrl'] ?? ''),
            'mac'          => $mac,
        ];

        $raw        = Http::post($this->baseUrl() . '/create', $body, $this->timeoutMs, $this->retries, httpClient: $this->httpClient);
        $returnCode = (int) ($raw['return_code'] ?? -1);
        $isSuccess  = $returnCode === 1;

        return new PaymentResult(
            success: $isSuccess,
            orderId: $orderId,
            amount: (int) $input['amount'],
            currency: 'VND',
            status: $isSuccess ? PaymentStatus::PENDING : $this->mapReturnCodeToStatus($returnCode),
            gateway: $this->getName(),
            rawResponse: $raw,
            createdAt: $now,
            paymentUrl: isset($raw['order_url']) ? (string) $raw['order_url'] : null,
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapReturnCode($returnCode),
                message: (string) ($raw['return_message'] ?? ''),
                gatewayCode: (string) $returnCode,
                gatewayMessage: ($raw['return_message'] ?? null) !== null ? (string) $raw['return_message'] : null,
            ),
        );
    }

    public function verifyWebhook(mixed $payload, array $headers): WebhookEvent
    {
        if (!is_array($payload)) {
            throw new PaymentSDKException(ErrorCode::WEBHOOK_PROCESSING_FAILED, 'ZaloPay webhook payload must be an object');
        }

        if (!isset($payload['data']) || !is_string($payload['data']) ||
            !isset($payload['mac']) || !is_string($payload['mac'])) {
            throw PaymentSDKException::invalidSignature('ZaloPay webhook missing data or mac field');
        }

        $dataStr     = $payload['data'];
        $receivedMac = strtolower($payload['mac']);
        $expectedMac = Crypto::hmacSHA256($dataStr, $this->key2);

        if (!Crypto::timingSafeEqual($expectedMac, $receivedMac)) {
            throw PaymentSDKException::invalidSignature();
        }

        $data = json_decode($dataStr, true);
        if (!is_array($data)) {
            throw new PaymentSDKException(ErrorCode::WEBHOOK_PROCESSING_FAILED, 'ZaloPay webhook data field is not valid JSON');
        }

        return new WebhookEvent(
            gateway: $this->getName(),
            eventType: WebhookEventType::PAYMENT_SUCCESS,
            orderId: (string) ($data['app_trans_id'] ?? ''),
            transactionId: (string) ($data['zp_trans_id'] ?? ''),
            amount: (int) ($data['amount'] ?? 0),
            currency: 'VND',
            status: PaymentStatus::SUCCESS,
            rawData: $payload,
            receivedAt: new \DateTimeImmutable(),
        );
    }

    public function getTransaction(string $transactionId, ?string $orderId = null): PaymentResult
    {
        $now = new \DateTimeImmutable();

        // Signing: `${app_id}|${app_trans_id}|${key1}`
        $signData = implode('|', [$this->appId, $transactionId, $this->key1]);
        $mac      = Crypto::hmacSHA256($signData, $this->key1);

        $body = [
            'app_id'       => $this->appId,
            'app_trans_id' => $transactionId,
            'mac'          => $mac,
        ];

        $raw        = Http::post($this->baseUrl() . '/query', $body, $this->timeoutMs, $this->retries, httpClient: $this->httpClient);
        $returnCode = (int) ($raw['return_code'] ?? -1);

        if ($returnCode === -49 || $returnCode === -15) {
            throw PaymentSDKException::transactionNotFound($transactionId);
        }

        $isSuccess       = $returnCode === 1;
        $resolvedOrderId = $orderId ?? $transactionId;

        return new PaymentResult(
            success: $isSuccess,
            orderId: $resolvedOrderId,
            amount: (int) ($raw['amount'] ?? 0),
            currency: 'VND',
            status: $this->mapReturnCodeToStatus($returnCode),
            gateway: $this->getName(),
            rawResponse: $raw,
            createdAt: $now,
            transactionId: isset($raw['zp_trans_id']) ? (string) $raw['zp_trans_id'] : null,
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapReturnCode($returnCode),
                message: (string) ($raw['return_message'] ?? ''),
                gatewayCode: (string) $returnCode,
                gatewayMessage: ($raw['return_message'] ?? null) !== null ? (string) $raw['return_message'] : null,
            ),
        );
    }

    public function refund(array $input): RefundResult
    {
        if (empty($input['transactionId'])) throw PaymentSDKException::invalidInput('transactionId is required');
        if (empty($input['orderId']))       throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');

        $timestamp   = (int) (microtime(true) * 1000);
        $description = (string) ($input['reason'] ?? "Refund for order {$input['orderId']}");
        $mRefundId   = $this->buildMRefundId((string) $input['orderId'], $timestamp);

        // Signing: `${app_id}|${zp_trans_id}|${amount}|${description}|${timestamp}`
        $signData = implode('|', [
            $this->appId,
            $input['transactionId'],
            $input['amount'],
            $description,
            $timestamp,
        ]);
        $mac = Crypto::hmacSHA256($signData, $this->key1);

        $body = [
            'app_id'      => $this->appId,
            'zp_trans_id' => $input['transactionId'],
            'm_refund_id' => $mRefundId,
            'amount'      => $input['amount'],
            'description' => $description,
            'timestamp'   => $timestamp,
            'mac'         => $mac,
        ];

        $raw        = Http::post($this->baseUrl() . '/refund', $body, $this->timeoutMs, $this->retries, httpClient: $this->httpClient);
        $returnCode = (int) ($raw['return_code'] ?? -1);
        $isSuccess  = $returnCode === 1;

        return new RefundResult(
            success: $isSuccess,
            orderId: (string) $input['orderId'],
            amount: (int) $input['amount'],
            status: $isSuccess ? RefundStatus::SUCCESS : RefundStatus::FAILED,
            rawResponse: $raw,
            refundId: isset($raw['refund_id']) ? (string) $raw['refund_id'] : null,
            transactionId: (string) $input['transactionId'],
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapReturnCode($returnCode),
                message: (string) ($raw['return_message'] ?? ''),
                gatewayCode: (string) $returnCode,
                gatewayMessage: ($raw['return_message'] ?? null) !== null ? (string) $raw['return_message'] : null,
            ),
        );
    }
}
