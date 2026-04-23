import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { MoMoGateway } from '../gateways/momo.js'
import { PaymentStatus, RefundStatus, ErrorCode } from '../types.js'
import { PaymentSDKError } from '../errors.js'
import { hmacSHA256, buildRawString } from '../utils/crypto.js'

// ─── Fixtures ─────────────────────────────────────────────────────────────────

const CONFIG = {
  partnerCode: 'MOMO_PARTNER',
  accessKey: 'test_access_key',
  secretKey: 'test_secret_key_abcdef1234567890',
  sandbox: true,
}

const PAYMENT_INPUT = {
  orderId: 'ORDER-002',
  amount: 50_000,
  currency: 'VND',
  description: 'Test MoMo payment',
  returnUrl: 'https://example.com/return',
  ipnUrl: 'https://example.com/ipn',
}

function buildWebhookPayload(overrides: Partial<{
  resultCode: number
  orderId: string
  transId: number
  amount: number
}> = {}) {
  const data = {
    partnerCode: CONFIG.partnerCode,
    orderId: 'ORDER-002',
    requestId: 'ORDER-002_1234567890',
    amount: 50_000,
    orderInfo: 'Test MoMo payment',
    orderType: 'momo_wallet',
    transId: 4111111111,
    resultCode: 0,
    message: 'Successful.',
    payType: 'qr',
    responseTime: 1704067200000,
    extraData: '',
    ...overrides,
  }

  // Build signature exactly as verifyWebhook expects
  const rawHashKeys = [
    'accessKey', 'amount', 'extraData', 'message', 'orderId',
    'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId',
    'responseTime', 'resultCode', 'transId',
  ] as const

  const signParams: Record<string, string> = {
    accessKey: CONFIG.accessKey,
    amount: String(data.amount),
    extraData: data.extraData,
    message: data.message,
    orderId: data.orderId,
    orderInfo: data.orderInfo,
    orderType: data.orderType,
    partnerCode: data.partnerCode,
    payType: data.payType,
    requestId: data.requestId,
    responseTime: String(data.responseTime),
    resultCode: String(data.resultCode),
    transId: String(data.transId),
  }

  const rawHash = buildRawString(signParams, [...rawHashKeys])
  return { ...data, signature: hmacSHA256(rawHash, CONFIG.secretKey) }
}

// ─── createPayment (mocked HTTP) ──────────────────────────────────────────────

describe('MoMoGateway.createPayment', () => {
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

  it('returns paymentUrl on success', async () => {
    const gw = new MoMoGateway(CONFIG)
    mockFetch({
      partnerCode: CONFIG.partnerCode,
      requestId: 'ORDER-002_123',
      orderId: 'ORDER-002',
      amount: 50000,
      responseTime: 1704067200000,
      message: 'Successful.',
      resultCode: 0,
      payUrl: 'https://test-payment.momo.vn/pay/abc123',
      deeplink: 'momo://pay?action=payWithApp&...',
      qrCodeUrl: 'https://test-payment.momo.vn/qr/abc123',
    })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(true)
    expect(result.paymentUrl).toBe('https://test-payment.momo.vn/pay/abc123')
    expect(result.orderId).toBe('ORDER-002')
    expect(result.amount).toBe(50_000)
    expect(result.currency).toBe('VND')
    expect(result.status).toBe(PaymentStatus.PENDING)
    expect(result.gateway).toBe('momo')
    expect(result.rawResponse).toBeDefined()
  })

  it('returns failure result when MoMo returns non-zero resultCode', async () => {
    const gw = new MoMoGateway(CONFIG)
    mockFetch({
      partnerCode: CONFIG.partnerCode,
      requestId: 'ORDER-002_123',
      orderId: 'ORDER-002',
      amount: 50000,
      responseTime: 1704067200000,
      message: 'Duplicate orderId',
      resultCode: 9001,
    })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.DUPLICATE_ORDER)
    expect(result.error?.gatewayCode).toBe('9001')
    expect(result.rawResponse).toBeDefined()
  })

  it('uses sandbox URL when sandbox=true', async () => {
    const gw = new MoMoGateway(CONFIG)
    mockFetch({ resultCode: 0, payUrl: 'https://test-payment.momo.vn/pay/x' })

    await gw.createPayment(PAYMENT_INPUT)

    const fetchCall = vi.mocked(fetch).mock.calls[0]
    expect(fetchCall?.[0]).toContain('test-payment.momo.vn')
  })

  it('uses production URL when sandbox=false', async () => {
    const gw = new MoMoGateway({ ...CONFIG, sandbox: false })
    mockFetch({ resultCode: 0, payUrl: 'https://payment.momo.vn/pay/x' })

    await gw.createPayment(PAYMENT_INPUT)

    const fetchCall = vi.mocked(fetch).mock.calls[0]
    expect(fetchCall?.[0]).not.toContain('test-payment')
    expect(fetchCall?.[0]).toContain('payment.momo.vn')
  })

  it('sends metadata as base64-encoded extraData', async () => {
    const gw = new MoMoGateway(CONFIG)
    mockFetch({ resultCode: 0, payUrl: 'https://test-payment.momo.vn/pay/x' })

    const metadata = { userId: 42, ref: 'campaign-spring' }
    await gw.createPayment({ ...PAYMENT_INPUT, metadata })

    const fetchCall = vi.mocked(fetch).mock.calls[0]
    const body = JSON.parse(fetchCall?.[1]?.body as string)
    const decoded = JSON.parse(Buffer.from(body.extraData, 'base64').toString())
    expect(decoded).toEqual(metadata)
  })

  it('throws INVALID_INPUT when orderId is missing', async () => {
    const gw = new MoMoGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, orderId: '' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is negative', async () => {
    const gw = new MoMoGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, amount: -1 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT for non-VND currency', async () => {
    const gw = new MoMoGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, currency: 'USD' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })
})

