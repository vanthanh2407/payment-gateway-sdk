import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ZaloPayGateway } from '../gateways/zalopay.js'
import { PaymentStatus, RefundStatus, ErrorCode } from '../types.js'
import { PaymentSDKError } from '../errors.js'
import { hmacSHA256 } from '../utils/crypto.js'

// ─── Fixtures ─────────────────────────────────────────────────────────────────

const CONFIG = {
  appId: 553,
  key1: 'sdngKKJmqEMzvh5QQcdD2A9XBSKUNaYn',
  key2: 'trMrHtvjo6myautxDUiAcYsVtaeQ8nhf',
  sandbox: true,
}

const PAYMENT_INPUT = {
  orderId: 'ORDER-ZP-001',
  amount: 100_000,
  currency: 'VND',
  description: 'Test ZaloPay payment',
  returnUrl: 'https://example.com/return',
  ipnUrl: 'https://example.com/ipn',
}

function buildWebhookPayload(overrides: Partial<{
  app_trans_id: string
  amount: number
  zp_trans_id: number
}> = {}) {
  const inner = {
    app_id: CONFIG.appId,
    app_trans_id: 'ORDER-ZP-001',
    app_time: 1704067200000,
    app_user: 'user',
    amount: 100_000,
    embed_data: '{"redirecturl":"https://example.com/return"}',
    item: '[]',
    zp_trans_id: 240101123456789,
    server_time: 1704067200500,
    channel: 38,
    merchant_user_id: '',
    ...overrides,
  }
  const dataStr = JSON.stringify(inner)
  const mac = hmacSHA256(dataStr, CONFIG.key2)
  return { data: dataStr, mac }
}

// ─── constructor validation ───────────────────────────────────────────────────

describe('ZaloPayGateway constructor', () => {
  it('throws INVALID_CONFIG when appId is 0', () => {
    expect(() =>
      new ZaloPayGateway({ appId: 0, key1: 'k1', key2: 'k2' }),
    ).toThrow(expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }))
  })

  it('throws INVALID_CONFIG when key1 is empty', () => {
    expect(() =>
      new ZaloPayGateway({ appId: 553, key1: '', key2: 'k2' }),
    ).toThrow(expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }))
  })

  it('throws INVALID_CONFIG when key2 is empty', () => {
    expect(() =>
      new ZaloPayGateway({ appId: 553, key1: 'k1', key2: '' }),
    ).toThrow(expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }))
  })
})

// ─── createPayment (mocked HTTP) ──────────────────────────────────────────────

describe('ZaloPayGateway.createPayment', () => {
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

  it('returns paymentUrl and PENDING status on return_code 1', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({
      return_code: 1,
      return_message: 'Success',
      order_url: 'https://sb-openapi.zalopay.vn/pay/order/abc123',
    })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(true)
    expect(result.paymentUrl).toBe('https://sb-openapi.zalopay.vn/pay/order/abc123')
    expect(result.orderId).toBe('ORDER-ZP-001')
    expect(result.amount).toBe(100_000)
    expect(result.currency).toBe('VND')
    expect(result.status).toBe(PaymentStatus.PENDING)
    expect(result.gateway).toBe('zalopay')
    expect(result.rawResponse).toBeDefined()
  })

  it('does not set paymentUrl when order_url is absent in response', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: 1, return_message: 'Success' })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(true)
    expect('paymentUrl' in result).toBe(false)
  })

  it('returns failure result for return_code -11 (DUPLICATE_ORDER)', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: -11, return_message: 'Already paid' })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.DUPLICATE_ORDER)
    expect(result.error?.gatewayCode).toBe('-11')
    expect(result.rawResponse).toBeDefined()
  })

  it('returns failure result for return_code -2 (INVALID_INPUT)', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: -2, return_message: 'Invalid parameters' })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.INVALID_INPUT)
  })

  it('rawResponse is present on failure', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    const rawBody = { return_code: -1, return_message: 'System error' }
    mockFetch(rawBody)

    const result = await gw.createPayment(PAYMENT_INPUT)
    expect(result.rawResponse).toEqual(rawBody)
  })

  it('uses sandbox URL when sandbox=true', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: 1, return_message: 'Success', order_url: 'https://example.com/pay' })

    await gw.createPayment(PAYMENT_INPUT)

    const fetchCall = vi.mocked(fetch).mock.calls[0]
    expect(fetchCall?.[0]).toContain('sb-openapi.zalopay.vn')
  })

  it('uses production URL when sandbox=false', async () => {
    const gw = new ZaloPayGateway({ ...CONFIG, sandbox: false })
    mockFetch({ return_code: 1, return_message: 'Success', order_url: 'https://example.com/pay' })

    await gw.createPayment(PAYMENT_INPUT)

    const fetchCall = vi.mocked(fetch).mock.calls[0]
    expect(fetchCall?.[0]).not.toContain('sb-openapi')
    expect(fetchCall?.[0]).toContain('openapi.zalopay.vn')
  })

  it('throws INVALID_INPUT when orderId is empty', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, orderId: '' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is negative', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, amount: -1 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT for non-VND currency', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, currency: 'USD' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })
})

