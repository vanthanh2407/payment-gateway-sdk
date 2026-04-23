import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createHmac } from 'node:crypto'
import { StripeGateway } from '../gateways/stripe.js'
import { PaymentStatus, RefundStatus, ErrorCode, WebhookEventType } from '../types.js'

// ─── Fixtures ─────────────────────────────────────────────────────────────────

const CONFIG = {
  secretKey: 'sk_test_abcdef1234567890abcdef1234567890',
  webhookSecret: 'whsec_test_abcdef1234567890abcdef1234567890',
  sandbox: true,
}

const PAYMENT_INPUT = {
  orderId: 'ORDER-STRIPE-001',
  amount: 100,           // USD → 10000 cents sent to Stripe
  currency: 'USD',
  description: 'Test Stripe payment',
  returnUrl: 'https://example.com/success',
  cancelUrl: 'https://example.com/cancel',
}

const PAYMENT_INPUT_VND = {
  orderId: 'ORDER-STRIPE-002',
  amount: 100_000,       // VND zero-decimal → 100000 sent as-is
  currency: 'VND',
  description: 'Test VND payment',
  returnUrl: 'https://example.com/success',
}

const SESSION_RESPONSE = {
  id: 'cs_test_stripe001',
  object: 'checkout.session' as const,
  url: 'https://checkout.stripe.com/c/pay/cs_test_stripe001',
  payment_intent: 'pi_test_stripe001',
  payment_status: 'unpaid',
  status: 'open',
  amount_total: 10000,
  currency: 'usd',
  metadata: { orderId: 'ORDER-STRIPE-001' },
}

const PAYMENT_INTENT_RESPONSE = {
  id: 'pi_test_stripe001',
  object: 'payment_intent' as const,
  amount: 10000,
  currency: 'usd',
  status: 'succeeded',
  description: 'Test payment',
  metadata: { orderId: 'ORDER-STRIPE-001' },
}

const WEBHOOK_TIMESTAMP = '1700000000'

/**
 * Build a Stripe-signed webhook fixture.
 * rawBody is the exact string that will be hashed — pass it through to verifyWebhook
 * so the signature always matches (even for the object payload path).
 */
function buildWebhookFixture(
  event: object,
  overrides: { timestamp?: string; secret?: string } = {},
) {
  const timestamp = overrides.timestamp ?? WEBHOOK_TIMESTAMP
  const secret = overrides.secret ?? CONFIG.webhookSecret
  const rawBody = JSON.stringify(event)
  const signedPayload = `${timestamp}.${rawBody}`
  const signature = createHmac('sha256', secret).update(signedPayload).digest('hex')
  return {
    rawBody,
    event,
    headers: { 'stripe-signature': `t=${timestamp},v1=${signature}` },
  }
}

function makePaymentIntentEvent(
  status: string,
  overrides: Partial<{ orderId: string; amount: number; currency: string }> = {},
) {
  return {
    id: 'evt_test_001',
    type: `payment_intent.${status === 'succeeded' ? 'succeeded' : status === 'canceled' ? 'canceled' : 'payment_failed'}`,
    data: {
      object: {
        id: 'pi_test_stripe001',
        object: 'payment_intent',
        amount: overrides.amount ?? 10000,
        currency: overrides.currency ?? 'usd',
        status,
        metadata: { orderId: overrides.orderId ?? PAYMENT_INPUT.orderId },
      },
    },
  }
}

// ─── constructor validation ───────────────────────────────────────────────────

