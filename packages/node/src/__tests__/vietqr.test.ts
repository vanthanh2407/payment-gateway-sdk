import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createHash } from 'node:crypto'
import { VietQRGateway } from '../gateways/vietqr.js'
import { PaymentStatus, RefundStatus, ErrorCode, WebhookEventType } from '../types.js'

// ─── Fixtures ─────────────────────────────────────────────────────────────────

const CONFIG = {
  clientId: 'test_client_id',
  apiKey: 'test_api_key_abcdef1234567890',
  bankCode: '970010',
  bankAccount: '1234567890',
  accountName: 'NGUYEN VAN A',
  sandbox: true,
}

const PAYMENT_INPUT = {
  orderId: 'ORDER-VQR-001',
  amount: 100_000,
  currency: 'VND',
  description: 'Test VietQR payment',
  returnUrl: 'https://example.com/return',
}

const TOKEN_RESPONSE = {
  access_token: 'test_bearer_token_xyz',
  token_type: 'Bearer',
  expires_in: 300,
}

function md5(data: string): string {
  return createHash('md5').update(data).digest('hex')
}

function buildWebhookPayload(
  overrides: Partial<{
    code: string
    success: boolean
    orderId: string
    transactionId: string
    amount: number
    bankCode: string
  }> = {},
) {
  const isSuccess = overrides.code === undefined || overrides.code === '00'
  const base = {
    orderId: overrides.orderId ?? PAYMENT_INPUT.orderId,
    transactionId: overrides.transactionId ?? 'TXN-VQR-001',
    amount: overrides.amount ?? PAYMENT_INPUT.amount,
    bankCode: overrides.bankCode ?? CONFIG.bankCode,
    bankAccount: CONFIG.bankAccount,
    content: PAYMENT_INPUT.description,
    transTime: '2024-01-01 12:00:00',
  }

  // Webhook checksum: MD5(orderId + bankCode + amount + transactionId + apiKey)
  const checkSum = md5(
    `${base.orderId}${base.bankCode}${String(base.amount)}${base.transactionId}${CONFIG.apiKey}`,
  )

  return {
    code: overrides.code ?? '00',
    desc: isSuccess ? 'Success' : 'Failed',
    success: overrides.success ?? isSuccess,
    data: { ...base, checkSum },
  }
}

// ─── constructor validation ───────────────────────────────────────────────────

describe('VietQRGateway constructor', () => {
  it('throws INVALID_CONFIG when clientId is missing', () => {
    expect(() => new VietQRGateway({ ...CONFIG, clientId: '' })).toThrow(
      expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }),
    )
  })

  it('throws INVALID_CONFIG when apiKey is missing', () => {
    expect(() => new VietQRGateway({ ...CONFIG, apiKey: '' })).toThrow(
      expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }),
    )
  })

  it('throws INVALID_CONFIG when bankCode is missing', () => {
    expect(() => new VietQRGateway({ ...CONFIG, bankCode: '' })).toThrow(
      expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }),
    )
  })

  it('throws INVALID_CONFIG when bankAccount is missing', () => {
    expect(() => new VietQRGateway({ ...CONFIG, bankAccount: '' })).toThrow(
      expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }),
    )
  })

  it('throws INVALID_CONFIG when accountName is missing', () => {
    expect(() => new VietQRGateway({ ...CONFIG, accountName: '' })).toThrow(
      expect.objectContaining({ code: ErrorCode.INVALID_CONFIG }),
    )
  })

  it('constructs successfully with all required fields', () => {
    expect(() => new VietQRGateway(CONFIG)).not.toThrow()
  })

  it('exposes correct capabilities', () => {
    const gw = new VietQRGateway(CONFIG)
    expect(gw.capabilities.supportQRCode).toBe(true)
    expect(gw.capabilities.supportWebhook).toBe(true)
    expect(gw.capabilities.supportRefund).toBe(true)
    expect(gw.capabilities.supportPartialRefund).toBe(false)
    expect(gw.capabilities.supportRecurring).toBe(false)
    expect(gw.capabilities.currencies).toEqual(['VND'])
    expect(gw.capabilities.paymentMethods).toContain('qr')
  })
})

