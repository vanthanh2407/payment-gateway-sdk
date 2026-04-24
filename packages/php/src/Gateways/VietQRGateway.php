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

final class VietQRGateway implements PaymentGatewayInterface
{
    private const API_BASE_SANDBOX    = 'https://dev.vietqr.org/vqr/api';
    private const API_BASE_PRODUCTION = 'https://api.vietqr.org/vqr/api';

    /** @var array<string, ErrorCode> VietQR result code → ErrorCode */
    private const RESULT_CODE_MAP = [
        'E01'  => ErrorCode::AUTHENTICATION_FAILED,
        'E02'  => ErrorCode::AUTHENTICATION_FAILED,
        'E03'  => ErrorCode::AUTHENTICATION_FAILED,
        'E04'  => ErrorCode::AUTHENTICATION_FAILED,
        'E05'  => ErrorCode::AUTHENTICATION_FAILED,
        'E06'  => ErrorCode::AUTHENTICATION_FAILED,
        'E07'  => ErrorCode::AUTHENTICATION_FAILED,
        'E08'  => ErrorCode::AUTHENTICATION_FAILED,
        'E09'  => ErrorCode::AUTHENTICATION_FAILED,
        'E24'  => ErrorCode::INVALID_INPUT,
        'E39'  => ErrorCode::INVALID_SIGNATURE,
        'E42'  => ErrorCode::REFUND_FAILED,
        'E43'  => ErrorCode::REFUND_FAILED,
        'E44'  => ErrorCode::REFUND_FAILED,
        'E45'  => ErrorCode::REFUND_FAILED,
        'E46'  => ErrorCode::REFUND_FAILED,
        'E74'  => ErrorCode::AUTHENTICATION_FAILED,
        'E75'  => ErrorCode::GATEWAY_ERROR,
        'E76'  => ErrorCode::INVALID_CONFIG,
        'E157' => ErrorCode::REFUND_ALREADY_PROCESSED,
    ];

    /** @var callable|null */
    private $httpClient;