describe('StripeGateway constructor', () => {
  it('throws INVALID_CONFIG when secretKey is missing', () => {
    expect(() => new StripeGateway({ ...CONFIG, secretKey: '' })).toThrow(
      expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }),
    )
  })

  it('throws INVALID_CONFIG when webhookSecret is missing', () => {
    expect(() => new StripeGateway({ ...CONFIG, webhookSecret: '' })).toThrow(
      expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }),
    )
  })

  it('constructs successfully with all required fields', () => {
    expect(() => new StripeGateway(CONFIG)).not.toThrow()
  })

  it('exposes correct capabilities', () => {
    const gw = new StripeGateway(CONFIG)
    expect(gw.capabilities.supportRefund).toBe(true)
    expect(gw.capabilities.supportPartialRefund).toBe(true)
    expect(gw.capabilities.supportRecurring).toBe(true)
    expect(gw.capabilities.supportWebhook).toBe(true)
    expect(gw.capabilities.supportQRCode).toBe(false)
    expect(gw.capabilities.currencies).toContain('USD')
    expect(gw.capabilities.currencies).toContain('VND')
    expect(gw.capabilities.paymentMethods).toContain('card')
  })

  it('has name "stripe"', () => {
    expect(new StripeGateway(CONFIG).name).toBe('stripe')
  })
})

// ─── createPayment ────────────────────────────────────────────────────────────