// ─── createPayment (mocked HTTP) ─────────────────────────────────────────────

describe('VietQRGateway.createPayment', () => {
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

  it('fetches token then generates QR and returns PENDING on success', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({
      code: '00',
      message: 'Success',
      data: {
        qr: 'base64qrdata...',
        qrDataURL: 'data:image/png;base64,abc123',
        urlLink: 'https://vietqr.vn/pay/ORDER-VQR-001',
      },
    })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(true)
    expect(result.paymentUrl).toBe('data:image/png;base64,abc123')
    expect(result.orderId).toBe('ORDER-VQR-001')
    expect(result.amount).toBe(100_000)
    expect(result.currency).toBe('VND')
    expect(result.status).toBe(PaymentStatus.PENDING)
    expect(result.gateway).toBe('vietqr')
    expect(result.rawResponse).toBeDefined()
    expect(result.error).toBeUndefined()
    // 2 calls: token + QR generate
    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it('omits paymentUrl when qrDataURL is absent', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: '00', message: 'Success' }) // no data field

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(true)
    expect(result.paymentUrl).toBeUndefined()
  })

  it('reuses cached token for subsequent calls', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE) // single token fetch
    mockFetch({ code: '00', message: 'Success', data: { qr: '', qrDataURL: 'data:1', urlLink: '' } })
    mockFetch({ code: '00', message: 'Success', data: { qr: '', qrDataURL: 'data:2', urlLink: '' } })

    await gw.createPayment(PAYMENT_INPUT)
    await gw.createPayment({ ...PAYMENT_INPUT, orderId: 'ORDER-VQR-002' })

    // 1 token + 2 QR calls = 3 total
    expect(fetch).toHaveBeenCalledTimes(3)
  })

  it('returns failure result when gateway returns error code', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: 'E76', message: 'Merchant not found' })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.INVALID_CONFIG)
    expect(result.error?.gatewayCode).toBe('E76')
    expect(result.error?.gatewayMessage).toBe('Merchant not found')
    expect(result.rawResponse).toBeDefined()
  })

  it('maps unknown error code to UNKNOWN_ERROR', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: 'E999', message: 'Unexpected error' })

    const result = await gw.createPayment(PAYMENT_INPUT)

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.UNKNOWN_ERROR)
  })

  it('uses sandbox URL when sandbox=true', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: '00', message: 'Success', data: { qr: '', qrDataURL: 'data:', urlLink: '' } })

    await gw.createPayment(PAYMENT_INPUT)

    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toContain('dev.vietqr.org')
    expect(vi.mocked(fetch).mock.calls[1]?.[0]).toContain('dev.vietqr.org')
  })

  it('uses production URL when sandbox=false', async () => {
    const gw = new VietQRGateway({ ...CONFIG, sandbox: false })
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: '00', message: 'Success', data: { qr: '', qrDataURL: 'data:', urlLink: '' } })

    await gw.createPayment(PAYMENT_INPUT)

    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toContain('api.vietqr.org')
    expect(vi.mocked(fetch).mock.calls[0]?.[0]).not.toContain('dev.vietqr.org')
  })

  it('sends Basic Auth header on token fetch', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: '00', message: 'Success', data: { qr: '', qrDataURL: 'data:', urlLink: '' } })

    await gw.createPayment(PAYMENT_INPUT)

    const tokenHeaders = vi.mocked(fetch).mock.calls[0]?.[1]?.headers as Record<string, string>
    const expectedCreds = Buffer.from(`${CONFIG.clientId}:${CONFIG.apiKey}`).toString('base64')
    expect(tokenHeaders?.['Authorization']).toBe(`Basic ${expectedCreds}`)
  })

  it('sends Bearer token header on QR generate call', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: '00', message: 'Success', data: { qr: '', qrDataURL: 'data:', urlLink: '' } })

    await gw.createPayment(PAYMENT_INPUT)

    const qrHeaders = vi.mocked(fetch).mock.calls[1]?.[1]?.headers as Record<string, string>
    expect(qrHeaders?.['Authorization']).toBe(`Bearer ${TOKEN_RESPONSE.access_token}`)
  })

  it('throws AUTHENTICATION_FAILED when token response has no access_token', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch({ access_token: '', token_type: 'Bearer', expires_in: 300 })

    await expect(gw.createPayment(PAYMENT_INPUT)).rejects.toMatchObject({
      code: ErrorCode.AUTHENTICATION_FAILED,
    })
  })

  it('throws INVALID_INPUT when orderId is missing', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, orderId: '' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is zero', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, amount: 0 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is negative', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, amount: -500 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT for non-VND currency', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(
      gw.createPayment({ ...PAYMENT_INPUT, currency: 'USD' }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws NETWORK_ERROR when token fetch fails', async () => {
    const gw = new VietQRGateway({ ...CONFIG, retries: 0 })
    vi.mocked(fetch).mockRejectedValue(new Error('ECONNREFUSED'))

    await expect(gw.createPayment(PAYMENT_INPUT)).rejects.toMatchObject({
      code: ErrorCode.NETWORK_ERROR,
    })
  })
})

