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

final class VNPayGateway implements PaymentGatewayInterface
{
    private const PAYMENT_URL_SANDBOX    = 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html';
    private const PAYMENT_URL_PRODUCTION = 'https://vnpayment.vn/paymentv2/vpcpay.html';
    private const API_URL_SANDBOX        = 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction';
    private const API_URL_PRODUCTION     = 'https://vnpayment.vn/merchant_webapi/api/transaction';

    /** @var array<string, ErrorCode> VNPay response code → ErrorCode (per specs/error-codes.md) */
    private const RESPONSE_CODE_MAP = [
        '07' => ErrorCode::PAYMENT_FAILED,
        '09' => ErrorCode::AUTHENTICATION_FAILED,
        '10' => ErrorCode::AUTHENTICATION_FAILED,
        '11' => ErrorCode::PAYMENT_EXPIRED,
        '12' => ErrorCode::CARD_LOCKED,
        '13' => ErrorCode::AUTHENTICATION_FAILED,
        '24' => ErrorCode::PAYMENT_CANCELLED,
        '51' => ErrorCode::INSUFFICIENT_FUNDS,
        '65' => ErrorCode::PAYMENT_FAILED,
        '75' => ErrorCode::BANK_MAINTENANCE,
        '79' => ErrorCode::AUTHENTICATION_FAILED,
    ];

    /** @var callable|null */
    private $httpClient;

    public function __construct(
        private readonly string $tmnCode,
        private readonly string $hashSecret,
        private readonly bool $sandbox = false,
        private readonly int $timeoutMs = 30_000,
        private readonly int $retries = 2,
        ?callable $httpClient = null,
    ) {
        if ($tmnCode === '')    throw PaymentSDKException::invalidConfig('VNPay: tmnCode is required');
        if ($hashSecret === '') throw PaymentSDKException::invalidConfig('VNPay: hashSecret is required');

        $this->httpClient = $httpClient;
    }