describe('StripeGateway.createPayment', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  function mockFetch(body: unknown, status = 200) {
    vi.mocked(fetch).mockResolvedValueOnce({
      ok: status >= 200 && status < 300,
      status,
      json: async () => body,
      text: async () => JSON.stringify(body),
    } as Response)
  }

  it('returns PENDING with paymentUrl and transactionId on success', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(SESSION_RESPONSE)

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(true)
    expect(result.paymentUrl).toBe(SESSION_RESPONSE.url)
    expect(result.transactionId).toBe(SESSION_RESPONSE.payment_intent)
    expect(result.orderId).toBe(PAYMENT_INPUT.orderId)
    expect(result.amount).toBe(PAYMENT_INPUT.amount)
    expect(result.currency).toBe('USD')
    expect(result.status).toBe(PaymentStatus.PENDING)
    expect(result.gateway).toBe('stripe')
    expect(result.rawResponse).toBeDefined()
    expect(result.error).toBeUndefined()
    expect(result.createdAt).toBeInstanceOf(Date)
  })

  it('converts USD amount to cents (×100) in the request body', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(SESSION_RESPONSE)

    await gw.createPayment(PAYMENT_INPUT)

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('line_items[0][price_data][unit_amount]')).toBe('10000')
    expect(body.get('line_items[0][price_data][currency]')).toBe('usd')
  })

  it('passes VND amount as-is (zero-decimal currency)', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch({ ...SESSION_RESPONSE, currency: 'vnd', amount_total: 100_000 })

    await gw.createPayment(PAYMENT_INPUT_VND)

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('line_items[0][price_data][unit_amount]')).toBe('100000')
    expect(body.get('line_items[0][price_data][currency]')).toBe('vnd')
  })

  it('includes orderId in session metadata', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(SESSION_RESPONSE)

    await gw.createPayment(PAYMENT_INPUT)

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('metadata[orderId]')).toBe(PAYMENT_INPUT.orderId)
  })

  it('sends Bearer Authorization header', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(SESSION_RESPONSE)

    await gw.createPayment(PAYMENT_INPUT)

    const headers = vi.mocked(fetch).mock.calls[0]?.[1]?.headers as Record<string, string>
    expect(headers?.['Authorization']).toBe(`Bearer ${CONFIG.secretKey}`)
  })

  it('sends form-encoded Content-Type', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(SESSION_RESPONSE)

    await gw.createPayment(PAYMENT_INPUT)

    const headers = vi.mocked(fetch).mock.calls[0]?.[1]?.headers as Record<string, string>
    expect(headers?.['Content-Type']).toBe('application/x-www-form-urlencoded')
  })

  it('passes cancelUrl when provided', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(SESSION_RESPONSE)

    await gw.createPayment(PAYMENT_INPUT)

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('cancel_url')).toBe(PAYMENT_INPUT.cancelUrl)
  })

  it('falls back to returnUrl as cancel_url when cancelUrl is not provided', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(SESSION_RESPONSE)
    const input = { ...PAYMENT_INPUT }
    const { cancelUrl: _removed, ...inputWithoutCancel } = input

    await gw.createPayment(inputWithoutCancel)

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('cancel_url')).toBe(PAYMENT_INPUT.returnUrl)
  })

  it('passes customer_email when customerInfo.email is set', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(SESSION_RESPONSE)

    await gw.createPayment({
      ...PAYMENT_INPUT,
      customerInfo: { email: 'test@example.com' },
    })

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('customer_email')).toBe('test@example.com')
  })

  it('omits paymentUrl when session url is null', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch({ ...SESSION_RESPONSE, url: null })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.paymentUrl).toBeUndefined()
  })

  it('omits transactionId when payment_intent is null', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch({ ...SESSION_RESPONSE, payment_intent: null })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.transactionId).toBeUndefined()
  })

  it('returns success=false with CARD_DECLINED for card_declined decline_code', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      {
        error: {
          type: 'card_error',
          code: 'card_declined',
          decline_code: 'card_declined',
          message: 'Your card was declined.',
        },
      },
      402,
    )

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.CARD_DECLINED)
    expect(result.error?.gatewayCode).toBe('card_declined')
    expect(result.error?.gatewayMessage).toBe('Your card was declined.')
    expect(result.rawResponse).toBeDefined()
    expect(result.status).toBe(PaymentStatus.FAILED)
  })

  it('maps insufficient_funds decline_code to INSUFFICIENT_FUNDS', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      {
        error: {
          type: 'card_error',
          code: 'card_declined',
          decline_code: 'insufficient_funds',
          message: 'Insufficient funds.',
        },
      },
      402,
    )

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.error?.code).toBe(ErrorCode.INSUFFICIENT_FUNDS)
  })

  it('maps lost_card decline_code to CARD_LOCKED', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      { error: { type: 'card_error', decline_code: 'lost_card', message: 'Lost card.' } },
      402,
    )

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.error?.code).toBe(ErrorCode.CARD_LOCKED)
  })

  it('maps authentication_required to AUTHENTICATION_FAILED', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      {
        error: {
          type: 'card_error',
          decline_code: 'authentication_required',
          message: '3DS required.',
        },
      },
      402,
    )

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.error?.code).toBe(ErrorCode.AUTHENTICATION_FAILED)
  })

  it('maps invalid_request_error type to INVALID_INPUT', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      {
        error: {
          type: 'invalid_request_error',
          code: 'parameter_missing',
          message: 'Missing required param.',
        },
      },
      400,
    )

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.error?.code).toBe(ErrorCode.INVALID_INPUT)
  })

  it('retries api_error responses and returns error after exhausting retries', async () => {
    const gw = new StripeGateway({ ...CONFIG, retries: 1 })
    const apiErr = { error: { type: 'api_error', message: 'Service unavailable' } }
    mockFetch(apiErr, 500)
    mockFetch(apiErr, 500)

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.GATEWAY_ERROR)
    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it('does NOT retry card_error (non-transient)', async () => {
    const gw = new StripeGateway({ ...CONFIG, retries: 2 })
    mockFetch(
      { error: { type: 'card_error', decline_code: 'card_declined', message: 'Declined.' } },
      402,
    )

    await gw.createPayment(PAYMENT_INPUT)

    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it('throws NETWORK_ERROR when fetch rejects', async () => {
    const gw = new StripeGateway({ ...CONFIG, retries: 0 })
    vi.mocked(fetch).mockRejectedValue(new Error('ECONNREFUSED'))

    await expect(gw.createPayment(PAYMENT_INPUT)).rejects.toMatchObject({
      code: ErrorCode.NETWORK_ERROR,
    })
  })

  it('throws TIMEOUT when fetch aborts', async () => {
    const gw = new StripeGateway({ ...CONFIG, retries: 0 })
    vi.mocked(fetch).mockRejectedValue(
      Object.assign(new Error('aborted'), { name: 'AbortError' }),
    )

    await expect(gw.createPayment(PAYMENT_INPUT)).rejects.toMatchObject({
      code: ErrorCode.TIMEOUT,
    })
  })

  it('throws INVALID_INPUT when orderId is missing', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, orderId: '' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is zero', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, amount: 0 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is negative', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, amount: -50 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when returnUrl is missing', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, returnUrl: '' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })
})

// ─── verifyWebhook ────────────────────────────────────────────────────────────

describe('StripeGateway.verifyWebhook', () => {
  it('verifies a payment_intent.succeeded event and returns PAYMENT_SUCCESS', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(rawBody, headers)

    expect(result.gateway).toBe('stripe')
    expect(result.eventType).toBe(WebhookEventType.PAYMENT_SUCCESS)
    expect(result.orderId).toBe(PAYMENT_INPUT.orderId)
    expect(result.transactionId).toBe('pi_test_stripe001')
    expect(result.amount).toBe(100)        // fromStripeAmount(10000, 'USD') = 100
    expect(result.currency).toBe('USD')
    expect(result.status).toBe(PaymentStatus.SUCCESS)
    expect(result.receivedAt).toBeInstanceOf(Date)
  })

  it('verifies a payment_intent.payment_failed event and returns PAYMENT_FAILED', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = {
      id: 'evt_002',
      type: 'payment_intent.payment_failed',
      data: {
        object: {
          id: 'pi_test_002',
          object: 'payment_intent',
          amount: 5000,
          currency: 'usd',
          status: 'requires_payment_method',
          metadata: { orderId: 'ORDER-002' },
        },
      },
    }
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(rawBody, headers)

    expect(result.eventType).toBe(WebhookEventType.PAYMENT_FAILED)
    expect(result.status).toBe(PaymentStatus.FAILED)
    expect(result.amount).toBe(50)  // 5000 cents → $50
  })

  it('verifies a payment_intent.canceled event and returns PAYMENT_CANCELLED', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('canceled')
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(rawBody, headers)

    expect(result.eventType).toBe(WebhookEventType.PAYMENT_CANCELLED)
    expect(result.status).toBe(PaymentStatus.CANCELLED)
  })

  it('verifies a checkout.session.completed event and returns PAYMENT_SUCCESS', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = {
      id: 'evt_003',
      type: 'checkout.session.completed',
      data: {
        object: {
          id: 'cs_test_001',
          object: 'checkout.session',
          payment_intent: 'pi_test_001',
          amount_total: 10000,
          currency: 'usd',
          payment_status: 'paid',
          status: 'complete',
          metadata: { orderId: PAYMENT_INPUT.orderId },
        },
      },
    }
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(rawBody, headers)

    expect(result.eventType).toBe(WebhookEventType.PAYMENT_SUCCESS)
    expect(result.status).toBe(PaymentStatus.SUCCESS)
    expect(result.transactionId).toBe('pi_test_001')
  })

  it('verifies a charge.refunded event and returns REFUND_SUCCESS', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = {
      id: 'evt_004',
      type: 'charge.refunded',
      data: {
        object: {
          id: 'ch_test_001',
          object: 'charge',
          payment_intent: 'pi_test_001',
          amount: 10000,
          currency: 'usd',
          metadata: { orderId: PAYMENT_INPUT.orderId },
        },
      },
    }
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(rawBody, headers)

    expect(result.eventType).toBe(WebhookEventType.REFUND_SUCCESS)
    expect(result.status).toBe(PaymentStatus.REFUNDED)
  })

  it('handles VND zero-decimal amounts without dividing by 100', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = {
      id: 'evt_005',
      type: 'payment_intent.succeeded',
      data: {
        object: {
          id: 'pi_vnd_001',
          object: 'payment_intent',
          amount: 100_000,
          currency: 'vnd',
          status: 'succeeded',
          metadata: { orderId: PAYMENT_INPUT_VND.orderId },
        },
      },
    }
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(rawBody, headers)

    expect(result.amount).toBe(100_000)  // VND: no conversion
    expect(result.currency).toBe('VND')
  })

  it('accepts a Buffer payload and verifies correctly', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(Buffer.from(rawBody, 'utf8'), headers)

    expect(result.eventType).toBe(WebhookEventType.PAYMENT_SUCCESS)
  })

  it('accepts a parsed object payload and verifies correctly when JSON.stringify matches', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    // Build fixture from the stringified object so signatures line up
    const rawBody = JSON.stringify(event)
    const timestamp = WEBHOOK_TIMESTAMP
    const sig = createHmac('sha256', CONFIG.webhookSecret)
      .update(`${timestamp}.${rawBody}`)
      .digest('hex')

    const result = await gw.verifyWebhook(event, {
      'stripe-signature': `t=${timestamp},v1=${sig}`,
    })

    expect(result.eventType).toBe(WebhookEventType.PAYMENT_SUCCESS)
  })

  it('accepts Stripe-Signature header with capital S', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(rawBody, {
      'Stripe-Signature': headers['stripe-signature']!,
    })

    expect(result.eventType).toBe(WebhookEventType.PAYMENT_SUCCESS)
  })

  it('accepts multiple v1 signatures and passes when one is valid', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    const { rawBody, headers } = buildWebhookFixture(event)
    const validSig = headers['stripe-signature']!.split('v1=')[1]!
    // Prepend a fake v1 signature
    const multiSig = `t=${WEBHOOK_TIMESTAMP},v1=${'a'.repeat(64)},v1=${validSig}`

    const result = await gw.verifyWebhook(rawBody, { 'stripe-signature': multiSig })

    expect(result.eventType).toBe(WebhookEventType.PAYMENT_SUCCESS)
  })

  it('throws INVALID_SIGNATURE when checksum is tampered', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    const { rawBody } = buildWebhookFixture(event)

    await expect(
      gw.verifyWebhook(rawBody, {
        'stripe-signature': `t=${WEBHOOK_TIMESTAMP},v1=${'a'.repeat(64)}`,
      }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_SIGNATURE })
  })

  it('throws INVALID_SIGNATURE when signed with wrong secret', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    const { rawBody, headers } = buildWebhookFixture(event, { secret: 'whsec_wrong_secret' })

    await expect(gw.verifyWebhook(rawBody, headers)).rejects.toMatchObject({
      code: ErrorCode.INVALID_SIGNATURE,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED when Stripe-Signature header is missing', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    const { rawBody } = buildWebhookFixture(event)

    await expect(gw.verifyWebhook(rawBody, {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED when header has no t= or v1=', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    const { rawBody } = buildWebhookFixture(event)

    await expect(
      gw.verifyWebhook(rawBody, { 'stripe-signature': 'garbage' }),
    ).rejects.toMatchObject({ code: ErrorCode.WEBHOOK_PROCESSING_FAILED })
  })

  it('throws WEBHOOK_PROCESSING_FAILED for null payload', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(gw.verifyWebhook(null, {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED for number payload', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(gw.verifyWebhook(42, {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })

  it('unknown event type maps to PAYMENT_FAILED event type', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = {
      id: 'evt_unknown',
      type: 'customer.created',
      data: {
        object: {
          id: 'cus_001',
          amount: 0,
          currency: 'usd',
          metadata: {},
        },
      },
    }
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(rawBody, headers)

    expect(result.eventType).toBe(WebhookEventType.PAYMENT_FAILED)
    expect(result.status).toBe(PaymentStatus.FAILED)
  })

  it('includes rawData in the returned event', async () => {
    const gw = new StripeGateway(CONFIG)
    const event = makePaymentIntentEvent('succeeded')
    const { rawBody, headers } = buildWebhookFixture(event)

    const result = await gw.verifyWebhook(rawBody, headers)

    expect(result.rawData).toMatchObject({ id: 'evt_test_001', type: 'payment_intent.succeeded' })
  })
})

// ─── getTransaction ───────────────────────────────────────────────────────────

describe('StripeGateway.getTransaction', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  function mockFetch(body: unknown, status = 200) {
    vi.mocked(fetch).mockResolvedValueOnce({
      ok: status >= 200 && status < 300,
      status,
      json: async () => body,
      text: async () => JSON.stringify(body),
    } as Response)
  }

  it('queries /payment_intents/:id for pi_ prefixed IDs', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(PAYMENT_INTENT_RESPONSE)

    await gw.getTransaction('pi_test_stripe001')

    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toContain('/payment_intents/pi_test_stripe001')
  })

  it('queries /checkout/sessions/:id for cs_ prefixed IDs', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch({ ...SESSION_RESPONSE, status: 'complete', payment_status: 'paid' })

    await gw.getTransaction('cs_test_stripe001')

    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toContain('/checkout/sessions/cs_test_stripe001')
  })

  it('returns SUCCESS for a succeeded PaymentIntent', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(PAYMENT_INTENT_RESPONSE)

    const result = await gw.getTransaction('pi_test_stripe001', 'ORDER-STRIPE-001')

    expect(result.success).toBe(true)
    expect(result.status).toBe(PaymentStatus.SUCCESS)
    expect(result.transactionId).toBe('pi_test_stripe001')
    expect(result.orderId).toBe('ORDER-STRIPE-001')
    expect(result.amount).toBe(100)           // fromStripeAmount(10000, 'usd') = 100
    expect(result.currency).toBe('USD')
    expect(result.gateway).toBe('stripe')
    expect(result.rawResponse).toBeDefined()
    expect(result.error).toBeUndefined()
  })

  it('maps PaymentIntent statuses correctly', async () => {
    const cases: Array<[string, PaymentStatus]> = [
      ['succeeded', PaymentStatus.SUCCESS],
      ['processing', PaymentStatus.PROCESSING],
      ['requires_action', PaymentStatus.PENDING],
      ['requires_payment_method', PaymentStatus.PENDING],
      ['canceled', PaymentStatus.CANCELLED],
    ]

    for (const [intentStatus, expectedStatus] of cases) {
      vi.mocked(fetch).mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({ ...PAYMENT_INTENT_RESPONSE, status: intentStatus }),
        text: async () => '',
      } as Response)

      const gw = new StripeGateway(CONFIG)
      const result = await gw.getTransaction('pi_test_stripe001')

      expect(result.status).toBe(expectedStatus)
    }
  })

  it('returns SUCCESS for a paid Checkout Session', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch({ ...SESSION_RESPONSE, status: 'complete', payment_status: 'paid' })

    const result = await gw.getTransaction('cs_test_stripe001')

    expect(result.success).toBe(true)
    expect(result.status).toBe(PaymentStatus.SUCCESS)
    expect(result.transactionId).toBe(SESSION_RESPONSE.payment_intent)
  })

  it('reads orderId from PaymentIntent metadata', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(PAYMENT_INTENT_RESPONSE)

    const result = await gw.getTransaction('pi_test_stripe001')

    expect(result.orderId).toBe('ORDER-STRIPE-001')
  })

  it('falls back to orderId param when metadata has no orderId', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch({ ...PAYMENT_INTENT_RESPONSE, metadata: {} })

    const result = await gw.getTransaction('pi_test_stripe001', 'FALLBACK-ORDER')

    expect(result.orderId).toBe('FALLBACK-ORDER')
  })

  it('returns TRANSACTION_NOT_FOUND for resource_missing error', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      { error: { type: 'invalid_request_error', code: 'resource_missing', message: 'No such payment_intent.' } },
      404,
    )

    const result = await gw.getTransaction('pi_nonexistent')

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.TRANSACTION_NOT_FOUND)
    expect(result.rawResponse).toBeDefined()
  })

  it('throws INVALID_INPUT when transactionId is empty', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(gw.getTransaction('')).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws NETWORK_ERROR when fetch rejects', async () => {
    const gw = new StripeGateway({ ...CONFIG, retries: 0 })
    vi.mocked(fetch).mockRejectedValue(new Error('Network failure'))

    await expect(gw.getTransaction('pi_test_001')).rejects.toMatchObject({
      code: ErrorCode.NETWORK_ERROR,
    })
  })

  it('throws TIMEOUT when request aborts', async () => {
    const gw = new StripeGateway({ ...CONFIG, retries: 0 })
    vi.mocked(fetch).mockRejectedValue(
      Object.assign(new Error('aborted'), { name: 'AbortError' }),
    )

    await expect(gw.getTransaction('pi_test_001')).rejects.toMatchObject({
      code: ErrorCode.TIMEOUT,
    })
  })

  it('sends GET request with Bearer auth', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(PAYMENT_INTENT_RESPONSE)

    await gw.getTransaction('pi_test_stripe001')

    const init = vi.mocked(fetch).mock.calls[0]?.[1]
    expect(init?.method).toBe('GET')
    const headers = init?.headers as Record<string, string>
    expect(headers?.['Authorization']).toBe(`Bearer ${CONFIG.secretKey}`)
  })
})