// ─── verifyWebhook ────────────────────────────────────────────────────────────

describe('VietQRGateway.verifyWebhook', () => {
  it('verifies a valid successful webhook and returns PAYMENT_SUCCESS', async () => {
    const gw = new VietQRGateway(CONFIG)
    const payload = buildWebhookPayload()

    const event = await gw.verifyWebhook(payload, {})

    expect(event.gateway).toBe('vietqr')
    expect(event.eventType).toBe(WebhookEventType.PAYMENT_SUCCESS)
    expect(event.orderId).toBe('ORDER-VQR-001')
    expect(event.transactionId).toBe('TXN-VQR-001')
    expect(event.amount).toBe(100_000)
    expect(event.currency).toBe('VND')
    expect(event.status).toBe(PaymentStatus.SUCCESS)
    expect(event.rawData).toEqual(payload)
    expect(event.receivedAt).toBeInstanceOf(Date)
  })

  it('verifies a failed webhook and returns PAYMENT_FAILED', async () => {
    const gw = new VietQRGateway(CONFIG)
    const payload = buildWebhookPayload({ code: 'E75', success: false })

    const event = await gw.verifyWebhook(payload, {})

    expect(event.status).toBe(PaymentStatus.FAILED)
    expect(event.eventType).toBe(WebhookEventType.PAYMENT_FAILED)
  })

  it('treats success=true as successful even when code is absent', async () => {
    const gw = new VietQRGateway(CONFIG)
    const payload = buildWebhookPayload({ success: true })

    const event = await gw.verifyWebhook(payload, {})

    expect(event.status).toBe(PaymentStatus.SUCCESS)
  })

  it('throws INVALID_SIGNATURE when checkSum is tampered', async () => {
    const gw = new VietQRGateway(CONFIG)
    const payload = buildWebhookPayload()
    // Replace with a valid-length hex string that is wrong
    const tampered = { ...payload, data: { ...payload.data, checkSum: 'a'.repeat(32) } }

    await expect(gw.verifyWebhook(tampered, {})).rejects.toMatchObject({
      code: ErrorCode.INVALID_SIGNATURE,
    })
  })

  it('throws INVALID_SIGNATURE when checkSum is for wrong apiKey', async () => {
    const gw = new VietQRGateway(CONFIG)
    const payloadWithWrongKey = buildWebhookPayload()
    // Overwrite checkSum with one signed using a different key
    const wrongCheckSum = md5(
      `${payloadWithWrongKey.data.orderId}${payloadWithWrongKey.data.bankCode}${String(payloadWithWrongKey.data.amount)}${payloadWithWrongKey.data.transactionId}wrong_api_key`,
    )
    const tampered = { ...payloadWithWrongKey, data: { ...payloadWithWrongKey.data, checkSum: wrongCheckSum } }

    await expect(gw.verifyWebhook(tampered, {})).rejects.toMatchObject({
      code: ErrorCode.INVALID_SIGNATURE,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED for null payload', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(gw.verifyWebhook(null, {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED for string payload', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(gw.verifyWebhook('plain-string', {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED when data.checkSum is missing', async () => {
    const gw = new VietQRGateway(CONFIG)
    const payload = buildWebhookPayload()
    const { checkSum: _removed, ...dataWithoutCheckSum } = payload.data
    const broken = { ...payload, data: dataWithoutCheckSum }

    await expect(gw.verifyWebhook(broken, {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })

  it('throws WEBHOOK_PROCESSING_FAILED when data field is missing', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(gw.verifyWebhook({ code: '00', desc: 'ok', success: true }, {})).rejects.toMatchObject({
      code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
    })
  })
})

// ─── getTransaction (mocked HTTP) ────────────────────────────────────────────

describe('VietQRGateway.getTransaction', () => {
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

  it('returns SUCCESS for a completed transaction', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({
      code: '00',
      message: 'Success',
      data: {
        orderId: 'ORDER-VQR-001',
        transactionId: 'TXN-VQR-001',
        amount: 100_000,
        bankCode: CONFIG.bankCode,
        bankAccount: CONFIG.bankAccount,
        content: 'Test VietQR payment',
        transTime: '2024-01-01 12:00:00',
      },
    })

    const result = await gw.getTransaction('TXN-VQR-001', 'ORDER-VQR-001')

    expect(result.success).toBe(true)
    expect(result.status).toBe(PaymentStatus.SUCCESS)
    expect(result.transactionId).toBe('TXN-VQR-001')
    expect(result.orderId).toBe('ORDER-VQR-001')
    expect(result.amount).toBe(100_000)
    expect(result.currency).toBe('VND')
    expect(result.gateway).toBe('vietqr')
    expect(result.rawResponse).toBeDefined()
    expect(result.error).toBeUndefined()
  })

  it('falls back to transactionId as orderId when orderId is omitted', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({
      code: '00',
      message: 'Success',
      data: {
        orderId: 'TXN-VQR-001',
        transactionId: 'TXN-VQR-001',
        amount: 100_000,
        bankCode: CONFIG.bankCode,
        bankAccount: CONFIG.bankAccount,
        content: '',
        transTime: '',
      },
    })

    const result = await gw.getTransaction('TXN-VQR-001')

    expect(result.success).toBe(true)
  })

  it('returns FAILED with AUTHENTICATION_FAILED when token is expired', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: 'E74', message: 'Token invalid or expired' })

    const result = await gw.getTransaction('TXN-VQR-001', 'ORDER-VQR-001')

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.AUTHENTICATION_FAILED)
    expect(result.rawResponse).toBeDefined()
  })

  it('omits transactionId from result when data is absent', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: 'E24', message: 'Bank not found' })

    const result = await gw.getTransaction('TXN-VQR-001', 'ORDER-VQR-001')

    expect(result.success).toBe(false)
    expect(result.transactionId).toBeUndefined()
  })

  it('throws NETWORK_ERROR when fetch always rejects', async () => {
    const gw = new VietQRGateway({ ...CONFIG, retries: 0 })
    vi.mocked(fetch).mockRejectedValue(new Error('Connection refused'))

    await expect(gw.getTransaction('TXN', 'ORDER-VQR-001')).rejects.toMatchObject({
      code: ErrorCode.NETWORK_ERROR,
    })
  })
})