// ─── verifyWebhook ────────────────────────────────────────────────────────────

describe('ZaloPayGateway.verifyWebhook', () => {
  it('verifies a valid webhook and returns correct WebhookEvent', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    const payload = buildWebhookPayload()
    const event = await gw.verifyWebhook(payload, {})

    expect(event.gateway).toBe('zalopay')
    expect(event.eventType).toBe('PAYMENT_SUCCESS')
    expect(event.orderId).toBe('ORDER-ZP-001')
    expect(event.transactionId).toBe('240101123456789')
    expect(event.amount).toBe(100_000)
    expect(event.currency).toBe('VND')
    expect(event.status).toBe(PaymentStatus.SUCCESS)
    expect(event.rawData).toEqual(payload)
  })

  it('sets transactionId from zp_trans_id', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    const payload = buildWebhookPayload({ zp_trans_id: 999888777666555 })
    const event = await gw.verifyWebhook(payload, {})
    expect(event.transactionId).toBe('999888777666555')
  })

  it('sets orderId from app_trans_id', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    const payload = buildWebhookPayload({ app_trans_id: '240423_CUSTOM-ORDER' })
    const event = await gw.verifyWebhook(payload, {})
    expect(event.orderId).toBe('240423_CUSTOM-ORDER')
  })

  it('throws INVALID_SIGNATURE when mac is tampered', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    const payload = { ...buildWebhookPayload(), mac: 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899' }
    await expect(gw.verifyWebhook(payload, {})).rejects.toMatchObject({
      code: ErrorCode.INVALID_SIGNATURE,
    })
  })

  it('throws INVALID_SIGNATURE when mac field is missing', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    const { mac: _removed, ...payloadWithoutMac } = buildWebhookPayload()
    await expect(gw.verifyWebhook(payloadWithoutMac, {})).rejects.toMatchObject({
      code: ErrorCode.INVALID_SIGNATURE,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED for non-object payload', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    await expect(gw.verifyWebhook(null, {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
    await expect(gw.verifyWebhook('string-payload', {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED when data field is not valid JSON', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    const badData = 'not-valid-json'
    const mac = hmacSHA256(badData, CONFIG.key2)
    await expect(gw.verifyWebhook({ data: badData, mac }, {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })
})

// ─── getTransaction (mocked HTTP) ─────────────────────────────────────────────

describe('ZaloPayGateway.getTransaction', () => {
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

  it('returns SUCCESS result for return_code 1', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({
      return_code: 1,
      return_message: 'Success',
      amount: 100_000,
      zp_trans_id: 240101123456789,
    })

    const result = await gw.getTransaction('240101_ORDER-ZP-001', 'ORDER-ZP-001')

    expect(result.success).toBe(true)
    expect(result.status).toBe(PaymentStatus.SUCCESS)
    expect(result.transactionId).toBe('240101123456789')
    expect(result.amount).toBe(100_000)
    expect(result.rawResponse).toBeDefined()
  })

  it('returns PROCESSING status for return_code 2', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: 2, return_message: 'Processing' })

    const result = await gw.getTransaction('240101_ORDER-ZP-001')

    expect(result.success).toBe(false)
    expect(result.status).toBe(PaymentStatus.PROCESSING)
    expect(result.error?.code).toBe(ErrorCode.GATEWAY_ERROR)
  })

  it('throws TRANSACTION_NOT_FOUND for return_code -49', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: -49, return_message: 'Transaction not found' })

    await expect(gw.getTransaction('240101_NONEXISTENT')).rejects.toMatchObject({
      code: ErrorCode.TRANSACTION_NOT_FOUND,
    })
  })

  it('throws TRANSACTION_NOT_FOUND for return_code -15', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: -15, return_message: 'Order not found' })

    await expect(gw.getTransaction('240101_NONEXISTENT')).rejects.toMatchObject({
      code: ErrorCode.TRANSACTION_NOT_FOUND,
    })
  })

  it('throws NETWORK_ERROR after exhausting retries', async () => {
    const gw = new ZaloPayGateway({ ...CONFIG, retries: 1 })
    vi.mocked(fetch).mockRejectedValue(new Error('Connection refused'))

    await expect(gw.getTransaction('240101_ORDER-ZP-001')).rejects.toMatchObject({
      code: ErrorCode.NETWORK_ERROR,
    })
    expect(fetch).toHaveBeenCalledTimes(2)
  })
})