// ─── refund ───────────────────────────────────────────────────────────────────

describe('StripeGateway.refund', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  function mockFetch(body: unknown, status = 200) {
    vi.mocked(fetch).mockResolvedValueOnce({
      ok: status >= 200 && status < 300,
      status,
      json: async () => body,
      text: async () => JSON.stringify(body),
    } as Response)
  }

  const REFUND_INPUT = {
    transactionId: 'pi_test_stripe001',
    orderId: 'ORDER-STRIPE-001',
    amount: 10000,   // in Stripe's minor unit (cents)
    reason: 'Customer request',
  }

  const REFUND_RESPONSE = {
    id: 'ref_test_001',
    object: 'refund',
    amount: 10000,
    status: 'succeeded',
    payment_intent: 'pi_test_stripe001',
  }

  it('returns SUCCESS for a completed refund', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(REFUND_RESPONSE)

    const result = await gw.refund(REFUND_INPUT)

    expect(result.success).toBe(true)
    expect(result.status).toBe(RefundStatus.SUCCESS)
    expect(result.refundId).toBe('ref_test_001')
    expect(result.transactionId).toBe(REFUND_INPUT.transactionId)
    expect(result.orderId).toBe(REFUND_INPUT.orderId)
    expect(result.amount).toBe(REFUND_INPUT.amount)
    expect(result.rawResponse).toBeDefined()
    expect(result.error).toBeUndefined()
  })

  it('returns PENDING for a pending refund', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch({ ...REFUND_RESPONSE, status: 'pending' })

    const result = await gw.refund(REFUND_INPUT)

    expect(result.success).toBe(true)
    expect(result.status).toBe(RefundStatus.PENDING)
  })

  it('returns REJECTED for a canceled refund', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch({ ...REFUND_RESPONSE, status: 'canceled' })

    const result = await gw.refund(REFUND_INPUT)

    expect(result.success).toBe(false)
    expect(result.status).toBe(RefundStatus.REJECTED)
  })

  it('posts payment_intent and amount to /refunds', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(REFUND_RESPONSE)

    await gw.refund(REFUND_INPUT)

    const call = vi.mocked(fetch).mock.calls[0]
    expect(call?.[0]).toContain('/refunds')
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('payment_intent')).toBe(REFUND_INPUT.transactionId)
    expect(body.get('amount')).toBe(String(REFUND_INPUT.amount))
  })

  it('maps reason to requested_by_customer by default', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(REFUND_RESPONSE)

    await gw.refund({ ...REFUND_INPUT, reason: 'Wrong item sent' })

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('reason')).toBe('requested_by_customer')
    expect(body.get('metadata[reason]')).toBe('Wrong item sent')
  })

  it('maps "fraudulent" reason to Stripe fraudulent', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(REFUND_RESPONSE)

    await gw.refund({ ...REFUND_INPUT, reason: 'fraudulent' })

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('reason')).toBe('fraudulent')
  })

  it('maps "duplicate" reason to Stripe duplicate', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(REFUND_RESPONSE)

    await gw.refund({ ...REFUND_INPUT, reason: 'duplicate' })

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('reason')).toBe('duplicate')
  })

  it('omits reason from body when not provided', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(REFUND_RESPONSE)

    const { reason: _removed, ...inputWithoutReason } = REFUND_INPUT
    await gw.refund(inputWithoutReason)

    const call = vi.mocked(fetch).mock.calls[0]
    const body = new URLSearchParams(call?.[1]?.body as string)
    expect(body.get('reason')).toBeNull()
  })

  it('returns REFUND_ALREADY_PROCESSED for charge_already_refunded error', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      {
        error: {
          type: 'invalid_request_error',
          code: 'charge_already_refunded',
          message: 'The charge has already been refunded.',
        },
      },
      400,
    )

    const result = await gw.refund(REFUND_INPUT)

    expect(result.success).toBe(false)
    expect(result.status).toBe(RefundStatus.FAILED)
    expect(result.error?.code).toBe(ErrorCode.REFUND_ALREADY_PROCESSED)
    expect(result.error?.gatewayCode).toBe('charge_already_refunded')
    expect(result.rawResponse).toBeDefined()
  })

  it('returns REFUND_AMOUNT_EXCEEDED for charge_exceeds_source_amount error', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      {
        error: {
          type: 'invalid_request_error',
          code: 'charge_exceeds_source_amount',
          message: 'Refund amount exceeds the charge.',
        },
      },
      400,
    )

    const result = await gw.refund(REFUND_INPUT)

    expect(result.error?.code).toBe(ErrorCode.REFUND_AMOUNT_EXCEEDED)
  })

  it('returns REFUND_NOT_SUPPORTED for refund_not_supported error', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      {
        error: {
          type: 'invalid_request_error',
          code: 'refund_not_supported',
          message: 'This charge cannot be refunded.',
        },
      },
      400,
    )

    const result = await gw.refund(REFUND_INPUT)

    expect(result.error?.code).toBe(ErrorCode.REFUND_NOT_SUPPORTED)
  })

  it('returns TRANSACTION_NOT_FOUND for missing_charge error', async () => {
    const gw = new StripeGateway(CONFIG)
    mockFetch(
      {
        error: {
          type: 'invalid_request_error',
          code: 'missing_charge',
          message: 'No such payment_intent.',
        },
      },
      400,
    )

    const result = await gw.refund(REFUND_INPUT)

    expect(result.error?.code).toBe(ErrorCode.TRANSACTION_NOT_FOUND)
  })

  it('throws INVALID_INPUT when transactionId is missing', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(
      gw.refund({ ...REFUND_INPUT, transactionId: '' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when orderId is missing', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(
      gw.refund({ ...REFUND_INPUT, orderId: '' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is zero', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(
      gw.refund({ ...REFUND_INPUT, amount: 0 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is negative', async () => {
    const gw = new StripeGateway(CONFIG)
    await expect(
      gw.refund({ ...REFUND_INPUT, amount: -100 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws NETWORK_ERROR when fetch rejects', async () => {
    const gw = new StripeGateway({ ...CONFIG, retries: 0 })
    vi.mocked(fetch).mockRejectedValue(new Error('Connection refused'))

    await expect(gw.refund(REFUND_INPUT)).rejects.toMatchObject({
      code: ErrorCode.NETWORK_ERROR,
    })
  })
})