    private ?string $cachedToken   = null;
    private int     $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $clientId,
        private readonly string $apiKey,
        private readonly string $bankCode,
        private readonly string $bankAccount,
        private readonly string $accountName,
        private readonly bool   $sandbox    = false,
        private readonly int    $timeoutMs  = 30_000,
        private readonly int    $retries    = 2,
        ?callable $httpClient = null,
    ) {
        if ($clientId === '')    throw PaymentSDKException::invalidConfig('VietQR: clientId is required');
        if ($apiKey === '')      throw PaymentSDKException::invalidConfig('VietQR: apiKey is required');
        if ($bankCode === '')    throw PaymentSDKException::invalidConfig('VietQR: bankCode is required');
        if ($bankAccount === '') throw PaymentSDKException::invalidConfig('VietQR: bankAccount is required');
        if ($accountName === '') throw PaymentSDKException::invalidConfig('VietQR: accountName is required');

        $this->httpClient = $httpClient;
    }

    public function getName(): string
    {
        return 'vietqr';
    }

    public function getCapabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            supportRefund: true,
            supportPartialRefund: false,
            supportRecurring: false,
            supportWebhook: true,
            supportQRCode: true,
            supportInstallment: false,
            currencies: ['VND'],
            paymentMethods: ['banking', 'qr'],
        );
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? self::API_BASE_SANDBOX : self::API_BASE_PRODUCTION;
    }

    private function mapResultCode(string $code): ErrorCode
    {
        return self::RESULT_CODE_MAP[$code] ?? ErrorCode::UNKNOWN_ERROR;
    }

    private function mapCodeToStatus(string $code): PaymentStatus
    {
        return match (true) {
            $code === '00'   => PaymentStatus::SUCCESS,
            $code === 'E157' => PaymentStatus::REFUNDED,
            default          => PaymentStatus::FAILED,
        };
    }

    /** Checksum for createPayment and getTransaction: MD5(clientId + orderId + apiKey) */
    private function buildCheckSum(string $orderId): string
    {
        return Crypto::md5($this->clientId . $orderId . $this->apiKey);
    }

    /** Bearer tokens expire in 300 s; cache with a 30 s safety buffer */
    private function getAccessToken(): string
    {
        $now = (int) (microtime(true) * 1000);
        if ($this->cachedToken !== null && $now < $this->tokenExpiresAt) {
            return $this->cachedToken;
        }

        $credentials = base64_encode($this->clientId . ':' . $this->apiKey);
        $raw = Http::post(
            $this->baseUrl() . '/peripheral/ecommerce/token_generate',
            [],
            $this->timeoutMs,
            $this->retries,
            extraHeaders: ['Authorization' => 'Basic ' . $credentials],
            httpClient: $this->httpClient,
        );

        if (empty($raw['access_token'])) {
            throw new PaymentSDKException(ErrorCode::AUTHENTICATION_FAILED, 'VietQR: failed to obtain access token');
        }

        $this->cachedToken   = (string) $raw['access_token'];
        $expiresIn           = (int) ($raw['expires_in'] ?? 300);
        $this->tokenExpiresAt = $now + ($expiresIn - 30) * 1000;
        return $this->cachedToken;
    }

    public function createPayment(array $input): PaymentResult
    {
        if (empty($input['orderId'])) throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');
        if (!empty($input['currency']) && $input['currency'] !== 'VND') {
            throw PaymentSDKException::invalidInput('VietQR only supports VND currency');
        }

        $now   = new \DateTimeImmutable();
        $token = $this->getAccessToken();

        $body = [
            'bankCode'     => $this->bankCode,
            'bankAccount'  => $this->bankAccount,
            'userBankName' => $this->accountName,
            'amount'       => $input['amount'],
            'orderId'      => (string) $input['orderId'],
            'content'      => (string) ($input['description'] ?? ''),
            'transType'    => 'C',
            'urlLink'      => (string) ($input['returnUrl'] ?? ''),
            'checkSum'     => $this->buildCheckSum((string) $input['orderId']),
        ];

        $raw       = Http::post(
            $this->baseUrl() . '/qr/generate-customer',
            $body,
            $this->timeoutMs,
            $this->retries,
            extraHeaders: ['Authorization' => 'Bearer ' . $token],
            httpClient: $this->httpClient,
        );
        $code      = (string) ($raw['code'] ?? '');
        $isSuccess = $code === '00';

        return new PaymentResult(
            success: $isSuccess,
            orderId: (string) $input['orderId'],
            amount: (int) $input['amount'],
            currency: 'VND',
            status: $isSuccess ? PaymentStatus::PENDING : $this->mapCodeToStatus($code),
            gateway: $this->getName(),
            rawResponse: $raw,
            createdAt: $now,
            paymentUrl: isset($raw['data']['qrDataURL']) ? (string) $raw['data']['qrDataURL'] : null,
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapResultCode($code),
                message: (string) ($raw['message'] ?? ''),
                gatewayCode: $code,
                gatewayMessage: isset($raw['message']) ? (string) $raw['message'] : null,
            ),
        );
    }

    public function verifyWebhook(mixed $payload, array $headers): WebhookEvent
    {
        if (!is_array($payload)) {
            throw new PaymentSDKException(ErrorCode::WEBHOOK_PROCESSING_FAILED, 'VietQR webhook payload must be an object');
        }

        if (!isset($payload['data']) || !is_array($payload['data']) ||
            !isset($payload['data']['checkSum']) || !is_string($payload['data']['checkSum'])) {
            throw new PaymentSDKException(ErrorCode::WEBHOOK_PROCESSING_FAILED, 'VietQR webhook missing data or checkSum');
        }

        $data             = $payload['data'];
        $orderId          = (string) ($data['orderId'] ?? '');
        $transactionId    = (string) ($data['transactionId'] ?? '');
        $amount           = (int) ($data['amount'] ?? 0);
        $bankCode         = (string) ($data['bankCode'] ?? '');
        $receivedCheckSum = $payload['data']['checkSum'];

        // Webhook checksum: MD5(orderId + bankCode + amount + transactionId + apiKey)
        $expectedCheckSum = Crypto::md5($orderId . $bankCode . (string) $amount . $transactionId . $this->apiKey);

        if (!Crypto::timingSafeEqual($expectedCheckSum, $receivedCheckSum)) {
            throw PaymentSDKException::invalidSignature();
        }

        $code      = (string) ($payload['code'] ?? '');
        $isSuccess = $code === '00' || ($payload['success'] ?? false) === true;
        $status    = $isSuccess ? PaymentStatus::SUCCESS : PaymentStatus::FAILED;
        $eventType = $isSuccess ? WebhookEventType::PAYMENT_SUCCESS : WebhookEventType::PAYMENT_FAILED;

        return new WebhookEvent(
            gateway: $this->getName(),
            eventType: $eventType,
            orderId: $orderId,
            transactionId: $transactionId,
            amount: $amount,
            currency: 'VND',
            status: $status,
            rawData: $payload,
            receivedAt: new \DateTimeImmutable(),
        );
    }

    public function getTransaction(string $transactionId, ?string $orderId = null): PaymentResult
    {
        $now             = new \DateTimeImmutable();
        $resolvedOrderId = $orderId ?? $transactionId;
        $token           = $this->getAccessToken();

        $body = [
            'orderId'     => $resolvedOrderId,
            'bankCode'    => $this->bankCode,
            'bankAccount' => $this->bankAccount,
            'checkSum'    => $this->buildCheckSum($resolvedOrderId),
        ];

        $raw       = Http::post(
            $this->baseUrl() . '/transaction/query',
            $body,
            $this->timeoutMs,
            $this->retries,
            extraHeaders: ['Authorization' => 'Bearer ' . $token],
            httpClient: $this->httpClient,
        );
        $code      = (string) ($raw['code'] ?? '');
        $isSuccess = $code === '00';

        return new PaymentResult(
            success: $isSuccess,
            orderId: isset($raw['data']['orderId']) ? (string) $raw['data']['orderId'] : $resolvedOrderId,
            amount: (int) ($raw['data']['amount'] ?? 0),
            currency: 'VND',
            status: $this->mapCodeToStatus($code),
            gateway: $this->getName(),
            rawResponse: $raw,
            createdAt: $now,
            transactionId: isset($raw['data']['transactionId']) ? (string) $raw['data']['transactionId'] : null,
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapResultCode($code),
                message: (string) ($raw['message'] ?? ''),
                gatewayCode: $code,
                gatewayMessage: isset($raw['message']) ? (string) $raw['message'] : null,
            ),
        );
    }

    public function refund(array $input): RefundResult
    {
        if (empty($input['transactionId'])) throw PaymentSDKException::invalidInput('transactionId is required');
        if (empty($input['orderId']))       throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');

        $token    = $this->getAccessToken();
        // Refund checksum: MD5(clientId + transactionId + amount + apiKey)
        $checkSum = Crypto::md5(
            $this->clientId . (string) $input['transactionId'] . (string) $input['amount'] . $this->apiKey
        );

        $body = [
            'transactionId' => (string) $input['transactionId'],
            'orderId'       => (string) $input['orderId'],
            'bankCode'      => $this->bankCode,
            'bankAccount'   => $this->bankAccount,
            'amount'        => (int) $input['amount'],
            'checkSum'      => $checkSum,
        ];
        if (!empty($input['reason'])) {
            $body['remark'] = (string) $input['reason'];
        }

        $raw       = Http::post(
            $this->baseUrl() . '/transaction/refund',
            $body,
            $this->timeoutMs,
            $this->retries,
            extraHeaders: ['Authorization' => 'Bearer ' . $token],
            httpClient: $this->httpClient,
        );
        $code      = (string) ($raw['code'] ?? '');
        $isSuccess = $code === '00';

        return new RefundResult(
            success: $isSuccess,
            orderId: (string) $input['orderId'],
            amount: (int) $input['amount'],
            status: $isSuccess ? RefundStatus::SUCCESS : RefundStatus::FAILED,
            rawResponse: $raw,
            refundId: isset($raw['data']['refundId']) ? (string) $raw['data']['refundId'] : null,
            transactionId: (string) $input['transactionId'],
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapResultCode($code),
                message: (string) ($raw['message'] ?? ''),
                gatewayCode: $code,
                gatewayMessage: isset($raw['message']) ? (string) $raw['message'] : null,
            ),
        );
    }
}
