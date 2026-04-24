<?php

declare(strict_types=1);

namespace PaymentSdk\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use PaymentSdk\Enums\ErrorCode;
use PaymentSdk\Enums\PaymentStatus;
use PaymentSdk\Enums\RefundStatus;
use PaymentSdk\Enums\WebhookEventType;
use PaymentSdk\Exceptions\PaymentSDKException;
use PaymentSdk\Gateways\ZaloPayGateway;
use PaymentSdk\Utils\Crypto;

class ZaloPayGatewayTest extends TestCase
{
    private const APP_ID = 2553;
    private const KEY1   = 'PcY4iZIKFCIdgZvA6ueMcMHHUbRLYjPL';
    private const KEY2   = 'kLtgPl8HHhfvMuDHPwKfgfsY4Ydm9eIz';

    private const PAYMENT_INPUT = [
        'orderId'     => 'ORDER-003',
        'amount'      => 75_000,
        'currency'    => 'VND',
        'description' => 'Test ZaloPay payment',
        'returnUrl'   => 'https://example.com/return',
        'ipnUrl'      => 'https://example.com/ipn',
    ];

    private function makeGateway(?callable $httpClient = null): ZaloPayGateway
    {
        return new ZaloPayGateway(
            appId: self::APP_ID,
            key1: self::KEY1,
            key2: self::KEY2,
            sandbox: true,
            httpClient: $httpClient,
        );
    }

    /**
     * Build a valid ZaloPay webhook payload with correct HMAC-SHA256 mac.
     *
     * @param array<string, mixed> $dataOverrides
     * @return array<string, mixed>
     */
    private function buildWebhookPayload(array $dataOverrides = []): array
    {
        $data = array_merge([
            'app_id'          => self::APP_ID,
            'app_trans_id'    => '240101_ORDER-003',
            'app_time'        => 1704067200000,
            'app_user'        => 'user',
            'amount'          => 75_000,
            'embed_data'      => '{"redirecturl":"https://example.com/return"}',
            'item'            => '[]',
            'zp_trans_id'     => 240101000001,
            'server_time'     => 1704067201000,
            'channel'         => 38,
            'merchant_user_id' => 'user',
        ], $dataOverrides);

        $dataStr = (string) json_encode($data);
        $mac     = Crypto::hmacSHA256($dataStr, self::KEY2);

        return ['data' => $dataStr, 'mac' => $mac];
    }

    // ─── Constructor validation ───────────────────────────────────────────────

    public function testThrowsInvalidConfigWhenAppIdZero(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('appId is required');
        new ZaloPayGateway(0, self::KEY1, self::KEY2);
    }

    public function testThrowsInvalidConfigWhenKey1Empty(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('key1 is required');
        new ZaloPayGateway(self::APP_ID, '', self::KEY2);
    }

    public function testThrowsInvalidConfigWhenKey2Empty(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('key2 is required');
        new ZaloPayGateway(self::APP_ID, self::KEY1, '');
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
        $this->assertContains('qr', $caps->paymentMethods);
    }

    // ─── createPayment ────────────────────────────────────────────────────────

    public function testCreatePaymentReturnsPaymentUrlOnSuccess(): void
    {
        $response = [
            'return_code'    => 1,
            'return_message' => 'Success',
            'order_url'      => 'https://sb-openapi.zalopay.vn/pay/abc123',
            'zp_trans_token' => 'token_abc',
        ];

        $result = $this->makeGateway(fn() => $response)->createPayment(self::PAYMENT_INPUT);

        $this->assertTrue($result->success);
        $this->assertSame('https://sb-openapi.zalopay.vn/pay/abc123', $result->paymentUrl);
        $this->assertSame('ORDER-003', $result->orderId);
        $this->assertSame(75_000, $result->amount);
        $this->assertSame('VND', $result->currency);
        $this->assertSame(PaymentStatus::PENDING, $result->status);
        $this->assertSame('zalopay', $result->gateway);
        $this->assertNull($result->error);
    }

