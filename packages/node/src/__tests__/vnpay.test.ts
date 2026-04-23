import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { VNPayGateway } from '../gateways/vnpay.js'
import { PaymentStatus, RefundStatus, ErrorCode } from '../types.js'
import { PaymentSDKError } from '../errors.js'
import { hmacSHA512, buildSortedQueryString, formatVNPayDate } from '../utils/crypto.js'

// ─── Fixtures ─────────────────────────────────────────────────────────────────

const CONFIG = {
  tmnCode: 'TESTCODE',
  hashSecret: 'test_hash_secret_key_1234567890ab',
  sandbox: true,
}

const PAYMENT_INPUT = {
  orderId: 'ORDER-001',
  amount: 100_000,
  currency: 'VND',
  description: 'Test payment',
  returnUrl: 'https://example.com/return',
  ipnUrl: 'https://example.com/ipn',
  customerInfo: { ipAddress: '1.2.3.4' },
}

function buildWebhookPayload(overrides: Record<string, string> = {}): Record<string, string> {
  const params: Record<string, string> = {
    vnp_Amount: '10000000',
    vnp_BankCode: 'NCB',
    vnp_CardType: 'ATM',
    vnp_OrderInfo: 'Test+payment',
    vnp_PayDate: '20240101120000',
    vnp_ResponseCode: '00',
    vnp_TmnCode: CONFIG.tmnCode,
    vnp_TransactionNo: 'TXN123456',
    vnp_TransactionStatus: '00',
    vnp_TxnRef: 'ORDER-001',
    ...overrides,
  }
  const qs = buildSortedQueryString(params)
  params['vnp_SecureHash'] = hmacSHA512(qs, CONFIG.hashSecret)
  return params
}

// ─── createPayment ────────────────────────────────────────────────────────────

describe('VNPayGateway.createPayment', () => {
  it('returns a valid payment URL with correct parameters', async () => {
    const gw = new VNPayGateway(CONFIG)
    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(true)
    expect(result.paymentUrl).toBeDefined()
    expect(result.paymentUrl).toContain('sandbox.vnpayment.vn')
    expect(result.paymentUrl).toContain('vnp_TxnRef=ORDER-001')
    expect(result.paymentUrl).toContain('vnp_Amount=10000000') // 100_000 * 100
    expect(result.paymentUrl).toContain('vnp_TmnCode=TESTCODE')
    expect(result.paymentUrl).toContain('vnp_SecureHash=')
    expect(result.orderId).toBe('ORDER-001')
    expect(result.amount).toBe(100_000)
    expect(result.currency).toBe('VND')
    expect(result.status).toBe(PaymentStatus.PENDING)
    expect(result.gateway).toBe('vnpay')
  })

  it('uses production URL when sandbox=false', async () => {
    const gw = new VNPayGateway({ ...CONFIG, sandbox: false })
    const result = await gw.createPayment(PAYMENT_INPUT)
    expect(result.paymentUrl).toContain('vnpayment.vn/paymentv2')
    expect(result.paymentUrl).not.toContain('sandbox')
  })

  it('sets locale to vn by default', async () => {
    const gw = new VNPayGateway(CONFIG)
    const result = await gw.createPayment(PAYMENT_INPUT)
    expect(result.paymentUrl).toContain('vnp_Locale=vn')
  })

  it('uses custom locale when provided', async () => {
    const gw = new VNPayGateway(CONFIG)
    const result = await gw.createPayment({ ...PAYMENT_INPUT, locale: 'en' })
    expect(result.paymentUrl).toContain('vnp_Locale=en')
  })

  it('includes vnp_ExpireDate when expireAt is provided', async () => {
    const gw = new VNPayGateway(CONFIG)
    const expireAt = new Date('2030-12-31T23:59:59')
    const result = await gw.createPayment({ ...PAYMENT_INPUT, expireAt })
    expect(result.paymentUrl).toContain('vnp_ExpireDate=')
  })

  it('produces a valid HMAC-SHA512 signature that can be verified', async () => {
    const gw = new VNPayGateway(CONFIG)
    const result = await gw.createPayment(PAYMENT_INPUT)
    const url = new URL(result.paymentUrl!)
    const receivedHash = url.searchParams.get('vnp_SecureHash')!
    url.searchParams.delete('vnp_SecureHash')

    const params: Record<string, string> = {}
    for (const [k, v] of url.searchParams.entries()) {
      params[k] = v
    }
    const qs = buildSortedQueryString(params)
    const expectedHash = hmacSHA512(qs, CONFIG.hashSecret)
    expect(receivedHash.toLowerCase()).toBe(expectedHash.toLowerCase())
  })

  it('throws INVALID_INPUT when orderId is missing', async () => {
    const gw = new VNPayGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, orderId: '' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is zero', async () => {
    const gw = new VNPayGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, amount: 0 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT for non-VND currency', async () => {
    const gw = new VNPayGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, currency: 'USD' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })
})