// ─── refund (mocked HTTP) ─────────────────────────────────────────────────────

describe('VietQRGateway.refund', () => {
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
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({
      code: '00',
      message: 'Refund successful',
      data: {
        refundId: 'REFUND-001',
        orderId: 'ORDER-VQR-001',
        amount: 100_000,
        status: 'SUCCESS',
      },
    })

    const result = await gw.refund({
      transactionId: 'TXN-VQR-001',
      orderId: 'ORDER-VQR-001',
      amount: 100_000,
      reason: 'Wrong item',
    })

    expect(result.success).toBe(true)
    expect(result.status).toBe(RefundStatus.SUCCESS)
    expect(result.refundId).toBe('REFUND-001')
    expect(result.transactionId).toBe('TXN-VQR-001')
    expect(result.orderId).toBe('ORDER-VQR-001')
    expect(result.amount).toBe(100_000)
    expect(result.rawResponse).toBeDefined()
    expect(result.error).toBeUndefined()
  })

  it('omits refundId from result when data is absent', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: '00', message: 'Refund successful' }) // no data

    const result = await gw.refund({
      transactionId: 'TXN-VQR-001',
      orderId: 'ORDER-VQR-001',
      amount: 100_000,
    })

    expect(result.success).toBe(true)
    expect(result.refundId).toBeUndefined()
  })

  it('returns FAILED with REFUND_ALREADY_PROCESSED for E157', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: 'E157', message: 'Transaction already refunded' })

    const result = await gw.refund({
      transactionId: 'TXN-VQR-001',
      orderId: 'ORDER-VQR-001',
      amount: 100_000,
    })

    expect(result.success).toBe(false)
    expect(result.status).toBe(RefundStatus.FAILED)
    expect(result.error?.code).toBe(ErrorCode.REFUND_ALREADY_PROCESSED)
    expect(result.error?.gatewayCode).toBe('E157')
    expect(result.rawResponse).toBeDefined()
  })

  it('returns FAILED with REFUND_FAILED for E42', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: 'E42', message: 'Refund authorization failure' })

    const result = await gw.refund({
      transactionId: 'TXN-VQR-001',
      orderId: 'ORDER-VQR-001',
      amount: 100_000,
    })

    expect(result.success).toBe(false)
    expect(result.error?.code).toBe(ErrorCode.REFUND_FAILED)
  })

  it('includes remark in request body when reason is provided', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: '00', message: 'Success', data: { refundId: 'R1', orderId: 'O1', amount: 1000, status: 'SUCCESS' } })

    await gw.refund({
      transactionId: 'TXN-VQR-001',
      orderId: 'ORDER-VQR-001',
      amount: 100_000,
      reason: 'Customer request',
    })

    const refundCall = vi.mocked(fetch).mock.calls[1]
    const body = JSON.parse(refundCall?.[1]?.body as string)
    expect(body.remark).toBe('Customer request')
  })

  it('omits remark from request body when reason is not provided', async () => {
    const gw = new VietQRGateway(CONFIG)
    mockFetch(TOKEN_RESPONSE)
    mockFetch({ code: '00', message: 'Success', data: { refundId: 'R1', orderId: 'O1', amount: 1000, status: 'SUCCESS' } })

    await gw.refund({
      transactionId: 'TXN-VQR-001',
      orderId: 'ORDER-VQR-001',
      amount: 100_000,
    })

    const refundCall = vi.mocked(fetch).mock.calls[1]
    const body = JSON.parse(refundCall?.[1]?.body as string)
    expect(body.remark).toBeUndefined()
  })

  it('throws INVALID_INPUT when transactionId is missing', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(
      gw.refund({ transactionId: '', orderId: 'ORDER-VQR-001', amount: 100_000 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when orderId is missing', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(
      gw.refund({ transactionId: 'TXN-VQR-001', orderId: '', amount: 100_000 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws INVALID_INPUT when amount is not positive', async () => {
    const gw = new VietQRGateway(CONFIG)
    await expect(
      gw.refund({ transactionId: 'TXN-VQR-001', orderId: 'ORDER-VQR-001', amount: 0 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
    await expect(
      gw.refund({ transactionId: 'TXN-VQR-001', orderId: 'ORDER-VQR-001', amount: -1 }),
    ).rejects.toMatchObject({ code: ErrorCode.INVALID_INPUT })
  })

  it('throws NETWORK_ERROR when fetch always rejects', async () => {
    const gw = new VietQRGateway({ ...CONFIG, retries: 0 })
    vi.mocked(fetch).mockRejectedValue(new Error('Connection refused'))

    await expect(
      gw.refund({ transactionId: 'TXN-VQR-001', orderId: 'ORDER-VQR-001', amount: 100_000 }),
    ).rejects.toMatchObject({ code: ErrorCode.NETWORK_ERROR })
  })
})