// ─── refund (mocked HTTP) ─────────────────────────────────────────────────────

describe('ZaloPayGateway.refund', () => {
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
    transactionId: '240101123456789',
    orderId: 'ORDER-ZP-001',
    amount: 100_000,
    reason: 'Wrong item',
  }

  it('returns RefundStatus.SUCCESS with refundId on return_code 1', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: 1, return_message: 'Success', refund_id: 987654321 })

    const result = await gw.refund(REFUND_INPUT)

    expect(result.success).toBe(true)
    expect(result.status).toBe(RefundStatus.SUCCESS)
    expect(result.refundId).toBe('987654321')
    expect(result.transactionId).toBe('240101123456789')
    expect(result.orderId).toBe('ORDER-ZP-001')
    expect(result.amount).toBe(100_000)
    expect(result.error).toBeUndefined()
  })

  it('returns REFUND_ALREADY_PROCESSED for return_code -12', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: -12, return_message: 'Already refunded' })

    const result = await gw.refund(REFUND_INPUT)

    expect(result.success).toBe(false)
    expect(result.status).toBe(RefundStatus.FAILED)
    expect(result.error?.code).toBe(ErrorCode.REFUND_ALREADY_PROCESSED)
    expect(result.error?.gatewayCode).toBe('-12')
  })

  it('returns REFUND_AMOUNT_EXCEEDED for return_code -13', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    mockFetch({ return_code: -13, return_message: 'Amount exceeded' })

    const result = await gw.refund(REFUND_INPUT)

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.REFUND_AMOUNT_EXCEEDED)
  })

  it('rawResponse is present on both success and failure', async () => {
    const gw = new ZaloPayGateway(CONFIG)
    const successBody = { return_code: 1, return_message: 'Success', refund_id: 111 }
    const failBody = { return_code: -12, return_message: 'Already refunded' }

    mockFetch(successBody)
    const successResult = await gw.refund(REFUND_INPUT)
    expect(successResult.rawResponse).toEqual(successBody)

    mockFetch(failBody)
    const failResult = await gw.refund(REFUND_INPUT)
    expect(failResult.rawResponse).toEqual(failBody)
  })

  it('throws INVALID_INPUT for missing required fields', async () => {
    const gw = new ZaloPayGateway(CONFIG)

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
