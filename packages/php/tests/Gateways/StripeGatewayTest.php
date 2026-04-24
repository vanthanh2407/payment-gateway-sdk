<?php

declare(strict_types=1);

namespace PaymentSdk\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use PaymentSdk\Enums\ErrorCode;
use PaymentSdk\Enums\PaymentStatus;
use PaymentSdk\Enums\RefundStatus;
use PaymentSdk\Enums\WebhookEventType;
use PaymentSdk\Exceptions\PaymentSDKException;
use PaymentSdk\Gateways\StripeGateway;
use PaymentSdk\Utils\Crypto;

class StripeGatewayTest extends TestCase
{
    private const SECRET_KEY      = 'sk_test_abc123';
    private const WEBHOOK_SECRET  = 'whsec_test_secret';

    private const PAYMENT_INPUT = [
        'orderId'     => 'ORDER-STR-001',
        'amount'      => 100,          // USD cents already in minor unit
        'currency'    => 'USD',
        'description' => 'Test Stripe payment',
        'returnUrl'   => 'https://example.com/return',
    ];

    private function makeGateway(?callable $httpClient = null): StripeGateway
    {
        return new StripeGateway(
            secretKey: self::SECRET_KEY,
            webhookSecret: self::WEBHOOK_SECRET,
            sandbox: true,
            httpClient: $httpClient,
        );
    }

    /**
     * Build a valid Stripe-Signature header value.
     * Signature: HMAC-SHA256("{timestamp}.{payload}", webhookSecret)
     */
    private function buildStripeSignatureHeader(string $payload, string $timestamp = '1700000000'): string
    {
        $sig = Crypto::hmacSHA256("{$timestamp}.{$payload}", self::WEBHOOK_SECRET);
        return "t={$timestamp},v1={$sig}";
    }

    // ─── Constructor ─────────────────────────────────────────────────────────

    public function testConstructorThrowsOnMissingSecretKey(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('Stripe: secretKey is required');
        new StripeGateway(secretKey: '', webhookSecret: 'ws');
    }

    public function testConstructorThrowsOnMissingWebhookSecret(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('Stripe: webhookSecret is required');
        new StripeGateway(secretKey: 'sk', webhookSecret: '');
    }

    // ─── Capabilities ────────────────────────────────────────────────────────

    public function testGetName(): void
    {
        $this->assertSame('stripe', $this->makeGateway()->getName());
    }

    public function testCapabilities(): void
    {
        $cap = $this->makeGateway()->getCapabilities();
        $this->assertTrue($cap->supportRefund);
        $this->assertTrue($cap->supportPartialRefund);
        $this->assertTrue($cap->supportRecurring);
        $this->assertTrue($cap->supportWebhook);
        $this->assertFalse($cap->supportQRCode);
        $this->assertContains('USD', $cap->currencies);
        $this->assertContains('VND', $cap->currencies);
        $this->assertContains('card', $cap->paymentMethods);
    }

    // ─── createPayment ───────────────────────────────────────────────────────

    public function testCreatePaymentSuccess(): void
    {
        $httpClient = fn() => [
            'id'             => 'cs_test_abc',
            'object'         => 'checkout.session',
            'url'            => 'https://checkout.stripe.com/pay/cs_test_abc',
            'payment_intent' => 'pi_test_123',
            'payment_status' => 'unpaid',
            'status'         => 'open',
            'amount_total'   => 10000,
            'currency'       => 'usd',
            'metadata'       => ['orderId' => 'ORDER-STR-001'],
        ];

        $result = $this->makeGateway($httpClient)->createPayment(self::PAYMENT_INPUT);

        $this->assertTrue($result->success);
        $this->assertSame('ORDER-STR-001', $result->orderId);
        $this->assertSame(100, $result->amount);
        $this->assertSame('USD', $result->currency);
        $this->assertSame(PaymentStatus::PENDING, $result->status);
        $this->assertSame('stripe', $result->gateway);
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_abc', $result->paymentUrl);
        $this->assertSame('pi_test_123', $result->transactionId);
        $this->assertNull($result->error);
    }

    public function testCreatePaymentSendsFormEncodedBody(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            $capturedBody = $body;
            return [
                'id' => 'cs_test', 'object' => 'checkout.session',
                'url' => 'https://stripe.com', 'payment_intent' => null,
                'payment_status' => 'unpaid', 'status' => 'open',
                'amount_total' => 10000, 'currency' => 'usd', 'metadata' => [],
            ];
        };

