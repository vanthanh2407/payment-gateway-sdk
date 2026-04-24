<?php

declare(strict_types=1);

namespace PaymentSdk\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use PaymentSdk\Enums\ErrorCode;
use PaymentSdk\Enums\PaymentStatus;
use PaymentSdk\Enums\RefundStatus;
use PaymentSdk\Enums\WebhookEventType;
use PaymentSdk\Exceptions\PaymentSDKException;
use PaymentSdk\Gateways\VNPayGateway;
use PaymentSdk\Utils\Crypto;

class VNPayGatewayTest extends TestCase
{
    private const TMN_CODE    = 'TESTCODE';
    private const HASH_SECRET = 'test_hash_secret_abcdef1234567890';

    private const PAYMENT_INPUT = [
        'orderId'     => 'ORDER-001',
        'amount'      => 100_000,
        'currency'    => 'VND',
        'description' => 'Test VNPay payment',
        'returnUrl'   => 'https://example.com/return',
    ];

    private function makeGateway(?callable $httpClient = null): VNPayGateway
    {
        return new VNPayGateway(
            tmnCode: self::TMN_CODE,
            hashSecret: self::HASH_SECRET,
            sandbox: true,
            httpClient: $httpClient,
        );
    }

    /**
     * Build a valid VNPay webhook payload with correct HMAC-SHA512 signature.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function buildWebhookPayload(array $overrides = []): array
    {
        $data = array_merge([
            'vnp_TmnCode'          => self::TMN_CODE,
            'vnp_Amount'           => '10000000',
            'vnp_BankCode'         => 'NCB',
            'vnp_BankTranNo'       => 'VNP12345',
            'vnp_CardType'         => 'ATM',
            'vnp_PayDate'          => '20240101120000',
            'vnp_OrderInfo'        => 'Test VNPay payment',
            'vnp_TransactionNo'    => '12345678',
            'vnp_ResponseCode'     => '00',
            'vnp_TransactionStatus' => '00',
            'vnp_TxnRef'           => 'ORDER-001',
            'vnp_SecureHashType'   => 'SHA512',
        ], $overrides);

        // Build sign params excluding hash fields
        $signParams = [];
        foreach ($data as $key => $val) {
            if ($key !== 'vnp_SecureHash' && $key !== 'vnp_SecureHashType') {
                $signParams[$key] = (string) $val;
            }
        }

        $queryString        = Crypto::buildSortedQueryString($signParams);
        $data['vnp_SecureHash'] = Crypto::hmacSHA512($queryString, self::HASH_SECRET);

        return $data;
    }

    // ─── Constructor validation ───────────────────────────────────────────────

    public function testThrowsInvalidConfigWhenTmnCodeEmpty(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('tmnCode is required');
        new VNPayGateway('', 'secret');
    }

    public function testThrowsInvalidConfigWhenHashSecretEmpty(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('hashSecret is required');
        new VNPayGateway('TESTCODE', '');
    }

    // ─── Capabilities ─────────────────────────────────────────────────────────

    public function testGetCapabilities(): void
    {
        $caps = $this->makeGateway()->getCapabilities();
        $this->assertTrue($caps->supportRefund);
        $this->assertFalse($caps->supportPartialRefund);
        $this->assertTrue($caps->supportWebhook);
        $this->assertTrue($caps->supportQRCode);
        $this->assertContains('VND', $caps->currencies);
        $this->assertContains('card', $caps->paymentMethods);
        $this->assertContains('banking', $caps->paymentMethods);
        $this->assertContains('qr', $caps->paymentMethods);
    }

    // ─── createPayment ────────────────────────────────────────────────────────

    public function testCreatePaymentReturnsPaymentUrl(): void
    {
        $result = $this->makeGateway()->createPayment(self::PAYMENT_INPUT);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->paymentUrl);
        $this->assertStringContainsString('sandbox.vnpayment.vn', (string) $result->paymentUrl);
        $this->assertStringContainsString('vnp_SecureHash=', (string) $result->paymentUrl);
        $this->assertSame('ORDER-001', $result->orderId);
        $this->assertSame(100_000, $result->amount);
        $this->assertSame('VND', $result->currency);
        $this->assertSame(PaymentStatus::PENDING, $result->status);
        $this->assertSame('vnpay', $result->gateway);
        $this->assertNull($result->error);
    }

    public function testCreatePaymentEncodeAmountTimesHundred(): void
    {
        $result = $this->makeGateway()->createPayment(self::PAYMENT_INPUT);

        $this->assertStringContainsString('vnp_Amount=10000000', (string) $result->paymentUrl);
    }

    public function testCreatePaymentUsesProductionUrl(): void
    {
        $gw     = new VNPayGateway(tmnCode: self::TMN_CODE, hashSecret: self::HASH_SECRET, sandbox: false);
        $result = $gw->createPayment(self::PAYMENT_INPUT);

        $this->assertStringContainsString('vnpayment.vn/paymentv2', (string) $result->paymentUrl);
        $this->assertStringNotContainsString('sandbox', (string) $result->paymentUrl);
    }

    public function testCreatePaymentIncludesExpireDateWhenProvided(): void
    {
        $expireAt = new \DateTimeImmutable('2024-01-02 12:00:00');
        $result   = $this->makeGateway()->createPayment(array_merge(self::PAYMENT_INPUT, ['expireAt' => $expireAt]));

        $this->assertStringContainsString('vnp_ExpireDate=', (string) $result->paymentUrl);
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

        $this->assertSame('vnpay', $event->gateway);
        $this->assertSame('ORDER-001', $event->orderId);
        $this->assertSame('12345678', $event->transactionId);
        $this->assertSame(PaymentStatus::SUCCESS, $event->status);
        $this->assertSame(WebhookEventType::PAYMENT_SUCCESS, $event->eventType);
        $this->assertSame(100_000, $event->amount);
        $this->assertSame('VND', $event->currency);
    }

    public function testVerifyWebhookMapsResponseCode24ToCancelled(): void
    {
        $event = $this->makeGateway()->verifyWebhook(
            $this->buildWebhookPayload(['vnp_ResponseCode' => '24', 'vnp_TransactionStatus' => '02']),
            [],
        );

        $this->assertSame(PaymentStatus::CANCELLED, $event->status);
        $this->assertSame(WebhookEventType::PAYMENT_CANCELLED, $event->eventType);
    }

    public function testVerifyWebhookMapsResponseCode11ToExpired(): void
    {
        $event = $this->makeGateway()->verifyWebhook(
            $this->buildWebhookPayload(['vnp_ResponseCode' => '11', 'vnp_TransactionStatus' => '02']),
            [],
        );

        $this->assertSame(PaymentStatus::EXPIRED, $event->status);
        $this->assertSame(WebhookEventType::PAYMENT_FAILED, $event->eventType);
    }

    public function testVerifyWebhookMapsFailedResponseCodeToFailed(): void
    {
        $event = $this->makeGateway()->verifyWebhook(
            $this->buildWebhookPayload(['vnp_ResponseCode' => '51', 'vnp_TransactionStatus' => '02']),
            [],
        );

        $this->assertSame(PaymentStatus::FAILED, $event->status);
        $this->assertSame(WebhookEventType::PAYMENT_FAILED, $event->eventType);
    }

    public function testVerifyWebhookThrowsOnTamperedSignature(): void
    {
        $payload = array_merge($this->buildWebhookPayload(), ['vnp_SecureHash' => 'tampered']);

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->verifyWebhook($payload, []);
    }

    public function testVerifyWebhookThrowsWhenSignatureMissing(): void
    {
        $payload = $this->buildWebhookPayload();
        unset($payload['vnp_SecureHash']);

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
            'vnp_ResponseCode'      => '00',
            'vnp_Message'           => 'Giao dich thanh cong',
            'vnp_TransactionStatus' => '00',
            'vnp_TxnRef'            => 'ORDER-001',
            'vnp_Amount'            => '10000000',
            'vnp_BankCode'          => 'NCB',
            'vnp_PayDate'           => '20240101120000',
            'vnp_TransactionNo'     => '12345678',
            'vnp_SecureHash'        => 'somehash',
        ];

        $result = $this->makeGateway(fn() => $response)->getTransaction('12345678', 'ORDER-001');

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('ORDER-001', $result->orderId);
        $this->assertSame(100_000, $result->amount);
        $this->assertSame('12345678', $result->transactionId);
        $this->assertNull($result->error);
    }

    public function testGetTransactionReturnsFailedOnErrorResponseCode(): void
    {
        $response = [
            'vnp_ResponseCode'      => '51',
            'vnp_Message'           => 'Insufficient funds',
            'vnp_TransactionStatus' => '02',
            'vnp_TxnRef'            => 'ORDER-001',
            'vnp_Amount'            => '10000000',
            'vnp_SecureHash'        => 'somehash',
        ];

        $result = $this->makeGateway(fn() => $response)->getTransaction('', 'ORDER-001');

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatus::FAILED, $result->status);
        $this->assertSame(ErrorCode::INSUFFICIENT_FUNDS, $result->error?->code);
        $this->assertSame('51', $result->error?->gatewayCode);
    }

    public function testGetTransactionThrowsTransactionNotFoundForCode91(): void
    {
        $response = [
            'vnp_ResponseCode' => '91',
            'vnp_Message'      => 'Transaction not found',
            'vnp_SecureHash'   => 'somehash',
        ];

        $this->expectException(PaymentSDKException::class);
        $this->makeGateway(fn() => $response)->getTransaction('MISSING', 'ORDER-001');
    }

    public function testGetTransactionThrowsWhenOrderIdMissing(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->makeGateway()->getTransaction('TXN', null);
    }

    public function testGetTransactionUsesApiSandboxUrl(): void
    {
        $capturedUrl = null;
        $gw = $this->makeGateway(function (string $url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return [
                'vnp_ResponseCode'      => '00',
                'vnp_TransactionStatus' => '00',
                'vnp_TxnRef'            => 'ORDER-001',
                'vnp_Amount'            => '10000000',
                'vnp_TransactionNo'     => '12345678',
                'vnp_Message'           => 'Ok',
                'vnp_SecureHash'        => 'hash',
            ];
        });

        $gw->getTransaction('12345678', 'ORDER-001');

        $this->assertStringContainsString('sandbox.vnpayment.vn', (string) $capturedUrl);
    }

    public function testGetTransactionThrowsNetworkErrorAfterRetries(): void
    {
        $gw = new VNPayGateway(
            tmnCode: self::TMN_CODE,
            hashSecret: self::HASH_SECRET,
            sandbox: true,
            retries: 1,
            httpClient: function (): never {
                throw new PaymentSDKException(ErrorCode::NETWORK_ERROR, 'Connection refused');
            },
        );

        $this->expectException(PaymentSDKException::class);
        $gw->getTransaction('TXN', 'ORDER-001');
    }

    // ─── refund ───────────────────────────────────────────────────────────────

    public function testRefundReturnsSuccessResult(): void
    {
        $response = [
            'vnp_ResponseCode'      => '00',
            'vnp_Message'           => 'Giao dich thanh cong',
            'vnp_TransactionStatus' => '00',
            'vnp_TxnRef'            => 'ORDER-001',
            'vnp_Amount'            => '10000000',
            'vnp_TransactionNo'     => '99999999',
            'vnp_SecureHash'        => 'somehash',
        ];

        $result = $this->makeGateway(fn() => $response)->refund([
            'transactionId' => '12345678',
            'orderId'       => 'ORDER-001',
            'amount'        => 100_000,
            'reason'        => 'Customer request',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame(RefundStatus::SUCCESS, $result->status);
        $this->assertSame('99999999', $result->refundId);
        $this->assertSame(100_000, $result->amount);
        $this->assertSame('ORDER-001', $result->orderId);
        $this->assertSame('12345678', $result->transactionId);
        $this->assertNull($result->error);
    }

    public function testRefundReturnsFailedOnErrorResponseCode(): void
    {
        $response = [
            'vnp_ResponseCode'      => '99',
            'vnp_Message'           => 'Refund failed',
            'vnp_TransactionStatus' => '02',
            'vnp_TxnRef'            => 'ORDER-001',
            'vnp_Amount'            => '10000000',
            'vnp_SecureHash'        => 'somehash',
        ];

        $result = $this->makeGateway(fn() => $response)->refund([
            'transactionId' => '12345678',
            'orderId'       => 'ORDER-001',
            'amount'        => 100_000,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(RefundStatus::FAILED, $result->status);
        $this->assertSame(ErrorCode::UNKNOWN_ERROR, $result->error?->code);
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
