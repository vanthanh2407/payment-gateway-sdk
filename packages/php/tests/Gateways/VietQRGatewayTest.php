<?php

declare(strict_types=1);

namespace PaymentSdk\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use PaymentSdk\Enums\ErrorCode;
use PaymentSdk\Enums\PaymentStatus;
use PaymentSdk\Enums\RefundStatus;
use PaymentSdk\Enums\WebhookEventType;
use PaymentSdk\Exceptions\PaymentSDKException;
use PaymentSdk\Gateways\VietQRGateway;
use PaymentSdk\Utils\Crypto;

class VietQRGatewayTest extends TestCase
{
    private const CLIENT_ID    = 'test_client_id';
    private const API_KEY      = 'test_api_key_secret';
    private const BANK_CODE    = '970010';
    private const BANK_ACCOUNT = '123456789';
    private const ACCOUNT_NAME = 'TEST MERCHANT';

    private const ACCESS_TOKEN   = 'fake-bearer-token-abc';
    private const TOKEN_RESPONSE = [
        'access_token' => self::ACCESS_TOKEN,
        'token_type'   => 'Bearer',
        'expires_in'   => 300,
    ];

    private const PAYMENT_INPUT = [
        'orderId'     => 'ORDER-VQR-001',
        'amount'      => 150_000,
        'currency'    => 'VND',
        'description' => 'Test VietQR payment',
        'returnUrl'   => 'https://example.com/return',
    ];

    private function makeGateway(?callable $httpClient = null): VietQRGateway
    {
        return new VietQRGateway(
            clientId: self::CLIENT_ID,
            apiKey: self::API_KEY,
            bankCode: self::BANK_CODE,
            bankAccount: self::BANK_ACCOUNT,
            accountName: self::ACCOUNT_NAME,
            sandbox: true,
            httpClient: $httpClient,
        );
    }

    /**
     * httpClient that returns $tokenResponse for the token endpoint
     * and $apiResponse for any other endpoint.
     *
     * @param array<string, mixed> $apiResponse
     * @param array<string, mixed>|null $tokenResponse
     */
    private function makeHttpClient(array $apiResponse, ?array $tokenResponse = null): callable
    {
        $token = $tokenResponse ?? self::TOKEN_RESPONSE;
        return function (string $url, array $body, array $headers) use ($token, $apiResponse): array {
            if (str_contains($url, 'token_generate')) {
                return $token;
            }
            return $apiResponse;
        };
    }

    /**
     * Build a valid VietQR webhook payload with a correct checksum.
     *
     * @param array<string, mixed> $dataOverrides
     * @param string $code  top-level "code" field (default "00")
     */
    private function buildWebhookPayload(array $dataOverrides = [], string $code = '00'): array
    {
        $data = array_merge([
            'orderId'       => 'ORDER-VQR-001',
            'transactionId' => 'TXN-001',
            'amount'        => 150_000,
            'bankCode'      => self::BANK_CODE,
            'bankAccount'   => self::BANK_ACCOUNT,
            'content'       => 'Test payment',
            'transTime'     => '2024-01-01 12:00:00',
        ], $dataOverrides);

        // Webhook checksum: MD5(orderId + bankCode + amount + transactionId + apiKey)
        $data['checkSum'] = Crypto::md5(
            $data['orderId'] . $data['bankCode'] . (string) $data['amount'] . $data['transactionId'] . self::API_KEY
        );

        return [
            'code'    => $code,
            'desc'    => $code === '00' ? 'Success' : 'Failed',
            'success' => $code === '00',
            'data'    => $data,
        ];
    }

    // ─── Constructor ─────────────────────────────────────────────────────────

    public function testConstructorThrowsOnMissingClientId(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('VietQR: clientId is required');
        new VietQRGateway(clientId: '', apiKey: 'k', bankCode: 'b', bankAccount: 'a', accountName: 'n');
    }

    public function testConstructorThrowsOnMissingApiKey(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('VietQR: apiKey is required');
        new VietQRGateway(clientId: 'c', apiKey: '', bankCode: 'b', bankAccount: 'a', accountName: 'n');
    }

