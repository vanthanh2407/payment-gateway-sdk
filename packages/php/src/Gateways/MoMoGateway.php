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

final class MoMoGateway implements PaymentGatewayInterface
{
    private const API_URL_SANDBOX    = 'https://test-payment.momo.vn/v2/gateway/api';
    private const API_URL_PRODUCTION = 'https://payment.momo.vn/v2/gateway/api';

    /** @var array<int, ErrorCode> MoMo resultCode → ErrorCode (per specs/error-codes.md) */
    private const RESULT_CODE_MAP = [
        1001 => ErrorCode::INSUFFICIENT_FUNDS,
        1002 => ErrorCode::CARD_DECLINED,
        1003 => ErrorCode::PAYMENT_FAILED,
        1004 => ErrorCode::PAYMENT_FAILED,
        1005 => ErrorCode::PAYMENT_EXPIRED,
        1006 => ErrorCode::PAYMENT_CANCELLED,
        1007 => ErrorCode::PAYMENT_FAILED,
        1026 => ErrorCode::PAYMENT_FAILED,
        1080 => ErrorCode::REFUND_FAILED,
        1081 => ErrorCode::REFUND_FAILED,
        2001 => ErrorCode::AUTHENTICATION_FAILED,
        2007 => ErrorCode::AUTHENTICATION_FAILED,
        4001 => ErrorCode::AUTHENTICATION_FAILED,
        4100 => ErrorCode::AUTHENTICATION_FAILED,
        7002 => ErrorCode::PAYMENT_FAILED,
        9001 => ErrorCode::DUPLICATE_ORDER,
    ];

    /** @var callable|null */
    private $httpClient;

    public function __construct(
        private readonly string $partnerCode,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly bool $sandbox = false,
        private readonly int $timeoutMs = 30_000,
        private readonly int $retries = 2,
        ?callable $httpClient = null,
    ) {
        if ($partnerCode === '') throw PaymentSDKException::invalidConfig('MoMo: partnerCode is required');
        if ($accessKey === '')   throw PaymentSDKException::invalidConfig('MoMo: accessKey is required');
        if ($secretKey === '')   throw PaymentSDKException::invalidConfig('MoMo: secretKey is required');

        $this->httpClient = $httpClient;
    }

    public function getName(): string
    {
        return 'momo';
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
        return $this->sandbox ? self::API_URL_SANDBOX : self::API_URL_PRODUCTION;
    }

    private function buildRequestId(string $orderId): string
    {
        return $orderId . '_' . (string) (int) (microtime(true) * 1000);
    }

    private function mapResultCode(int $code): ErrorCode
    {
        return self::RESULT_CODE_MAP[$code] ?? ErrorCode::UNKNOWN_ERROR;
    }

    private function mapResultCodeToStatus(int $code): PaymentStatus
    {
        return match (true) {
            $code === 0                                    => PaymentStatus::SUCCESS,
            in_array($code, [1000, 7000, 8000], true)     => PaymentStatus::PROCESSING,
            $code === 1006                                 => PaymentStatus::CANCELLED,
            $code === 1005                                 => PaymentStatus::EXPIRED,
            $code === 9000                                 => PaymentStatus::REFUNDED,
            default                                        => PaymentStatus::FAILED,
        };
    }