// ─── config validation ────────────────────────────────────────────────────────

describe('VNPayGateway constructor', () => {
  it('throws INVALID_CONFIG when tmnCode is missing', () => {
    expect(() => new VNPayGateway({ tmnCode: '', hashSecret: 'secret' })).toThrow(
      PaymentSDKError,
    )
    expect(() => new VNPayGateway({ tmnCode: '', hashSecret: 'secret' })).toThrow(
      expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }),
    )
  })

  it('throws INVALID_CONFIG when hashSecret is missing', () => {
    expect(() => new VNPayGateway({ tmnCode: 'CODE', hashSecret: '' })).toThrow(
      expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }),
    )
  })
})

// ─── verifyWebhook ────────────────────────────────────────────────────────────

describe('VNPayGateway.verifyWebhook', () => {
  it('verifies a valid successful webhook and returns SUCCESS status', async () => {
    const gw = new VNPayGateway(CONFIG)
    const payload = buildWebhookPayload()
    const event = await gw.verifyWebhook(payload, {})

    expect(event.gateway).toBe('vnpay')
    expect(event.orderId).toBe('ORDER-001')
    expect(event.transactionId).toBe('TXN123456')
    expect(event.status).toBe(PaymentStatus.SUCCESS)
    expect(event.amount).toBe(100_000) // 10000000 / 100
    expect(event.currency).toBe('VND')
    expect(event.rawData).toEqual(payload)
  })

  it('maps responseCode=24 to CANCELLED status', async () => {
    const gw = new VNPayGateway(CONFIG)
    const payload = buildWebhookPayload({
      vnp_ResponseCode: '24',
      vnp_TransactionStatus: '02',
    })
    const event = await gw.verifyWebhook(payload, {})
    expect(event.status).toBe(PaymentStatus.CANCELLED)
  })

  it('maps responseCode=11 to EXPIRED status', async () => {
    const gw = new VNPayGateway(CONFIG)
    const payload = buildWebhookPayload({
      vnp_ResponseCode: '11',
      vnp_TransactionStatus: '02',
    })
    const event = await gw.verifyWebhook(payload, {})
    expect(event.status).toBe(PaymentStatus.EXPIRED)
  })

  it('throws INVALID_SIGNATURE when hash is tampered', async () => {
    const gw = new VNPayGateway(CONFIG)
    const payload = buildWebhookPayload()
    payload['vnp_SecureHash'] = 'tampered_hash_value_that_is_wrong'

    await expect(gw.verifyWebhook(payload, {})).rejects.toMatchObject({
      code: ErrorCode.INVALID_SIGNATURE,
    })
  })

  it('throws INVALID_SIGNATURE when vnp_SecureHash is missing', async () => {
    const gw = new VNPayGateway(CONFIG)
    await expect(gw.verifyWebhook({ vnp_TxnRef: 'ORDER-001' }, {})).rejects.toMatchObject(
      { code: ErrorCode.INVALID_SIGNATURE },
    )
  })

  it('throws WEBHOOK_PROCESSING_FAILED when payload is not an object', async () => {
    const gw = new VNPayGateway(CONFIG)
    await expect(gw.verifyWebhook('raw-string', {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })

  it('signature must not include vnp_SecureHash field itself', async () => {
    const gw = new VNPayGateway(CONFIG)
    // This verifies the implementation strips hash fields before verification
    const payload = buildWebhookPayload()
    // Add vnp_SecureHashType (should also be stripped)
    payload['vnp_SecureHashType'] = 'HmacSHA512'
    await expect(gw.verifyWebhook(payload, {})).resolves.toBeDefined()
  })
})

// ─── getTransaction (mocked HTTP) ─────────────────────────────────────────────

describe('VNPayGateway.getTransaction', () => {
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
    const gw = new VNPayGateway(CONFIG)
    mockFetch({
      vnp_ResponseCode: '00',
      vnp_Message: 'Giao dich thanh cong',
      vnp_TransactionStatus: '00',
      vnp_TxnRef: 'ORDER-001',
      vnp_Amount: '10000000',
      vnp_TransactionNo: 'TXN999',
      vnp_SecureHash: 'abc',
    })

    const result = await gw.getTransaction('TXN999', 'ORDER-001')
    expect(result.success).toBe(true)
    expect(result.status).toBe(PaymentStatus.SUCCESS)
    expect(result.transactionId).toBe('TXN999')
    expect(result.amount).toBe(100_000)
    expect(result.rawResponse).toBeDefined()
    expect(result.error).toBeUndefined()
  })

  it('returns FAILED result for a failed transaction', async () => {
    const gw = new VNPayGateway(CONFIG)
    mockFetch({
      vnp_ResponseCode: '51',
      vnp_Message: 'So du khong du',
      vnp_TransactionStatus: '02',
      vnp_TxnRef: 'ORDER-001',
      vnp_Amount: '10000000',
      vnp_TransactionNo: '',
      vnp_SecureHash: 'abc',
    })

    const result = await gw.getTransaction('', 'ORDER-001')
    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.INSUFFICIENT_FUNDS)
    expect(result.error?.gatewayCode).toBe('51')
  })

  it('throws TRANSACTION_NOT_FOUND when gateway returns code 91', async () => {
    const gw = new VNPayGateway(CONFIG)
    mockFetch({
      vnp_ResponseCode: '91',
      vnp_Message: 'Transaction not found',
      vnp_TransactionStatus: '99',
      vnp_TxnRef: 'ORDER-404',
      vnp_Amount: '0',
      vnp_SecureHash: 'abc',
    })

    await expect(gw.getTransaction('', 'ORDER-404')).rejects.toMatchObject({
      code: ErrorCode.TRANSACTION_NOT_FOUND,
    })
  })

  it('throws INVALID_INPUT when orderId is not provided', async () => {
    const gw = new VNPayGateway(CONFIG)
    await expect(gw.getTransaction('TXN123')).rejects.toMatchObject({
      code: ErrorCode.INVALID_INPUT,
    })
  })

  it('retries on network error and eventually throws NETWORK_ERROR', async () => {
    const gw = new VNPayGateway({ ...CONFIG, retries: 1, timeout: 5000 })
    vi.mocked(fetch).mockRejectedValue(new Error('ECONNREFUSED'))

    await expect(gw.getTransaction('', 'ORDER-001')).rejects.toMatchObject({
      code: ErrorCode.NETWORK_ERROR,
    })
    expect(fetch).toHaveBeenCalledTimes(2) // 1 initial + 1 retry
  })
})