    public function testConstructorThrowsOnMissingBankCode(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('VietQR: bankCode is required');
        new VietQRGateway(clientId: 'c', apiKey: 'k', bankCode: '', bankAccount: 'a', accountName: 'n');
    }

    public function testConstructorThrowsOnMissingBankAccount(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('VietQR: bankAccount is required');
        new VietQRGateway(clientId: 'c', apiKey: 'k', bankCode: 'b', bankAccount: '', accountName: 'n');
    }

    public function testConstructorThrowsOnMissingAccountName(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('VietQR: accountName is required');
        new VietQRGateway(clientId: 'c', apiKey: 'k', bankCode: 'b', bankAccount: 'a', accountName: '');
    }

    // ─── Capabilities ────────────────────────────────────────────────────────

    public function testGetName(): void
    {
        $gw = $this->makeGateway();
        $this->assertSame('vietqr', $gw->getName());
    }

    public function testCapabilities(): void
    {
        $cap = $this->makeGateway()->getCapabilities();
        $this->assertTrue($cap->supportRefund);
        $this->assertFalse($cap->supportPartialRefund);
        $this->assertFalse($cap->supportRecurring);
        $this->assertTrue($cap->supportWebhook);
        $this->assertTrue($cap->supportQRCode);
        $this->assertSame(['VND'], $cap->currencies);
        $this->assertContains('qr', $cap->paymentMethods);
        $this->assertContains('banking', $cap->paymentMethods);
    }

    // ─── createPayment ───────────────────────────────────────────────────────

    public function testCreatePaymentSuccess(): void
    {
        $apiResponse = [
            'code'    => '00',
            'message' => 'Success',
            'data'    => [
                'qr'         => 'qr-raw-string',
                'qrDataURL'  => 'data:image/png;base64,abc123',
                'urlLink'    => 'https://example.com/pay',
            ],
        ];

        $gw     = $this->makeGateway($this->makeHttpClient($apiResponse));
        $result = $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertTrue($result->success);
        $this->assertSame('ORDER-VQR-001', $result->orderId);
        $this->assertSame(150_000, $result->amount);
        $this->assertSame('VND', $result->currency);
        $this->assertSame(PaymentStatus::PENDING, $result->status);
        $this->assertSame('vietqr', $result->gateway);
        $this->assertSame('data:image/png;base64,abc123', $result->paymentUrl);
        $this->assertNull($result->error);
    }

    public function testCreatePaymentSendsCorrectChecksum(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            if (str_contains($url, 'token_generate')) {
                return self::TOKEN_RESPONSE;
            }
            $capturedBody = $body;
            return ['code' => '00', 'message' => 'OK', 'data' => ['qrDataURL' => 'data:image/png;base64,x']];
        };

        $gw = $this->makeGateway($httpClient);
        $gw->createPayment(self::PAYMENT_INPUT);