// ─── constructor validation ───────────────────────────────────────────────────

describe('MoMoGateway constructor', () => {
  it('throws INVALID_CONFIG when partnerCode is missing', () => {
    expect(() =>
      new MoMoGateway({ partnerCode: '', accessKey: 'a', secretKey: 'b' }),
    ).toThrow(expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }))
  })

  it('throws INVALID_CONFIG when accessKey is missing', () => {
    expect(() =>
      new MoMoGateway({ partnerCode: 'p', accessKey: '', secretKey: 'b' }),
    ).toThrow(expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }))
  })

  it('throws INVALID_CONFIG when secretKey is missing', () => {
    expect(() =>
      new MoMoGateway({ partnerCode: 'p', accessKey: 'a', secretKey: '' }),
    ).toThrow(expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }))
  })
})

// ─── verifyWebhook ────────────────────────────────────────────────────────────

describe('MoMoGateway.verifyWebhook', () => {
  it('verifies a valid successful webhook', async () => {
    const gw = new MoMoGateway(CONFIG)
    const payload = buildWebhookPayload()
    const event = await gw.verifyWebhook(payload, {})

    expect(event.gateway).toBe('momo')
    expect(event.orderId).toBe('ORDER-002')
    expect(event.transactionId).toBe('4111111111')
    expect(event.status).toBe(PaymentStatus.SUCCESS)
    expect(event.amount).toBe(50_000)
    expect(event.currency).toBe('VND')
    expect(event.rawData).toEqual(payload)
  })

  it('maps resultCode=1006 to CANCELLED status', async () => {
    const gw = new MoMoGateway(CONFIG)
    const payload = buildWebhookPayload({ resultCode: 1006 })
    const event = await gw.verifyWebhook(payload, {})
    expect(event.status).toBe(PaymentStatus.CANCELLED)
  })

  it('maps resultCode=1005 to EXPIRED status', async () => {
    const gw = new MoMoGateway(CONFIG)
    const payload = buildWebhookPayload({ resultCode: 1005 })
    const event = await gw.verifyWebhook(payload, {})
    expect(event.status).toBe(PaymentStatus.EXPIRED)
  })

  it('maps resultCode=1001 to FAILED status', async () => {
    const gw = new MoMoGateway(CONFIG)
    const payload = buildWebhookPayload({ resultCode: 1001 })
    const event = await gw.verifyWebhook(payload, {})
    expect(event.status).toBe(PaymentStatus.FAILED)
  })

  it('throws INVALID_SIGNATURE when signature is tampered', async () => {
    const gw = new MoMoGateway(CONFIG)
    const payload = { ...buildWebhookPayload(), signature: 'tampered_signature' }
    await expect(gw.verifyWebhook(payload, {})).rejects.toMatchObject({
      code: ErrorCode.INVALID_SIGNATURE,
    })
  })

  it('throws INVALID_SIGNATURE when signature field is missing', async () => {
    const gw = new MoMoGateway(CONFIG)
    const { signature: _removed, ...payloadWithoutSig } = buildWebhookPayload()
    await expect(gw.verifyWebhook(payloadWithoutSig, {})).rejects.toMatchObject({
      code: ErrorCode.INVALID_SIGNATURE,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED for non-object payload', async () => {
    const gw = new MoMoGateway(CONFIG)
    await expect(gw.verifyWebhook(null, {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
    await expect(gw.verifyWebhook('string-payload', {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })
})

// ─── getTransaction (mocked HTTP) ─────────────────────────────────────────────

describe('MoMoGateway.getTransaction', () => {
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

  it('returns SUCCESS result for a completed transaction', async () => {
    const gw = new MoMoGateway(CONFIG)
    mockFetch({
      partnerCode: CONFIG.partnerCode,
      requestId: 'ORDER-002_123',
      orderId: 'ORDER-002',
      extraData: '',
      amount: 50000,
      transId: 4111111111,
      payType: 'qr',
      resultCode: 0,
      message: 'Successful.',
      responseTime: 1704067200000,
      orderInfo: 'Test payment',
      type: 1,
    })

    const result = await gw.getTransaction('4111111111', 'ORDER-002')
    expect(result.success).toBe(true)
    expect(result.status).toBe(PaymentStatus.SUCCESS)
    expect(result.transactionId).toBe('4111111111')
    expect(result.amount).toBe(50_000)
    expect(result.rawResponse).toBeDefined()
  })

  it('returns FAILED with INSUFFICIENT_FUNDS error for resultCode 1001', async () => {
    const gw = new MoMoGateway(CONFIG)
    mockFetch({
      partnerCode: CONFIG.partnerCode,
      orderId: 'ORDER-002',
      extraData: '',
      amount: 50000,
      transId: 0,
      payType: '',
      resultCode: 1001,
      message: 'Insufficient balance',
      responseTime: 1704067200000,
      orderInfo: '',
      type: 0,
    })

    const result = await gw.getTransaction('', 'ORDER-002')
    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.INSUFFICIENT_FUNDS)
  })

  it('throws NETWORK_ERROR after exhausting retries', async () => {
    const gw = new MoMoGateway({ ...CONFIG, retries: 1 })
    vi.mocked(fetch).mockRejectedValue(new Error('Connection refused'))

    await expect(gw.getTransaction('TXN', 'ORDER-002')).rejects.toMatchObject({
      code: ErrorCode.NETWORK_ERROR,
    })
    expect(fetch).toHaveBeenCalledTimes(2)
  })
})

// ─── refund (mocked HTTP) ─────────────────────────────────────────────────────

describe('MoMoGateway.refund', () => {
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

  it('returns SUCCESS for a completed refund', async () => {
    const gw = new MoMoGateway(CONFIG)
    mockFetch({
      partnerCode: CONFIG.partnerCode,
      orderId: 'ORDER-002',
      requestId: 'ORDER-002_123',
      amount: 50000,
      transId: 5999999999,
      resultCode: 0,
      message: 'Successful.',
      responseTime: 1704067200000,
    })

    const result = await gw.refund({
      transactionId: '4111111111',
      orderId: 'ORDER-002',
      amount: 50_000,
      reason: 'Wrong item',
    })

    expect(result.success).toBe(true)
    expect(result.status).toBe(RefundStatus.SUCCESS)
    expect(result.refundId).toBe('5999999999')
    expect(result.amount).toBe(50_000)
    expect(result.error).toBeUndefined()
  })

  it('returns FAILED with error for rejected refund', async () => {
    const gw = new MoMoGateway(CONFIG)
    mockFetch({
      partnerCode: CONFIG.partnerCode,
      orderId: 'ORDER-002',
      requestId: 'ORDER-002_123',
      amount: 50000,
      transId: 0,
      resultCode: 1080,
      message: 'Refund failed',
      responseTime: 1704067200000,
    })

    const result = await gw.refund({
      transactionId: '4111111111',
      orderId: 'ORDER-002',
      amount: 50_000,
    })

    expect(result.success).toBe(false)
    expect(result.status).toBe(RefundStatus.FAILED)
    expect(result.error?.code).toBe(ErrorCode.REFUND_FAILED)
    expect(result.rawResponse).toBeDefined()
  })

  it('throws INVALID_INPUT for missing required fields', async () => {
    const gw = new MoMoGateway(CONFIG)

    await expect(
      gw.refund({ transactionId: '', orderId: 'ORD', amount: 1000 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })

    await expect(
      gw.refund({ transactionId: 'TXN', orderId: '', amount: 1000 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })

    await expect(
      gw.refund({ transactionId: 'TXN', orderId: 'ORD', amount: -100 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })
})