// ─── refund (mocked HTTP) ─────────────────────────────────────────────────────

describe('VNPayGateway.refund', () => {
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
    const gw = new VNPayGateway(CONFIG)
    mockFetch({
      vnp_ResponseCode: '00',
      vnp_Message: 'Giao dich thanh cong',
      vnp_TransactionStatus: '00',
      vnp_TxnRef: 'ORDER-001',
      vnp_Amount: '10000000',
      vnp_TransactionNo: 'REFUND-001',
      vnp_SecureHash: 'abc',
    })

    const result = await gw.refund({
      transactionId: 'TXN999',
      orderId: 'ORDER-001',
      amount: 100_000,
      reason: 'Customer request',
    })

    expect(result.success).toBe(true)
    expect(result.status).toBe(RefundStatus.SUCCESS)
    expect(result.refundId).toBe('REFUND-001')
    expect(result.amount).toBe(100_000)
    expect(result.error).toBeUndefined()
  })

  it('returns FAILED with error info for rejected refund', async () => {
    const gw = new VNPayGateway(CONFIG)
    mockFetch({
      vnp_ResponseCode: '99',
      vnp_Message: 'Giao dich khong hop le',
      vnp_TransactionStatus: '02',
      vnp_TxnRef: 'ORDER-001',
      vnp_Amount: '10000000',
      vnp_SecureHash: 'abc',
    })

    const result = await gw.refund({
      transactionId: 'TXN999',
      orderId: 'ORDER-001',
      amount: 100_000,
    })

    expect(result.success).toBe(false)
    expect(result.status).toBe(RefundStatus.FAILED)
    expect(result.error).toBeDefined()
    expect(result.rawResponse).toBeDefined()
  })

  it('throws INVALID_INPUT when required fields are missing', async () => {
    const gw = new VNPayGateway(CONFIG)
    await expect(
      gw.refund({ transactionId: '', orderId: 'ORD', amount: 1000 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })

    await expect(
      gw.refund({ transactionId: 'TXN', orderId: '', amount: 1000 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })

    await expect(
      gw.refund({ transactionId: 'TXN', orderId: 'ORD', amount: 0 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })
})