        $this->makeGateway($httpClient)->createPayment(self::PAYMENT_INPUT);

        $this->assertSame('payment', $capturedBody['mode']);
        $this->assertSame('https://example.com/return', $capturedBody['success_url']);
        $this->assertSame('ORDER-STR-001', $capturedBody['metadata[orderId]']);
        $this->assertSame('usd', $capturedBody['line_items[0][price_data][currency]']);
    }

    public function testCreatePaymentConvertsNonZeroDecimalAmountToMinorUnits(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            $capturedBody = $body;
            return [
                'id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://x',
                'payment_intent' => null, 'payment_status' => 'unpaid',
                'status' => 'open', 'amount_total' => 50_00, 'currency' => 'usd', 'metadata' => [],
            ];
        };

        $this->makeGateway($httpClient)->createPayment(['orderId' => 'X', 'amount' => 50, 'currency' => 'USD', 'returnUrl' => 'https://x']);

        // 50 USD → 5000 cents
        $this->assertSame('5000', $capturedBody['line_items[0][price_data][unit_amount]']);
    }

    public function testCreatePaymentDoesNotMultiplyZeroDecimalCurrency(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            $capturedBody = $body;
            return [
                'id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://x',
                'payment_intent' => null, 'payment_status' => 'unpaid',
                'status' => 'open', 'amount_total' => 150_000, 'currency' => 'vnd', 'metadata' => [],
            ];
        };

        $this->makeGateway($httpClient)->createPayment(['orderId' => 'X', 'amount' => 150_000, 'currency' => 'VND', 'returnUrl' => 'https://x']);

        // VND is zero-decimal: 150000 stays as 150000
        $this->assertSame('150000', $capturedBody['line_items[0][price_data][unit_amount]']);
    }

    public function testCreatePaymentSendsExpiresAtWhenProvided(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            $capturedBody = $body;
            return [
                'id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://x',
                'payment_intent' => null, 'payment_status' => 'unpaid',
                'status' => 'open', 'amount_total' => 10000, 'currency' => 'usd', 'metadata' => [],
            ];
        };

        $expireAt = new \DateTimeImmutable('@1700100000');
        $this->makeGateway($httpClient)->createPayment(array_merge(self::PAYMENT_INPUT, ['expireAt' => $expireAt]));

        $this->assertSame('1700100000', $capturedBody['expires_at']);
    }

    public function testCreatePaymentFailureFromStripeError(): void
    {
        $httpClient = fn() => [
            'error' => [
                'type'    => 'card_error',
                'code'    => 'card_declined',
                'message' => 'Your card was declined.',
            ],
        ];

        $result = $this->makeGateway($httpClient)->createPayment(self::PAYMENT_INPUT);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatus::FAILED, $result->status);
        $this->assertNotNull($result->error);
        $this->assertSame(ErrorCode::CARD_DECLINED, $result->error->code);
        $this->assertSame('Your card was declined.', $result->error->message);
    }

    public function testCreatePaymentMapsDeclineCodeOverType(): void
    {
        $httpClient = fn() => [
            'error' => [
                'type'         => 'card_error',
                'decline_code' => 'insufficient_funds',
                'message'      => 'Insufficient funds.',
            ],
        ];

        $result = $this->makeGateway($httpClient)->createPayment(self::PAYMENT_INPUT);
        $this->assertSame(ErrorCode::INSUFFICIENT_FUNDS, $result->error->code);
    }

    public function testCreatePaymentRequiresOrderId(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('orderId is required');
        $this->makeGateway()->createPayment(['amount' => 100, 'returnUrl' => 'https://x']);
    }

    public function testCreatePaymentRequiresPositiveAmount(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('amount must be positive');
        $this->makeGateway()->createPayment(['orderId' => 'X', 'amount' => 0, 'returnUrl' => 'https://x']);
    }

    public function testCreatePaymentRequiresReturnUrl(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('returnUrl is required');
        $this->makeGateway()->createPayment(['orderId' => 'X', 'amount' => 100]);
    }

    public function testCreatePaymentSendsAuthorizationHeader(): void
    {
        $capturedHeaders = null;
        $httpClient      = function (string $url, array $body, array $headers) use (&$capturedHeaders): array {
            $capturedHeaders = $headers;
            return [
                'id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://x',
                'payment_intent' => null, 'payment_status' => 'unpaid',
                'status' => 'open', 'amount_total' => 0, 'currency' => 'usd', 'metadata' => [],
            ];
        };

        $this->makeGateway($httpClient)->createPayment(self::PAYMENT_INPUT);

        $this->assertSame('Bearer ' . self::SECRET_KEY, $capturedHeaders['Authorization']);
        $this->assertSame('2023-10-16', $capturedHeaders['Stripe-Version']);
    }

    // ─── verifyWebhook ───────────────────────────────────────────────────────

    public function testVerifyWebhookPaymentIntentSucceeded(): void
    {
        $event = [
            'id'   => 'evt_test',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id'             => 'pi_test',
                    'object'         => 'payment_intent',
                    'amount'         => 10000,
                    'currency'       => 'usd',
                    'status'         => 'succeeded',
                    'payment_intent' => null,
                    'metadata'       => ['orderId' => 'ORDER-STR-001'],
                ],
            ],
        ];
        $payload   = json_encode($event);
        $sigHeader = $this->buildStripeSignatureHeader($payload);

        $result = $this->makeGateway()->verifyWebhook($payload, ['stripe-signature' => $sigHeader]);

        $this->assertSame('stripe', $result->gateway);
        $this->assertSame(WebhookEventType::PAYMENT_SUCCESS, $result->eventType);
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('ORDER-STR-001', $result->orderId);
        $this->assertSame('pi_test', $result->transactionId);
        $this->assertSame(100, $result->amount);    // 10000 cents → 100 USD
        $this->assertSame('USD', $result->currency);
    }

    public function testVerifyWebhookCheckoutSessionCompleted(): void
    {
        $event = [
            'id'   => 'evt_test',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id'             => 'cs_test',
                    'object'         => 'checkout.session',
                    'amount_total'   => 20000,
                    'currency'       => 'usd',
                    'status'         => 'complete',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_test_456',
                    'metadata'       => ['orderId' => 'ORDER-STR-002'],
                ],
            ],
        ];
        $payload   = json_encode($event);
        $sigHeader = $this->buildStripeSignatureHeader($payload);

        $result = $this->makeGateway()->verifyWebhook($payload, ['stripe-signature' => $sigHeader]);

        $this->assertSame(WebhookEventType::PAYMENT_SUCCESS, $result->eventType);
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('ORDER-STR-002', $result->orderId);
        $this->assertSame('pi_test_456', $result->transactionId);
        $this->assertSame(200, $result->amount);    // 20000 cents → 200 USD
    }

    public function testVerifyWebhookVndAmountNotConverted(): void
    {
        $event = [
            'id'   => 'evt_test',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id'             => 'pi_vnd',
                    'object'         => 'payment_intent',
                    'amount'         => 150_000,
                    'currency'       => 'vnd',
                    'status'         => 'succeeded',
                    'metadata'       => ['orderId' => 'ORDER-VND'],
                ],
            ],
        ];
        $payload   = json_encode($event);
        $sigHeader = $this->buildStripeSignatureHeader($payload);

        $result = $this->makeGateway()->verifyWebhook($payload, ['stripe-signature' => $sigHeader]);
        $this->assertSame(150_000, $result->amount);   // VND is zero-decimal
    }

    public function testVerifyWebhookChargeRefunded(): void
    {
        $event = [
            'id'   => 'evt_test',
            'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_test', 'currency' => 'usd', 'amount' => 5000, 'metadata' => []]],
        ];
        $payload   = json_encode($event);
        $sigHeader = $this->buildStripeSignatureHeader($payload);

        $result = $this->makeGateway()->verifyWebhook($payload, ['stripe-signature' => $sigHeader]);
        $this->assertSame(WebhookEventType::REFUND_SUCCESS, $result->eventType);
        $this->assertSame(PaymentStatus::REFUNDED, $result->status);
    }

    public function testVerifyWebhookPaymentIntentCanceled(): void
    {
        $event = [
            'id'   => 'evt_test',
            'type' => 'payment_intent.canceled',
            'data' => ['object' => ['id' => 'pi_test', 'currency' => 'usd', 'amount' => 5000, 'metadata' => []]],
        ];
        $payload   = json_encode($event);
        $sigHeader = $this->buildStripeSignatureHeader($payload);

        $result = $this->makeGateway()->verifyWebhook($payload, ['stripe-signature' => $sigHeader]);
        $this->assertSame(WebhookEventType::PAYMENT_CANCELLED, $result->eventType);
        $this->assertSame(PaymentStatus::CANCELLED, $result->status);
    }

    public function testVerifyWebhookAcceptsArrayPayload(): void
    {
        $event = [
            'id'   => 'evt_arr',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_arr', 'currency' => 'usd', 'amount' => 5000, 'metadata' => []]],
        ];
        // Build sig from JSON-encoded version (same as gateway does for array payloads)
        $payload   = json_encode($event);
        $sigHeader = $this->buildStripeSignatureHeader($payload);

        $result = $this->makeGateway()->verifyWebhook($event, ['stripe-signature' => $sigHeader]);
        $this->assertSame(WebhookEventType::PAYMENT_SUCCESS, $result->eventType);
    }

    public function testVerifyWebhookThrowsOnInvalidSignature(): void
    {
        $payload   = json_encode(['id' => 'evt', 'type' => 'x', 'data' => ['object' => []]]);
        $sigHeader = 't=1700000000,v1=badhash000000000000000000000000000000000000000000000000000000000000';

        try {
            $this->makeGateway()->verifyWebhook($payload, ['stripe-signature' => $sigHeader]);
            $this->fail('Expected PaymentSDKException');
        } catch (PaymentSDKException $e) {
            $this->assertSame(ErrorCode::INVALID_SIGNATURE, $e->errorCode);
        }
    }

    public function testVerifyWebhookThrowsOnMissingSignatureHeader(): void
    {
        $payload = json_encode(['id' => 'evt', 'type' => 'x', 'data' => ['object' => []]]);
        try {
            $this->makeGateway()->verifyWebhook($payload, []);
            $this->fail('Expected PaymentSDKException');
        } catch (PaymentSDKException $e) {
            $this->assertSame(ErrorCode::WEBHOOK_PROCESSING_FAILED, $e->errorCode);
        }
    }

    public function testVerifyWebhookThrowsOnMalformedSignatureHeader(): void
    {
        $payload = json_encode(['id' => 'evt', 'type' => 'x', 'data' => ['object' => []]]);
        try {
            $this->makeGateway()->verifyWebhook($payload, ['stripe-signature' => 'invalid-no-equals']);
            $this->fail('Expected PaymentSDKException');
        } catch (PaymentSDKException $e) {
            $this->assertSame(ErrorCode::WEBHOOK_PROCESSING_FAILED, $e->errorCode);
        }
    }

    public function testVerifyWebhookThrowsOnNonStringNonArrayPayload(): void
    {
        try {
            $this->makeGateway()->verifyWebhook(12345, ['stripe-signature' => 't=1,v1=x']);
            $this->fail('Expected PaymentSDKException');
        } catch (PaymentSDKException $e) {
            $this->assertSame(ErrorCode::WEBHOOK_PROCESSING_FAILED, $e->errorCode);
        }
    }

    public function testVerifyWebhookUnknownEventTypeDefaultsToPaymentFailed(): void
    {
        $event = [
            'id'   => 'evt_unknown',
            'type' => 'some.unknown.event',
            'data' => ['object' => ['id' => 'obj_test', 'currency' => 'usd', 'amount' => 0, 'metadata' => []]],
        ];
        $payload   = json_encode($event);
        $sigHeader = $this->buildStripeSignatureHeader($payload);

        $result = $this->makeGateway()->verifyWebhook($payload, ['stripe-signature' => $sigHeader]);
        $this->assertSame(WebhookEventType::PAYMENT_FAILED, $result->eventType);
        $this->assertSame(PaymentStatus::FAILED, $result->status);
    }

    // ─── getTransaction ──────────────────────────────────────────────────────

    public function testGetTransactionPaymentIntentSuccess(): void
    {
        $httpClient = fn() => [
            'id'       => 'pi_test_123',
            'object'   => 'payment_intent',
            'amount'   => 10000,
            'currency' => 'usd',
            'status'   => 'succeeded',
            'metadata' => ['orderId' => 'ORDER-STR-001'],
        ];

        $result = $this->makeGateway($httpClient)->getTransaction('pi_test_123');

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('pi_test_123', $result->transactionId);
        $this->assertSame('ORDER-STR-001', $result->orderId);
        $this->assertSame(100, $result->amount);    // 10000 cents → 100 USD
        $this->assertSame('USD', $result->currency);
    }

    public function testGetTransactionRoutesCheckoutSessionById(): void
    {
        $capturedUrl = null;
        $httpClient  = function (string $url, array $body, array $headers) use (&$capturedUrl): array {
            $capturedUrl = $url;
            return [
                'id'             => 'cs_test_abc',
                'object'         => 'checkout.session',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test',
                'status'         => 'complete',
                'currency'       => 'usd',
                'amount_total'   => 10000,
                'metadata'       => ['orderId' => 'ORDER-STR-001'],
            ];
        };

        $this->makeGateway($httpClient)->getTransaction('cs_test_abc');
        $this->assertStringContainsString('/checkout/sessions/cs_test_abc', $capturedUrl);
    }

    public function testGetTransactionRoutesPaymentIntentById(): void
    {
        $capturedUrl = null;
        $httpClient  = function (string $url, array $body, array $headers) use (&$capturedUrl): array {
            $capturedUrl = $url;
            return [
                'id' => 'pi_test_123', 'object' => 'payment_intent',
                'amount' => 5000, 'currency' => 'usd', 'status' => 'succeeded', 'metadata' => [],
            ];
        };

        $this->makeGateway($httpClient)->getTransaction('pi_test_123');
        $this->assertStringContainsString('/payment_intents/pi_test_123', $capturedUrl);
    }

    public function testGetTransactionCheckoutSessionPaid(): void
    {
        $httpClient = fn() => [
            'id'             => 'cs_test_abc',
            'object'         => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_456',
            'status'         => 'complete',
            'currency'       => 'usd',
            'amount_total'   => 25000,
            'metadata'       => ['orderId' => 'ORDER-STR-002'],
        ];

        $result = $this->makeGateway($httpClient)->getTransaction('cs_test_abc', 'ORDER-STR-002');

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatus::SUCCESS, $result->status);
        $this->assertSame('pi_test_456', $result->transactionId);
        $this->assertSame(250, $result->amount);    // 25000 cents → 250 USD
    }

    public function testGetTransactionNotFound(): void
    {
        $httpClient = fn() => [
            'error' => [
                'type'    => 'invalid_request_error',
                'code'    => 'resource_missing',
                'message' => 'No such payment_intent: pi_unknown',
            ],
        ];

        $result = $this->makeGateway($httpClient)->getTransaction('pi_unknown');

        $this->assertFalse($result->success);
        $this->assertSame(ErrorCode::TRANSACTION_NOT_FOUND, $result->error->code);
        $this->assertSame('resource_missing', $result->error->gatewayCode);
    }

    public function testGetTransactionRequiresTransactionId(): void
    {
        $this->expectException(PaymentSDKException::class);
        $this->expectExceptionMessage('transactionId is required');
        $this->makeGateway()->getTransaction('');
    }

    public function testGetTransactionIntentProcessingStatus(): void
    {
        $httpClient = fn() => [
            'id' => 'pi_proc', 'object' => 'payment_intent',
            'amount' => 5000, 'currency' => 'usd', 'status' => 'processing', 'metadata' => [],
        ];

        $result = $this->makeGateway($httpClient)->getTransaction('pi_proc');
        $this->assertSame(PaymentStatus::PROCESSING, $result->status);
    }

    // ─── refund ──────────────────────────────────────────────────────────────

    public function testRefundSuccess(): void
    {
        $httpClient = fn() => [
            'id'             => 're_test_001',
            'object'         => 'refund',
            'amount'         => 10000,
            'status'         => 'succeeded',
            'payment_intent' => 'pi_test_123',
        ];

        $result = $this->makeGateway($httpClient)->refund([
            'transactionId' => 'pi_test_123',
            'orderId'       => 'ORDER-STR-001',
            'amount'        => 10000,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame(RefundStatus::SUCCESS, $result->status);
        $this->assertSame('re_test_001', $result->refundId);
        $this->assertSame('pi_test_123', $result->transactionId);
        $this->assertSame('ORDER-STR-001', $result->orderId);
        $this->assertNull($result->error);
    }

    public function testRefundPendingIsSuccess(): void
    {
        $httpClient = fn() => [
            'id' => 're_test', 'object' => 'refund',
            'amount' => 5000, 'status' => 'pending', 'payment_intent' => 'pi_test',
        ];

        $result = $this->makeGateway($httpClient)->refund([
            'transactionId' => 'pi_test',
            'orderId'       => 'ORDER-001',
            'amount'        => 5000,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame(RefundStatus::PENDING, $result->status);
    }

    public function testRefundCanceledIsRejected(): void
    {
        $httpClient = fn() => [
            'id' => 're_test', 'object' => 'refund',
            'amount' => 5000, 'status' => 'canceled', 'payment_intent' => 'pi_test',
        ];

        $result = $this->makeGateway($httpClient)->refund([
            'transactionId' => 'pi_test',
            'orderId'       => 'ORDER-001',
            'amount'        => 5000,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(RefundStatus::REJECTED, $result->status);
    }

    public function testRefundSendsReasonAsFormParam(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            $capturedBody = $body;
            return ['id' => 're_test', 'object' => 'refund', 'amount' => 100, 'status' => 'succeeded', 'payment_intent' => 'pi_test'];
        };

        $this->makeGateway($httpClient)->refund([
            'transactionId' => 'pi_test',
            'orderId'       => 'ORDER-001',
            'amount'        => 100,
            'reason'        => 'fraudulent',
        ]);

        $this->assertSame('fraudulent', $capturedBody['reason']);
        $this->assertSame('fraudulent', $capturedBody['metadata[reason]']);
    }

    public function testRefundMapsArbitraryReasonToRequestedByCustomer(): void
    {
        $capturedBody = null;
        $httpClient   = function (string $url, array $body, array $headers) use (&$capturedBody): array {
            $capturedBody = $body;
            return ['id' => 're_test', 'object' => 'refund', 'amount' => 100, 'status' => 'succeeded', 'payment_intent' => 'pi_test'];
        };

        $this->makeGateway($httpClient)->refund([
            'transactionId' => 'pi_test',
            'orderId'       => 'ORDER-001',
            'amount'        => 100,
            'reason'        => 'Customer changed mind',
        ]);

        $this->assertSame('requested_by_customer', $capturedBody['reason']);
        $this->assertSame('Customer changed mind', $capturedBody['metadata[reason]']);
    }

    public function testRefundAlreadyProcessed(): void
    {
        $httpClient = fn() => [
            'error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'Already refunded.'],
        ];

        $result = $this->makeGateway($httpClient)->refund([
            'transactionId' => 'pi_test', 'orderId' => 'ORDER', 'amount' => 100,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(ErrorCode::REFUND_ALREADY_PROCESSED, $result->error->code);
    }

    public function testRefundAmountExceeded(): void
    {
        $httpClient = fn() => [
            'error' => ['type' => 'invalid_request_error', 'code' => 'charge_exceeds_source_amount', 'message' => 'Amount too large.'],
        ];

        $result = $this->makeGateway($httpClient)->refund([
            'transactionId' => 'pi_test', 'orderId' => 'ORDER', 'amount' => 99999,
        ]);

        $this->assertSame(ErrorCode::REFUND_AMOUNT_EXCEEDED, $result->error->code);
    }

    public function testRefundNotSupported(): void
    {
        $httpClient = fn() => [
            'error' => ['type' => 'invalid_request_error', 'code' => 'refund_not_supported', 'message' => 'Refund not supported.'],
        ];

        $result = $this->makeGateway($httpClient)->refund([
            'transactionId' => 'pi_test', 'orderId' => 'ORDER', 'amount' => 100,
        ]);

        $this->assertSame(ErrorCode::REFUND_NOT_SUPPORTED, $result->error->code);
    }

    public function testRefundTransactionNotFound(): void
    {
        $httpClient = fn() => [
            'error' => ['type' => 'invalid_request_error', 'code' => 'missing_charge', 'message' => 'No such charge.'],
        ];

        $result = $this->makeGateway($httpClient)->refund([
            'transactionId' => 'pi_missing', 'orderId' => 'ORDER', 'amount' => 100,
        ]);

        $this->assertSame(ErrorCode::TRANSACTION_NOT_FOUND, $result->error->code);
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
}