    public function testCreatePaymentReturnsFailureOnErrorReturnCode(): void
    {
        $response = [
            'return_code'    => -11,
            'return_message' => 'Duplicate order',
        ];

        $result = $this->makeGateway(fn() => $response)->createPayment(self::PAYMENT_INPUT);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatus::FAILED, $result->status);
        $this->assertSame(ErrorCode::DUPLICATE_ORDER, $result->error?->code);
        $this->assertSame('-11', $result->error?->gatewayCode);
    }

    public function testCreatePaymentUsesReturnCode2AsProcessing(): void
    {
        $response = ['return_code' => 2, 'return_message' => 'Processing'];

        $result = $this->makeGateway(fn() => $response)->createPayment(self::PAYMENT_INPUT);

        $this->assertSame(PaymentStatus::PROCESSING, $result->status);
    }

    public function testCreatePaymentUsesSandboxUrl(): void
    {
        $capturedUrl = null;
        $gw = $this->makeGateway(function (string $url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return ['return_code' => 1, 'return_message' => 'Success', 'order_url' => 'https://sb/pay'];
        });

        $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertStringContainsString('sb-openapi.zalopay.vn', (string) $capturedUrl);
    }

    public function testCreatePaymentUsesProductionUrl(): void
    {
        $capturedUrl = null;
        $gw = new ZaloPayGateway(
            appId: self::APP_ID,
            key1: self::KEY1,
            key2: self::KEY2,
            sandbox: false,
            httpClient: function (string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return ['return_code' => 1, 'return_message' => 'Success', 'order_url' => 'https://prod/pay'];
            },
        );

        $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertStringContainsString('openapi.zalopay.vn', (string) $capturedUrl);
        $this->assertStringNotContainsString('sb-', (string) $capturedUrl);
    }

    public function testCreatePaymentSendsSignedMac(): void
    {
        $capturedBody = null;
        $gw = $this->makeGateway(function (string $url, array $body) use (&$capturedBody) {
            $capturedBody = $body;
            return ['return_code' => 1, 'return_message' => 'Success', 'order_url' => 'https://sb/pay'];
        });

        $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertArrayHasKey('mac', (array) $capturedBody);
        $this->assertNotEmpty($capturedBody['mac']);
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

        $this->assertSame('zalopay', $event->gateway);
        $this->assertSame(WebhookEventType::PAYMENT_SUCCESS, $event->eventType);
        $this->assertSame('240101_ORDER-003', $event->orderId);
        $this->assertSame('240101000001', $event->transactionId);
        $this->assertSame(75_000, $event->amount);
        $this->assertSame('VND', $event->currency);
        $this->assertSame(PaymentStatus::SUCCESS, $event->status);
        $this->assertSame($payload, $event->rawData);
    }

    public function testVerifyWebhookThrowsOnTamperedMac(): void
    {
        $payload = array_merge($this->buildWebhookPayload(), ['mac' => 'tampered_mac']);

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook($payload, []);
    }

    public function testVerifyWebhookThrowsWhenDataFieldMissing(): void
    {
        $payload = $this->buildWebhookPayload();
        unset($payload['data']);

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook($payload, []);
    }

    public function testVerifyWebhookThrowsWhenMacFieldMissing(): void
    {
        $payload = $this->buildWebhookPayload();
        unset($payload['mac']);

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook($payload, []);
    }

    public function testVerifyWebhookThrowsForNullPayload(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook(null, []);
    }

    public function testVerifyWebhookThrowsWhenDataIsInvalidJson(): void
    {
        $invalidJson = 'not-json';
        $mac         = Crypto::hmacSHA256($invalidJson, self::KEY2);

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook(['data' => $invalidJson, 'mac' => $mac], []);
    }

    // ─── getTransaction ───────────────────────────────────────────────────────

    public function testGetTransactionReturnsSuccessResult(): void
    {
        $response = [
            'return_code'    => 1,
            'return_message' => 'Success',
            'amount'         => 75_000,
            'zp_trans_id'    => 240101000001,
        ];

        $result = $this->makeGateway(fn() => $response)->getTransaction('240101_ORDER-003', 'ORDER-003');

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('ORDER-003', $result->orderId);
        $this->assertSame(75_000, $result->amount);
        $this->assertSame('240101000001', $result->transactionId);
        $this->assertNull($result->error);
    }

    public function testGetTransactionFallsBackToTransactionIdAsOrderId(): void
    {
        $response = [
            'return_code'    => 1,
            'return_message' => 'Success',
            'amount'         => 75_000,
            'zp_trans_id'    => 240101000001,
        ];

        $result = $this->makeGateway(fn() => $response)->getTransaction('240101_ORDER-003');

        $this->assertSame('240101_ORDER-003', $result->orderId);
    }

    public function testGetTransactionReturnsFailedOnErrorReturnCode(): void
    {
        $response = [
            'return_code'    => -6,
            'return_message' => 'Transaction expired',
            'amount'         => 0,
        ];

        $result = $this->makeGateway(fn() => $response)->getTransaction('240101_ORDER-003', 'ORDER-003');

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatus::EXPIRED, $result->status);
        $this->assertSame(ErrorCode::PAYMENT_EXPIRED, $result->error?->code);
    }

    public function testGetTransactionThrowsTransactionNotFoundForCodeMinus15(): void
    {
        $response = ['return_code' => -15, 'return_message' => 'Not found'];

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway(fn() => $response)->getTransaction('MISSING');
    }

    public function testGetTransactionThrowsTransactionNotFoundForCodeMinus49(): void
    {
        $response = ['return_code' => -49, 'return_message' => 'Not found'];

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway(fn() => $response)->getTransaction('MISSING');
    }

    public function testGetTransactionThrowsNetworkErrorAfterRetries(): void
    {
        $gw = new ZaloPayGateway(
            appId: self::APP_ID,
            key1: self::KEY1,
            key2: self::KEY2,
            sandbox: true,
            retries: 1,
            httpClient: function (): never {
                throw new PaymentSDKException(ErrorCode::NETWORK_ERROR, 'Connection refused');
            },
        );

        $this->expectException(PaymentSDKException::class);
        $gw->getTransaction('240101_ORDER-003');
    }

    // ─── refund ───────────────────────────────────────────────────────────────

    public function testRefundReturnsSuccessResult(): void
    {
        $response = [
            'return_code'    => 1,
            'return_message' => 'Success',
            'refund_id'      => 99887766,
        ];

        $result = $this->makeGateway(fn() => $response)->refund([
            'transactionId' => '240101000001',
            'orderId'       => 'ORDER-003',
            'amount'        => 75_000,
            'reason'        => 'Wrong item',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame(RefundStatus::SUCCESS, $result->status);
        $this->assertSame('99887766', $result->refundId);
        $this->assertSame(75_000, $result->amount);
        $this->assertSame('ORDER-003', $result->orderId);
        $this->assertSame('240101000001', $result->transactionId);
        $this->assertNull($result->error);
    }

    public function testRefundReturnsFailedOnErrorReturnCode(): void
    {
        $response = [
            'return_code'    => -12,
            'return_message' => 'Refund already processed',
        ];

        $result = $this->makeGateway(fn() => $response)->refund([
            'transactionId' => '240101000001',
            'orderId'       => 'ORDER-003',
            'amount'        => 75_000,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(RefundStatus::FAILED, $result->status);
        $this->assertSame(ErrorCode::REFUND_ALREADY_PROCESSED, $result->error?->code);
        $this->assertSame('-12', $result->error?->gatewayCode);
    }

    public function testRefundReturnsFailedForAmountExceeded(): void
    {
        $response = ['return_code' => -13, 'return_message' => 'Refund amount exceeded'];

        $result = $this->makeGateway(fn() => $response)->refund([
            'transactionId' => '240101000001',
            'orderId'       => 'ORDER-003',
            'amount'        => 999_999,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(ErrorCode::REFUND_AMOUNT_EXCEEDED, $result->error?->code);
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
