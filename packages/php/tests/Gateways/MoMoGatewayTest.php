<?php

declare(strict_types=1);

namespace PaymentSdk\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use PaymentSdk\Enums\ErrorCode;
use PaymentSdk\Enums\PaymentStatus;
use PaymentSdk\Enums\RefundStatus;
use PaymentSdk\Exceptions\PaymentSDKException;
use PaymentSdk\Gateways\MoMoGateway;
use PaymentSdk\Utils\Crypto;

class MoMoGatewayTest extends TestCase
{
    private const PARTNER_CODE = 'MOMO_PARTNER';
    private const ACCESS_KEY   = 'test_access_key';
    private const SECRET_KEY   = 'test_secret_key_abcdef1234567890';

    private const PAYMENT_INPUT = [
        'orderId'     => 'ORDER-002',
        'amount'      => 50_000,
        'currency'    => 'VND',
        'description' => 'Test MoMo payment',
        'returnUrl'   => 'https://example.com/return',
        'ipnUrl'      => 'https://example.com/ipn',
    ];

    private function makeGateway(?callable $httpClient = null): MoMoGateway
    {
        return new MoMoGateway(
            partnerCode: self::PARTNER_CODE,
            accessKey: self::ACCESS_KEY,
            secretKey: self::SECRET_KEY,
            sandbox: true,
            httpClient: $httpClient,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function buildWebhookPayload(array $overrides = []): array
    {
        $data = array_merge([
            'partnerCode'  => self::PARTNER_CODE,
            'orderId'      => 'ORDER-002',
            'requestId'    => 'ORDER-002_1234567890',
            'amount'       => 50_000,
            'orderInfo'    => 'Test MoMo payment',
            'orderType'    => 'momo_wallet',
            'transId'      => 4111111111,
            'resultCode'   => 0,
            'message'      => 'Successful.',
            'payType'      => 'qr',
            'responseTime' => 1704067200000,
            'extraData'    => '',
        ], $overrides);

        $rawHashKeys = ['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId'];
        $signParams  = [
            'accessKey'    => self::ACCESS_KEY,
            'amount'       => (string) $data['amount'],
            'extraData'    => (string) $data['extraData'],
            'message'      => (string) $data['message'],
            'orderId'      => (string) $data['orderId'],
            'orderInfo'    => (string) $data['orderInfo'],
            'orderType'    => (string) $data['orderType'],
            'partnerCode'  => (string) $data['partnerCode'],
            'payType'      => (string) $data['payType'],
            'requestId'    => (string) $data['requestId'],
            'responseTime' => (string) $data['responseTime'],
            'resultCode'   => (string) $data['resultCode'],
            'transId'      => (string) $data['transId'],
        ];

        $rawHash             = Crypto::buildRawString($signParams, $rawHashKeys);
        $data['signature']   = Crypto::hmacSHA256($rawHash, self::SECRET_KEY);

        return $data;
    }

    // ─── Constructor validation ───────────────────────────────────────────────

    public function testThrowsInvalidConfigWhenPartnerCodeEmpty(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('partnerCode is required');
        new MoMoGateway('', 'access', 'secret');
    }

    public function testThrowsInvalidConfigWhenAccessKeyEmpty(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('accessKey is required');
        new MoMoGateway('partner', '', 'secret');
    }

    public function testThrowsInvalidConfigWhenSecretKeyEmpty(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('secretKey is required');
        new MoMoGateway('partner', 'access', '');
    }

    // ─── Capabilities ─────────────────────────────────────────────────────────

    public function testGetCapabilities(): void
    {
        $caps = $this->makeGateway()->getCapabilities();
        $this->assertTrue($caps->supportRefund);
        $this->assertTrue($caps->supportPartialRefund);
        $this->assertTrue($caps->supportWebhook);
        $this->assertTrue($caps->supportQRCode);
        $this->assertContains('VND', $caps->currencies);
        $this->assertContains('wallet', $caps->paymentMethods);
    }

    // ─── createPayment ────────────────────────────────────────────────────────

    public function testCreatePaymentReturnsPaymentUrlOnSuccess(): void
    {
        $response = [
            'partnerCode'  => self::PARTNER_CODE,
            'requestId'    => 'ORDER-002_123',
            'orderId'      => 'ORDER-002',
            'amount'       => 50_000,
            'responseTime' => 1704067200000,
            'message'      => 'Successful.',
            'resultCode'   => 0,
            'payUrl'       => 'https://test-payment.momo.vn/pay/abc123',
        ];

        $result = $this->makeGateway(fn() => $response)->createPayment(self::PAYMENT_INPUT);

        $this->assertTrue($result->success);
        $this->assertSame('https://test-payment.momo.vn/pay/abc123', $result->paymentUrl);
        $this->assertSame('ORDER-002', $result->orderId);
        $this->assertSame(50_000, $result->amount);
        $this->assertSame('VND', $result->currency);
        $this->assertSame(PaymentStatus::PENDING, $result->status);
        $this->assertSame('momo', $result->gateway);
        $this->assertNull($result->error);
        $this->assertNotNull($result->rawResponse);
    }

    public function testCreatePaymentReturnsFailureOnNonZeroResultCode(): void
    {
        $response = [
            'partnerCode'  => self::PARTNER_CODE,
            'orderId'      => 'ORDER-002',
            'amount'       => 50_000,
            'message'      => 'Duplicate orderId',
            'resultCode'   => 9001,
            'responseTime' => 1704067200000,
        ];

        $result = $this->makeGateway(fn() => $response)->createPayment(self::PAYMENT_INPUT);

        $this->assertFalse($result->success);
        $this->assertSame(ErrorCode::DUPLICATE_ORDER, $result->error?->code);
        $this->assertSame('9001', $result->error?->gatewayCode);
    }

    public function testCreatePaymentUsesSandboxUrl(): void
    {
        $capturedUrl = null;
        $gw = $this->makeGateway(function (string $url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return ['resultCode' => 0, 'payUrl' => 'https://test-payment.momo.vn/pay/x'];
        });

        $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertStringContainsString('test-payment.momo.vn', (string) $capturedUrl);
    }

    public function testCreatePaymentUsesProductionUrl(): void
    {
        $capturedUrl = null;
        $gw = new MoMoGateway(
            partnerCode: self::PARTNER_CODE,
            accessKey: self::ACCESS_KEY,
            secretKey: self::SECRET_KEY,
            sandbox: false,
            httpClient: function (string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return ['resultCode' => 0, 'payUrl' => 'https://payment.momo.vn/pay/x'];
            },
        );

        $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertStringNotContainsString('test-payment', (string) $capturedUrl);
        $this->assertStringContainsString('payment.momo.vn', (string) $capturedUrl);
    }

    public function testCreatePaymentEncodesMetadataAsBase64ExtraData(): void
    {
        $capturedBody = null;
        $gw = $this->makeGateway(function (string $url, array $body) use (&$capturedBody) {
            $capturedBody = $body;
            return ['resultCode' => 0, 'payUrl' => 'https://test-payment.momo.vn/pay/x'];
        });

        $metadata = ['userId' => 42, 'ref' => 'campaign-spring'];
        $gw->createPayment(array_merge(self::PAYMENT_INPUT, ['metadata' => $metadata]));

        $decoded = json_decode(base64_decode((string) $capturedBody['extraData']), true);
        $this->assertSame($metadata, $decoded);
    }

    public function testCreatePaymentThrowsWhenOrderIdMissing(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->createPayment(array_merge(self::PAYMENT_INPUT, ['orderId' => '']));
    }

    public function testCreatePaymentThrowsWhenAmountNegative(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->createPayment(array_merge(self::PAYMENT_INPUT, ['amount' => -1]));
    }

    public function testCreatePaymentThrowsForNonVndCurrency(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->createPayment(array_merge(self::PAYMENT_INPUT, ['currency' => 'USD']));
    }

    // ─── verifyWebhook ────────────────────────────────────────────────────────

    public function testVerifyWebhookSuccessfulPayload(): void
    {
        $payload = $this->buildWebhookPayload();
        $event   = $this->makeGateway()->verifyWebhook($payload, []);

        $this->assertSame('momo', $event->gateway);
        $this->assertSame('ORDER-002', $event->orderId);
        $this->assertSame('4111111111', $event->transactionId);
        $this->assertSame(PaymentStatus::SUCCESS, $event->status);
        $this->assertSame(50_000, $event->amount);
        $this->assertSame('VND', $event->currency);
        $this->assertSame($payload, $event->rawData);
    }

    public function testVerifyWebhookMapsResultCode1006ToCancelled(): void
    {
        $event = $this->makeGateway()->verifyWebhook(
            $this->buildWebhookPayload(['resultCode' => 1006, 'message' => 'Cancelled']),
            [],
        );
        $this->assertSame(PaymentStatus::CANCELLED, $event->status);
    }

    public function testVerifyWebhookMapsResultCode1005ToExpired(): void
    {
        $event = $this->makeGateway()->verifyWebhook(
            $this->buildWebhookPayload(['resultCode' => 1005, 'message' => 'Expired']),
            [],
        );
        $this->assertSame(PaymentStatus::EXPIRED, $event->status);
    }

    public function testVerifyWebhookMapsResultCode1001ToFailed(): void
    {
        $event = $this->makeGateway()->verifyWebhook(
            $this->buildWebhookPayload(['resultCode' => 1001, 'message' => 'Insufficient funds']),
            [],
        );
        $this->assertSame(PaymentStatus::FAILED, $event->status);
    }

    public function testVerifyWebhookThrowsOnTamperedSignature(): void
    {
        $payload = array_merge($this->buildWebhookPayload(), ['signature' => 'tampered_signature']);

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook($payload, []);
    }

    public function testVerifyWebhookThrowsWhenSignatureMissing(): void
    {
        $payload = $this->buildWebhookPayload();
        unset($payload['signature']);

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook($payload, []);
    }

    public function testVerifyWebhookThrowsForNullPayload(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook(null, []);
    }

    public function testVerifyWebhookThrowsForStringPayload(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook('string-payload', []);
    }

    // ─── getTransaction ───────────────────────────────────────────────────────

    public function testGetTransactionReturnsSuccessResult(): void
    {
        $response = [
            'partnerCode'  => self::PARTNER_CODE,
            'orderId'      => 'ORDER-002',
            'extraData'    => '',
            'amount'       => 50_000,
            'transId'      => 4111111111,
            'payType'      => 'qr',
            'resultCode'   => 0,
            'message'      => 'Successful.',
            'responseTime' => 1704067200000,
            'orderInfo'    => 'Test payment',
            'type'         => 1,
        ];

        $result = $this->makeGateway(fn() => $response)->getTransaction('4111111111', 'ORDER-002');

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('4111111111', $result->transactionId);
        $this->assertSame(50_000, $result->amount);
        $this->assertNotNull($result->rawResponse);
    }

    public function testGetTransactionReturnsFailedWithInsufficientFunds(): void
    {
        $response = [
            'partnerCode'  => self::PARTNER_CODE,
            'orderId'      => 'ORDER-002',
            'extraData'    => '',
            'amount'       => 50_000,
            'transId'      => 0,
            'payType'      => '',
            'resultCode'   => 1001,
            'message'      => 'Insufficient balance',
            'responseTime' => 1704067200000,
            'orderInfo'    => '',
            'type'         => 0,
        ];

        $result = $this->makeGateway(fn() => $response)->getTransaction('', 'ORDER-002');

        $this->assertFalse($result->success);
        $this->assertSame(ErrorCode::INSUFFICIENT_FUNDS, $result->error?->code);
    }

    public function testGetTransactionThrowsNetworkErrorAfterRetries(): void
    {
        $gw = new MoMoGateway(
            partnerCode: self::PARTNER_CODE,
            accessKey: self::ACCESS_KEY,
            secretKey: self::SECRET_KEY,
            sandbox: true,
            retries: 1,
            httpClient: function (): never {
                throw new PaymentSDKException(ErrorCode::NETWORK_ERROR, 'Connection refused');
            },
        );

        $this->expectException(PaymentSDKException::class);
        $gw->getTransaction('TXN', 'ORDER-002');
    }

    // ─── refund ───────────────────────────────────────────────────────────────

    public function testRefundReturnsSuccessResult(): void
    {
        $response = [
            'partnerCode'  => self::PARTNER_CODE,
            'orderId'      => 'ORDER-002',
            'requestId'    => 'ORDER-002_123',
            'amount'       => 50_000,
            'transId'      => 5999999999,
            'resultCode'   => 0,
            'message'      => 'Successful.',
            'responseTime' => 1704067200000,
        ];

        $result = $this->makeGateway(fn() => $response)->refund([
            'transactionId' => '4111111111',
            'orderId'       => 'ORDER-002',
            'amount'        => 50_000,
            'reason'        => 'Wrong item',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame(RefundStatus::SUCCESS, $result->status);
        $this->assertSame('5999999999', $result->refundId);
        $this->assertSame(50_000, $result->amount);
        $this->assertNull($result->error);
    }

    public function testRefundReturnsFailedForRejectedRefund(): void
    {
        $response = [
            'partnerCode'  => self::PARTNER_CODE,
            'orderId'      => 'ORDER-002',
            'amount'       => 50_000,
            'transId'      => 0,
            'resultCode'   => 1080,
            'message'      => 'Refund failed',
            'responseTime' => 1704067200000,
        ];

        $result = $this->makeGateway(fn() => $response)->refund([
            'transactionId' => '4111111111',
            'orderId'       => 'ORDER-002',
            'amount'        => 50_000,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(RefundStatus::FAILED, $result->status);
        $this->assertSame(ErrorCode::REFUND_FAILED, $result->error?->code);
        $this->assertNotNull($result->rawResponse);
    }

    public function testRefundThrowsWhenTransactionIdMissing(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->refund(['transactionId' => '', 'orderId' => 'ORD', 'amount' => 1000]);
    }

    public function testRefundThrowsWhenOrderIdMissing(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->refund(['transactionId' => 'TXN', 'orderId' => '', 'amount' => 1000]);
    }

    public function testRefundThrowsWhenAmountNegative(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->refund(['transactionId' => 'TXN', 'orderId' => 'ORD', 'amount' => -100]);
    }
}