    public function getName(): string
    {
        return 'vnpay';
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
            paymentMethods: ['card', 'banking', 'qr'],
        );
    }

    private function paymentBaseUrl(): string
    {
        return $this->sandbox ? self::PAYMENT_URL_SANDBOX : self::PAYMENT_URL_PRODUCTION;
    }

    private function apiUrl(): string
    {
        return $this->sandbox ? self::API_URL_SANDBOX : self::API_URL_PRODUCTION;
    }

    private function mapResponseCode(string $code): ErrorCode
    {
        return self::RESPONSE_CODE_MAP[$code] ?? ErrorCode::UNKNOWN_ERROR;
    }

    private function mapTransactionStatus(string $transactionStatus, string $responseCode): PaymentStatus
    {
        if ($transactionStatus === '00') return PaymentStatus::SUCCESS;
        if ($responseCode === '24')      return PaymentStatus::CANCELLED;
        if ($responseCode === '11')      return PaymentStatus::EXPIRED;
        return PaymentStatus::FAILED;
    }

    public function createPayment(array $input): PaymentResult
    {
        if (empty($input['orderId'])) throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');
        if (!empty($input['currency']) && $input['currency'] !== 'VND') {
            throw PaymentSDKException::invalidInput('VNPay only supports VND currency');
        }

        $now     = new \DateTimeImmutable();
        $orderId = (string) $input['orderId'];

        $params = [
            'vnp_Version'    => '2.1.0',
            'vnp_Command'    => 'pay',
            'vnp_TmnCode'    => $this->tmnCode,
            'vnp_Locale'     => (string) ($input['locale'] ?? 'vn'),
            'vnp_CurrCode'   => 'VND',
            'vnp_TxnRef'     => $orderId,
            'vnp_OrderInfo'  => (string) $input['description'],
            'vnp_OrderType'  => 'other',
            'vnp_Amount'     => (string) ($input['amount'] * 100),
            'vnp_ReturnUrl'  => (string) $input['returnUrl'],
            'vnp_IpAddr'     => (string) ($input['customerInfo']['ipAddress'] ?? '127.0.0.1'),
            'vnp_CreateDate' => Crypto::formatVNPayDate($now),
        ];

        if (!empty($input['expireAt']) && $input['expireAt'] instanceof \DateTimeInterface) {
            $params['vnp_ExpireDate'] = Crypto::formatVNPayDate($input['expireAt']);
        }

        $queryString = Crypto::buildSortedQueryString($params);
        $secureHash  = Crypto::hmacSHA512($queryString, $this->hashSecret);
        $paymentUrl  = $this->paymentBaseUrl() . '?' . $queryString . '&vnp_SecureHash=' . $secureHash;

        return new PaymentResult(
            success: true,
            orderId: $orderId,
            amount: (int) $input['amount'],
            currency: 'VND',
            status: PaymentStatus::PENDING,
            gateway: $this->getName(),
            rawResponse: ['params' => $params, 'queryString' => $queryString],
            createdAt: $now,
            paymentUrl: $paymentUrl,
        );
    }

    public function verifyWebhook(mixed $payload, array $headers): WebhookEvent
    {
        if (!is_array($payload)) {
            throw new PaymentSDKException(ErrorCode::WEBHOOK_PROCESSING_FAILED, 'VNPay webhook payload must be an object');
        }

        if (empty($payload['vnp_SecureHash'])) {
            throw PaymentSDKException::invalidSignature('Missing vnp_SecureHash');
        }

        $receivedHash = (string) $payload['vnp_SecureHash'];

        // Rebuild sign params excluding hash fields, then sort and re-sign
        $signParams = [];
        foreach ($payload as $key => $val) {
            if ($key !== 'vnp_SecureHash' && $key !== 'vnp_SecureHashType') {
                $signParams[$key] = (string) $val;
            }
        }

        $queryString  = Crypto::buildSortedQueryString($signParams);
        $expectedHash = Crypto::hmacSHA512($queryString, $this->hashSecret);

        if (!Crypto::timingSafeEqual($expectedHash, strtolower($receivedHash))) {
            throw PaymentSDKException::invalidSignature();
        }

        $responseCode      = (string) ($payload['vnp_ResponseCode'] ?? '99');
        $transactionStatus = (string) ($payload['vnp_TransactionStatus'] ?? '99');
        $status            = $this->mapTransactionStatus($transactionStatus, $responseCode);

        $eventType = match ($status) {
            PaymentStatus::SUCCESS   => WebhookEventType::PAYMENT_SUCCESS,
            PaymentStatus::CANCELLED => WebhookEventType::PAYMENT_CANCELLED,
            default                  => WebhookEventType::PAYMENT_FAILED,
        };

        return new WebhookEvent(
            gateway: $this->getName(),
            eventType: $eventType,
            orderId: (string) ($payload['vnp_TxnRef'] ?? ''),
            transactionId: (string) ($payload['vnp_TransactionNo'] ?? ''),
            amount: (int) round((float) ($payload['vnp_Amount'] ?? '0') / 100),
            currency: 'VND',
            status: $status,
            rawData: $payload,
            receivedAt: new \DateTimeImmutable(),
        );
    }

    public function getTransaction(string $transactionId, ?string $orderId = null): PaymentResult
    {
        if ($orderId === null || $orderId === '') {
            throw PaymentSDKException::invalidInput('VNPay getTransaction requires orderId');
        }

        $now        = new \DateTimeImmutable();
        $createDate = Crypto::formatVNPayDate($now);
        $requestId  = (string) (int) (microtime(true) * 1000);

        $params = [
            'vnp_RequestId'       => $requestId,
            'vnp_Version'         => '2.1.0',
            'vnp_Command'         => 'querydr',
            'vnp_TmnCode'         => $this->tmnCode,
            'vnp_TxnRef'          => $orderId,
            'vnp_OrderInfo'       => "Query transaction {$orderId}",
            'vnp_TransactionDate' => $createDate,
            'vnp_CreateDate'      => $createDate,
            'vnp_IpAddr'          => '127.0.0.1',
        ];

        $signData = implode('|', [
            $params['vnp_RequestId'],
            $params['vnp_Version'],
            $params['vnp_Command'],
            $params['vnp_TmnCode'],
            $params['vnp_TxnRef'],
            $params['vnp_TransactionDate'],
            $params['vnp_CreateDate'],
            $params['vnp_IpAddr'],
            $params['vnp_OrderInfo'],
        ]);

        $params['vnp_SecureHash'] = Crypto::hmacSHA512($signData, $this->hashSecret);

        $raw          = Http::post($this->apiUrl(), $params, $this->timeoutMs, $this->retries, httpClient: $this->httpClient);
        $responseCode = (string) ($raw['vnp_ResponseCode'] ?? '99');
        $txStatus     = (string) ($raw['vnp_TransactionStatus'] ?? '99');
        $isSuccess    = $responseCode === '00' && $txStatus === '00';

        if ($responseCode === '91') {
            throw PaymentSDKException::transactionNotFound($transactionId);
        }

        return new PaymentResult(
            success: $isSuccess,
            orderId: (string) ($raw['vnp_TxnRef'] ?? $orderId),
            amount: (int) round((float) ($raw['vnp_Amount'] ?? '0') / 100),
            currency: 'VND',
            status: $isSuccess
                ? PaymentStatus::SUCCESS
                : $this->mapTransactionStatus($txStatus, $responseCode),
            gateway: $this->getName(),
            rawResponse: $raw,
            createdAt: $now,
            transactionId: (string) ($raw['vnp_TransactionNo'] ?? $transactionId),
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapResponseCode($responseCode),
                message: (string) ($raw['vnp_Message'] ?? ''),
                gatewayCode: $responseCode,
                gatewayMessage: ($raw['vnp_Message'] ?? null) !== null ? (string) $raw['vnp_Message'] : null,
            ),
        );
    }

    public function refund(array $input): RefundResult
    {
        if (empty($input['transactionId'])) throw PaymentSDKException::invalidInput('transactionId is required');
        if (empty($input['orderId']))       throw PaymentSDKException::invalidInput('orderId is required');
        if (empty($input['amount']) || $input['amount'] <= 0) throw PaymentSDKException::invalidInput('amount must be positive');

        $now         = new \DateTimeImmutable();
        $createDate  = Crypto::formatVNPayDate($now);
        $requestId   = (string) (int) (microtime(true) * 1000);
        $description = (string) ($input['reason'] ?? "Refund for order {$input['orderId']}");

        $params = [
            'vnp_RequestId'       => $requestId,
            'vnp_Version'         => '2.1.0',
            'vnp_Command'         => 'refund',
            'vnp_TmnCode'         => $this->tmnCode,
            'vnp_TransactionType' => '02',
            'vnp_TxnRef'          => (string) $input['orderId'],
            'vnp_Amount'          => (string) ($input['amount'] * 100),
            'vnp_OrderInfo'       => $description,
            'vnp_TransactionNo'   => (string) $input['transactionId'],
            'vnp_TransactionDate' => $createDate,
            'vnp_CreateDate'      => $createDate,
            'vnp_CreateBy'        => 'sdk',
            'vnp_IpAddr'          => '127.0.0.1',
        ];

        $signData = implode('|', [
            $params['vnp_RequestId'],
            $params['vnp_Version'],
            $params['vnp_Command'],
            $params['vnp_TmnCode'],
            $params['vnp_TransactionType'],
            $params['vnp_TxnRef'],
            $params['vnp_Amount'],
            $params['vnp_TransactionNo'],
            $params['vnp_TransactionDate'],
            $params['vnp_CreateBy'],
            $params['vnp_CreateDate'],
            $params['vnp_IpAddr'],
            $params['vnp_OrderInfo'],
        ]);

        $params['vnp_SecureHash'] = Crypto::hmacSHA512($signData, $this->hashSecret);

        $raw          = Http::post($this->apiUrl(), $params, $this->timeoutMs, $this->retries, httpClient: $this->httpClient);
        $responseCode = (string) ($raw['vnp_ResponseCode'] ?? '99');
        $txStatus     = (string) ($raw['vnp_TransactionStatus'] ?? '99');
        $isSuccess    = $responseCode === '00' && $txStatus === '00';

        return new RefundResult(
            success: $isSuccess,
            orderId: (string) $input['orderId'],
            amount: (int) $input['amount'],
            status: $isSuccess ? RefundStatus::SUCCESS : RefundStatus::FAILED,
            rawResponse: $raw,
            refundId: isset($raw['vnp_TransactionNo']) ? (string) $raw['vnp_TransactionNo'] : null,
            transactionId: (string) $input['transactionId'],
            error: $isSuccess ? null : new PaymentError(
                code: $this->mapResponseCode($responseCode),
                message: (string) ($raw['vnp_Message'] ?? ''),
                gatewayCode: $responseCode,
                gatewayMessage: ($raw['vnp_Message'] ?? null) !== null ? (string) $raw['vnp_Message'] : null,
            ),
        );
    }
}