        $expectedCheckSum = Crypto::md5(self::CLIENT_ID . 'ORDER-VQR-001' . self::API_KEY);
        $this->assertSame($expectedCheckSum, $capturedBody['checkSum']);
        $this->assertSame(self::BANK_CODE, $capturedBody['bankCode']);
        $this->assertSame(self::BANK_ACCOUNT, $capturedBody['bankAccount']);
        $this->assertSame(self::ACCOUNT_NAME, $capturedBody['userBankName']);
        $this->assertSame('C', $capturedBody['transType']);
    }

    public function testCreatePaymentSendsBearerToken(): void
    {
        $capturedHeaders = null;
        $httpClient      = function (string $url, array $body, array $headers) use (&$capturedHeaders): array {
            if (str_contains($url, 'token_generate')) {
                return self::TOKEN_RESPONSE;
            }
            $capturedHeaders = $headers;
            return ['code' => '00', 'message' => 'OK', 'data' => ['qrDataURL' => 'x']];
        };

        $gw = $this->makeGateway($httpClient);
        $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertSame('Bearer ' . self::ACCESS_TOKEN, $capturedHeaders['Authorization']);
    }

    public function testCreatePaymentUsesProductionUrl(): void
    {
        $capturedUrl = null;
        $httpClient  = function (string $url, array $body, array $headers) use (&$capturedUrl): array {
            if (str_contains($url, 'token_generate')) {
                return self::TOKEN_RESPONSE;
            }
            $capturedUrl = $url;
            return ['code' => '00', 'message' => 'OK', 'data' => ['qrDataURL' => 'x']];
        };

        $gw = new VietQRGateway(
            clientId: self::CLIENT_ID,
            apiKey: self::API_KEY,
            bankCode: self::BANK_CODE,
            bankAccount: self::BANK_ACCOUNT,
            accountName: self::ACCOUNT_NAME,
            sandbox: false,
            httpClient: $httpClient,
        );
        $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertStringContainsString('api.vietqr.org', $capturedUrl);
        $this->assertStringNotContainsString('dev.vietqr.org', $capturedUrl);
    }

    public function testCreatePaymentFailure(): void
    {
        $apiResponse = ['code' => 'E01', 'message' => 'Invalid credentials'];
        $gw          = $this->makeGateway($this->makeHttpClient($apiResponse));
        $result      = $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatus::FAILED, $result->status);
        $this->assertNotNull($result->error);
        $this->assertSame(ErrorCode::AUTHENTICATION_FAILED, $result->error->code);
        $this->assertSame('E01', $result->error->gatewayCode);
    }

    public function testCreatePaymentRejectsNonVNDCurrency(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('VietQR only supports VND currency');
        $gw = $this->makeGateway();
        $gw->createPayment(['orderId' => 'X', 'amount' => 100, 'currency' => 'USD']);
    }

    public function testCreatePaymentRequiresOrderId(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('orderId is required');
        $this->makeGateway()->createPayment(['amount' => 100]);
    }

    public function testCreatePaymentRequiresPositiveAmount(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('amount must be positive');
        $this->makeGateway()->createPayment(['orderId' => 'X', 'amount' => 0]);
    }

    public function testCreatePaymentTokenCaching(): void
    {
        $callCount  = 0;
        $httpClient = function (string $url, array $body, array $headers) use (&$callCount): array {
            if (str_contains($url, 'token_generate')) {
                $callCount++;
                return self::TOKEN_RESPONSE;
            }
            return ['code' => '00', 'message' => 'OK', 'data' => ['qrDataURL' => 'x']];
        };

        $gw = $this->makeGateway($httpClient);
        $gw->createPayment(self::PAYMENT_INPUT);
        $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertSame(1, $callCount, 'Token should be fetched only once within TTL');
    }

    public function testCreatePaymentThrowsOnAuthTokenFailure(): void
    {
        $httpClient = function (string $url, array $body, array $headers): array {
            if (str_contains($url, 'token_generate')) {
                return ['token_type' => 'Bearer'];  // missing access_token
            }
            return [];
        };

        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('VietQR: failed to obtain access token');
        $this->makeGateway($httpClient)->createPayment(self::PAYMENT_INPUT);
    }

    // ─── verifyWebhook ───────────────────────────────────────────────────────

    public function testVerifyWebhookSuccess(): void
    {
        $payload = $this->buildWebhookPayload();
        $event   = $this->makeGateway()->verifyWebhook($payload, []);

        $this->assertSame('vietqr', $event->gateway);
        $this->assertSame(WebhookEventType::PAYMENT_SUCCESS, $event->eventType);
        $this->assertSame('ORDER-VQR-001', $event->orderId);
        $this->assertSame('TXN-001', $event->transactionId);
        $this->assertSame(150_000, $event->amount);
        $this->assertSame('VND', $event->currency);
        $this->assertSame(PaymentStatus::SUCCESS, $event->status);
    }

    public function testVerifyWebhookFailedPayment(): void
    {
        $payload = $this->buildWebhookPayload(code: 'E01');
        $event   = $this->makeGateway()->verifyWebhook($payload, []);

        $this->assertSame(WebhookEventType::PAYMENT_FAILED, $event->eventType);
        $this->assertSame(PaymentStatus::FAILED, $event->status);
    }

    public function testVerifyWebhookSuccessViaBoolField(): void
    {
        $payload           = $this->buildWebhookPayload(code: '');
        $payload['code']    = 'SOMETHING_ELSE';
        $payload['success'] = true;

        // Rebuild checksum because we didn't change data fields
        $event = $this->makeGateway()->verifyWebhook($payload, []);
        $this->assertSame(PaymentStatus::SUCCESS, $event->status);
    }

    public function testVerifyWebhookThrowsOnTamperedChecksum(): void
    {
        $payload                     = $this->buildWebhookPayload();
        $payload['data']['checkSum'] = 'tampered_checksum_000';

        try {
            $this->makeGateway()->verifyWebhook($payload, []);
            $this->fail('Expected PaymentSDKException');
        } catch (PaymentSDKException $e) {
            $this->assertSame(ErrorCode::INVALID_SIGNATURE, $e->errorCode);
        }
    }

    public function testVerifyWebhookThrowsOnMissingData(): void
    {
        try {
            $this->makeGateway()->verifyWebhook(['code' => '00', 'success' => true], []);
            $this->fail('Expected PaymentSDKException');
        } catch (PaymentSDKException $e) {
            $this->assertSame(ErrorCode::WEBHOOK_PROCESSING_FAILED, $e->errorCode);
        }
    }

    public function testVerifyWebhookThrowsOnNonArrayPayload(): void
    {
        try {
            $this->makeGateway()->verifyWebhook('not-an-array', []);
            $this->fail('Expected PaymentSDKException');
        } catch (PaymentSDKException $e) {
            $this->assertSame(ErrorCode::WEBHOOK_PROCESSING_FAILED, $e->errorCode);
        }
    }

    // ─── getTransaction ──────────────────────────────────────────────────────

    public function testGetTransactionSuccess(): void
    {
        $apiResponse = [
            'code'    => '00',
            'message' => 'Success',
            'data'    => [
                'orderId'       => 'ORDER-VQR-001',
                'transactionId' => 'TXN-001',
                'amount'        => 150_000,
                'bankCode'      => self::BANK_CODE,
                'bankAccount'   => self::BANK_ACCOUNT,
                'content'       => 'Test',
                'transTime'     => '2024-01-01 12:00:00',
            ],
        ];

        $gw     = $this->makeGateway($this->makeHttpClient($apiResponse));
        $result = $gw->getTransaction('TXN-001', 'ORDER-VQR-001');

        $this->assertTrue($result->success);
        $this->assertSame('ORDER-VQR-001', $result->orderId);
        $this->assertSame('TXN-001', $result->transactionId);
        $this->assertSame(150_000, $result->amount);
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertNull($result->error);
    }

    public function testGetTransactionUsesTransactionIdAsOrderIdWhenOmitted(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            if (str_contains($url, 'token_generate')) {
                return self::TOKEN_RESPONSE;
            }
            $capturedBody = $body;
            return ['code' => '00', 'message' => 'OK', 'data' => ['orderId' => 'TXN-FALLBACK', 'transactionId' => 'TXN-FALLBACK', 'amount' => 0]];
        };

        $gw = $this->makeGateway($httpClient);
        $gw->getTransaction('TXN-FALLBACK');

        $this->assertSame('TXN-FALLBACK', $capturedBody['orderId']);
    }

    public function testGetTransactionFailure(): void
    {
        $apiResponse = ['code' => 'E24', 'message' => 'Invalid input'];
        $gw          = $this->makeGateway($this->makeHttpClient($apiResponse));
        $result      = $gw->getTransaction('TXN-FAIL', 'ORDER-FAIL');

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatus::FAILED, $result->status);
        $this->assertNotNull($result->error);
        $this->assertSame(ErrorCode::INVALID_INPUT, $result->error->code);
    }

    // ─── refund ──────────────────────────────────────────────────────────────

    public function testRefundSuccess(): void
    {
        $apiResponse = [
            'code'    => '00',
            'message' => 'Success',
            'data'    => [
                'refundId' => 'REFUND-001',
                'orderId'  => 'ORDER-VQR-001',
                'amount'   => 150_000,
                'status'   => 'SUCCESS',
            ],
        ];

        $gw     = $this->makeGateway($this->makeHttpClient($apiResponse));
        $result = $gw->refund([
            'transactionId' => 'TXN-001',
            'orderId'       => 'ORDER-VQR-001',
            'amount'        => 150_000,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('ORDER-VQR-001', $result->orderId);
        $this->assertSame('TXN-001', $result->transactionId);
        $this->assertSame(150_000, $result->amount);
        $this->assertSame(RefundStatus::SUCCESS, $result->status);
        $this->assertSame('REFUND-001', $result->refundId);
        $this->assertNull($result->error);
    }

    public function testRefundSendsCorrectChecksum(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            if (str_contains($url, 'token_generate')) {
                return self::TOKEN_RESPONSE;
            }
            $capturedBody = $body;
            return ['code' => '00', 'message' => 'OK', 'data' => ['refundId' => 'R1']];
        };

        $gw = $this->makeGateway($httpClient);
        $gw->refund(['transactionId' => 'TXN-001', 'orderId' => 'ORDER-001', 'amount' => 50_000]);

        $expected = Crypto::md5(self::CLIENT_ID . 'TXN-001' . '50000' . self::API_KEY);
        $this->assertSame($expected, $capturedBody['checkSum']);
    }

    public function testRefundSendsRemarkWhenReasonGiven(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            if (str_contains($url, 'token_generate')) {
                return self::TOKEN_RESPONSE;
            }
            $capturedBody = $body;
            return ['code' => '00', 'message' => 'OK', 'data' => ['refundId' => 'R1']];
        };

        $gw = $this->makeGateway($httpClient);
        $gw->refund(['transactionId' => 'TXN-001', 'orderId' => 'ORDER-001', 'amount' => 50_000, 'reason' => 'Customer request']);

        $this->assertSame('Customer request', $capturedBody['remark']);
    }

    public function testRefundFailure(): void
    {
        $apiResponse = ['code' => 'E42', 'message' => 'Refund failed'];
        $gw          = $this->makeGateway($this->makeHttpClient($apiResponse));
        $result      = $gw->refund([
            'transactionId' => 'TXN-FAIL',
            'orderId'       => 'ORDER-FAIL',
            'amount'        => 50_000,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(RefundStatus::FAILED, $result->status);
        $this->assertNotNull($result->error);
        $this->assertSame(ErrorCode::REFUND_FAILED, $result->error->code);
    }

    public function testRefundAlreadyProcessed(): void
    {
        $apiResponse = ['code' => 'E157', 'message' => 'Already refunded'];
        $gw          = $this->makeGateway($this->makeHttpClient($apiResponse));
        $result      = $gw->refund([
            'transactionId' => 'TXN-001',
            'orderId'       => 'ORDER-001',
            'amount'        => 50_000,
        ]);

        $this->assertNotNull($result->error);
        $this->assertSame(ErrorCode::REFUND_ALREADY_PROCESSED, $result->error->code);
    }

    public function testRefundRequiresTransactionId(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('transactionId is required');
        $this->makeGateway()->refund(['orderId' => 'X', 'amount' => 100]);
    }

    public function testRefundRequiresOrderId(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('orderId is required');
        $this->makeGateway()->refund(['transactionId' => 'T', 'amount' => 100]);
    }

    public function testRefundRequiresPositiveAmount(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('amount must be positive');
        $this->makeGateway()->refund(['transactionId' => 'T', 'orderId' => 'X', 'amount' => -1]);
    }

    public function testRefundBasicAuthSentForTokenAndBearerForApi(): void
    {
        $tokenHeaders = null;
        $apiHeaders   = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$tokenHeaders, &$apiHeaders): array {
            if (str_contains($url, 'token_generate')) {
                $tokenHeaders = $headers;
                return self::TOKEN_RESPONSE;
            }
            $apiHeaders = $headers;
            return ['code' => '00', 'message' => 'OK', 'data' => ['refundId' => 'R']];
        };

        $gw = $this->makeGateway($httpClient);
        $gw->refund(['transactionId' => 'T', 'orderId' => 'O', 'amount' => 100]);

        $expected = 'Basic ' . base64_encode(self::CLIENT_ID . ':' . self::API_KEY);
        $this->assertSame($expected, $tokenHeaders['Authorization']);
        $this->assertSame('Bearer ' . self::ACCESS_TOKEN, $apiHeaders['Authorization']);
    }
}