    public function createPayment(array $input): PaymentResult
    {
        if (empty($input['orderId'])) throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');
        if (!empty($input['currency']) && $input['currency'] !== 'VND') {
            throw PaymentSDKException::invalidInput('MoMo only supports VND currency');
        }

        $now         = new \DateTimeImmutable();
        $orderId     = (string) $input['orderId'];
        $requestId   = $this->buildRequestId($orderId);
        $extraData   = isset($input['metadata'])
            ? base64_encode((string) json_encode($input['metadata']))
            : '';
        $requestType = 'payWithMethod';
        $ipnUrl      = (string) ($input['ipnUrl'] ?? '');
        $returnUrl   = (string) $input['returnUrl'];
        $description = (string) $input['description'];

        // MoMo rawHash keys must be in exactly this order
        $rawHashKeys = ['accessKey', 'amount', 'extraData', 'ipnUrl', 'orderId', 'orderInfo', 'partnerCode', 'redirectUrl', 'requestId', 'requestType'];
        $signParams  = [
            'accessKey'   => $this->accessKey,
            'amount'      => (string) $input['amount'],
            'extraData'   => $extraData,
            'ipnUrl'      => $ipnUrl,
            'orderId'     => $orderId,
            'orderInfo'   => $description,
            'partnerCode' => $this->partnerCode,
            'redirectUrl' => $returnUrl,
            'requestId'   => $requestId,
            'requestType' => $requestType,
        ];

        $rawHash   = Crypto::buildRawString($signParams, $rawHashKeys);
        $signature = Crypto::hmacSHA256($rawHash, $this->secretKey);

        $body = [
            'partnerCode' => $this->partnerCode,
            'accessKey'   => $this->accessKey,
            'requestId'   => $requestId,
            'amount'      => $input['amount'],
            'orderId'     => $orderId,
            'orderInfo'   => $description,
            'redirectUrl' => $returnUrl,
            'ipnUrl'      => $ipnUrl,
            'extraData'   => $extraData,
            'requestType' => $requestType,
            'signature'   => $signature,
            'lang'        => ($input['locale'] ?? 'vi') === 'en' ? 'en' : 'vi',
        ];

        $raw        = Http::post($this->baseUrl() . '/create', $body, $this->timeoutMs, $this->retries, httpClient: $this->httpClient);
        $resultCode = (int) ($raw['resultCode'] ?? -1);
        $isSuccess  = $resultCode === 0;

        return new PaymentResult(
            success: $isSuccess,
            orderId: $orderId,
            amount: $input['amount'],
            currency: 'VND',
            status: $isSuccess ? PaymentStatus::PENDING : $this->mapResultCodeToStatus($resultCode),
            gateway: $this->getName(),
            rawResponse: $raw,
            createdAt: $now,
            paymentUrl: $raw['payUrl'] ?? null,
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapResultCode($resultCode),
                message: (string) ($raw['message'] ?? ''),
                gatewayCode: (string) $resultCode,
                gatewayMessage: ($raw['message'] ?? null) !== null ? (string) $raw['message'] : null,
            ),
        );
    }

    public function verifyWebhook(mixed $payload, array $headers): WebhookEvent
    {
        if (!is_array($payload)) {
            throw new PaymentSDKException(ErrorCode::WEBHOOK_PROCESSING_FAILED, 'MoMo webhook payload must be an object');
        }

        if (empty($payload['signature'])) {
            throw PaymentSDKException::invalidSignature('Missing MoMo signature');
        }

        // MoMo webhook rawHash keys must be in exactly this order
        $rawHashKeys = ['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId'];
        $signParams  = [
            'accessKey'    => $this->accessKey,
            'amount'       => (string) ($payload['amount'] ?? ''),
            'extraData'    => (string) ($payload['extraData'] ?? ''),
            'message'      => (string) ($payload['message'] ?? ''),
            'orderId'      => (string) ($payload['orderId'] ?? ''),
            'orderInfo'    => (string) ($payload['orderInfo'] ?? ''),
            'orderType'    => (string) ($payload['orderType'] ?? ''),
            'partnerCode'  => (string) ($payload['partnerCode'] ?? ''),
            'payType'      => (string) ($payload['payType'] ?? ''),
            'requestId'    => (string) ($payload['requestId'] ?? ''),
            'responseTime' => (string) ($payload['responseTime'] ?? ''),
            'resultCode'   => (string) ($payload['resultCode'] ?? ''),
            'transId'      => (string) ($payload['transId'] ?? ''),
        ];

        $rawHash  = Crypto::buildRawString($signParams, $rawHashKeys);
        $expected = Crypto::hmacSHA256($rawHash, $this->secretKey);

        if (!Crypto::timingSafeEqual($expected, (string) $payload['signature'])) {
            throw PaymentSDKException::invalidSignature();
        }

        $resultCode = (int) ($payload['resultCode'] ?? -1);
        $status     = $this->mapResultCodeToStatus($resultCode);
        $eventType  = match ($status) {
            PaymentStatus::SUCCESS   => WebhookEventType::PAYMENT_SUCCESS,
            PaymentStatus::CANCELLED => WebhookEventType::PAYMENT_CANCELLED,
            default                  => WebhookEventType::PAYMENT_FAILED,
        };

        return new WebhookEvent(
            gateway: $this->getName(),
            eventType: $eventType,
            orderId: (string) ($payload['orderId'] ?? ''),
            transactionId: (string) ($payload['transId'] ?? ''),
            amount: (int) ($payload['amount'] ?? 0),
            currency: 'VND',
            status: $status,
            rawData: $payload,
            receivedAt: new \DateTimeImmutable(),
        );
    }

    public function getTransaction(string $transactionId, ?string $orderId = null): PaymentResult
    {
        $resolvedOrderId = $orderId ?? $transactionId;
        $now       = new \DateTimeImmutable();
        $requestId = $this->buildRequestId($resolvedOrderId);

        $rawHashKeys = ['accessKey', 'orderId', 'partnerCode', 'requestId'];
        $signParams  = [
            'accessKey'   => $this->accessKey,
            'orderId'     => $resolvedOrderId,
            'partnerCode' => $this->partnerCode,
            'requestId'   => $requestId,
        ];

        $rawHash   = Crypto::buildRawString($signParams, $rawHashKeys);
        $signature = Crypto::hmacSHA256($rawHash, $this->secretKey);

        $body = [
            'partnerCode' => $this->partnerCode,
            'accessKey'   => $this->accessKey,
            'requestId'   => $requestId,
            'orderId'     => $resolvedOrderId,
            'signature'   => $signature,
            'lang'        => 'vi',
        ];

        $raw        = Http::post($this->baseUrl() . '/query', $body, $this->timeoutMs, $this->retries, httpClient: $this->httpClient);
        $resultCode = (int) ($raw['resultCode'] ?? -1);
        $isSuccess  = $resultCode === 0;

        return new PaymentResult(
            success: $isSuccess,
            orderId: (string) ($raw['orderId'] ?? $resolvedOrderId),
            amount: (int) ($raw['amount'] ?? 0),
            currency: 'VND',
            status: $this->mapResultCodeToStatus($resultCode),
            gateway: $this->getName(),
            rawResponse: $raw,
            createdAt: $now,
            transactionId: (string) ($raw['transId'] ?? ''),
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapResultCode($resultCode),
                message: (string) ($raw['message'] ?? ''),
                gatewayCode: (string) $resultCode,
                gatewayMessage: ($raw['message'] ?? null) !== null ? (string) $raw['message'] : null,
            ),
        );
    }

    public function refund(array $input): RefundResult
    {
        if (empty($input['transactionId'])) throw PaymentSDKException::invalidInput('transactionId is required');
        if (empty($input['orderId']))       throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');

        $requestId   = $this->buildRequestId((string) $input['orderId']);
        $description = (string) ($input['reason'] ?? "Refund for order {$input['orderId']}");

        // MoMo refund rawHash keys must be in exactly this order
        $rawHashKeys = ['accessKey', 'amount', 'description', 'orderId', 'partnerCode', 'requestId', 'transId'];
        $signParams  = [
            'accessKey'   => $this->accessKey,
            'amount'      => (string) $input['amount'],
            'description' => $description,
            'orderId'     => (string) $input['orderId'],
            'partnerCode' => $this->partnerCode,
            'requestId'   => $requestId,
            'transId'     => (string) $input['transactionId'],
        ];

        $rawHash   = Crypto::buildRawString($signParams, $rawHashKeys);
        $signature = Crypto::hmacSHA256($rawHash, $this->secretKey);

        $body = [
            'partnerCode' => $this->partnerCode,
            'accessKey'   => $this->accessKey,
            'requestId'   => $requestId,
            'amount'      => $input['amount'],
            'orderId'     => $input['orderId'],
            'transId'     => (int) $input['transactionId'],
            'description' => $description,
            'signature'   => $signature,
            'lang'        => 'vi',
        ];

        $raw        = Http::post($this->baseUrl() . '/refund', $body, $this->timeoutMs, $this->retries, httpClient: $this->httpClient);
        $resultCode = (int) ($raw['resultCode'] ?? -1);
        $isSuccess  = $resultCode === 0;

        return new RefundResult(
            success: $isSuccess,
            orderId: (string) $input['orderId'],
            amount: (int) ($raw['amount'] ?? $input['amount']),
            status: $isSuccess ? RefundStatus::SUCCESS : RefundStatus::FAILED,
            rawResponse: $raw,
            refundId: (string) ($raw['transId'] ?? ''),
            transactionId: (string) $input['transactionId'],
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapResultCode($resultCode),
                message: (string) ($raw['message'] ?? ''),
                gatewayCode: (string) $resultCode,
                gatewayMessage: ($raw['message'] ?? null) !== null ? (string) $raw['message'] : null,
            ),
        );
    }
}
