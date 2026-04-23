import { createHash } from 'node:crypto'
import {
  type PaymentGateway,
  type GatewayCapabilities,
  type CreatePaymentInput,
  type PaymentResult,
  type WebhookEvent,
  type RefundInput,
  type RefundResult,
  type BaseGatewayConfig,
  PaymentStatus,
  RefundStatus,
  WebhookEventType,
  ErrorCode,
} from '../types.js'
import {
  PaymentSDKError,
  invalidConfig,
  invalidInput,
  invalidSignature,
} from '../errors.js'
import { timingSafeEqual } from '../utils/crypto.js'
import { httpPost } from '../utils/http.js'

// ─── Config ───────────────────────────────────────────────────────────────────

export interface VietQRConfig extends BaseGatewayConfig {
  clientId: string
  apiKey: string
  /** Merchant receiving bank code (e.g. "970010" for Techcombank) */
  bankCode: string
  /** Merchant receiving bank account number */
  bankAccount: string
  /** Merchant bank account holder name */
  accountName: string
}

// ─── API response shapes ──────────────────────────────────────────────────────

interface VietQRTokenResponse {
  access_token: string
  token_type: string
  expires_in: number
}

interface VietQRGenerateResponse {
  code: string
  message: string
  data?: {
    qr: string
    qrDataURL: string
    urlLink: string
  }
}

interface VietQRQueryResponse {
  code: string
  message: string
  data?: {
    orderId: string
    transactionId: string
    amount: number
    bankCode: string
    bankAccount: string
    content: string
    transTime: string
  }
}

interface VietQRRefundResponse {
  code: string
  message: string
  data?: {
    refundId: string
    orderId: string
    amount: number
    status: string
  }
}

interface VietQRWebhookData {
  orderId: string
  transactionId: string
  amount: number
  bankCode: string
  bankAccount: string
  content: string
  transTime: string
  checkSum: string
}

interface VietQRWebhookPayload {
  code: string
  desc: string
  success: boolean
  data: VietQRWebhookData
}

// ─── Constants ────────────────────────────────────────────────────────────────

const API_URL = {
  sandbox: 'https://dev.vietqr.org/vqr/api',
  production: 'https://api.vietqr.org/vqr/api',
} as const

const RESULT_CODE_MAP: Record<string, ErrorCode> = {
  E01: ErrorCode.AUTHENTICATION_FAILED,
  E02: ErrorCode.AUTHENTICATION_FAILED,
  E03: ErrorCode.AUTHENTICATION_FAILED,
  E04: ErrorCode.AUTHENTICATION_FAILED,
  E05: ErrorCode.AUTHENTICATION_FAILED,
  E06: ErrorCode.AUTHENTICATION_FAILED,
  E07: ErrorCode.AUTHENTICATION_FAILED,
  E08: ErrorCode.AUTHENTICATION_FAILED,
  E09: ErrorCode.AUTHENTICATION_FAILED,
  E24: ErrorCode.INVALID_INPUT,
  E39: ErrorCode.INVALID_SIGNATURE,
  E42: ErrorCode.REFUND_FAILED,
  E43: ErrorCode.REFUND_FAILED,
  E44: ErrorCode.REFUND_FAILED,
  E45: ErrorCode.REFUND_FAILED,
  E46: ErrorCode.REFUND_FAILED,
  E74: ErrorCode.AUTHENTICATION_FAILED,
  E75: ErrorCode.GATEWAY_ERROR,
  E76: ErrorCode.INVALID_CONFIG,
  E157: ErrorCode.REFUND_ALREADY_PROCESSED,
}

function mapResultCode(code: string): ErrorCode {
  return RESULT_CODE_MAP[code] ?? ErrorCode.UNKNOWN_ERROR
}

function mapCodeToStatus(code: string): PaymentStatus {
  if (code === '00') return PaymentStatus.SUCCESS
  if (code === 'E157') return PaymentStatus.REFUNDED
  return PaymentStatus.FAILED
}

function md5(data: string): string {
  return createHash('md5').update(data).digest('hex')
}

// ─── VietQRGateway ────────────────────────────────────────────────────────────

export class VietQRGateway implements PaymentGateway {
  readonly name = 'vietqr'

  readonly capabilities: GatewayCapabilities = {
    supportRefund: true,
    supportPartialRefund: false,
    supportRecurring: false,
    supportWebhook: true,
    supportQRCode: true,
    supportInstallment: false,
    currencies: ['VND'],
    paymentMethods: ['banking', 'qr'],
  }

  private readonly config: Required<VietQRConfig>
  private cachedToken: string | undefined
  private tokenExpiresAt = 0

  constructor(config: VietQRConfig) {
    if (!config.clientId) throw invalidConfig('VietQR: clientId is required')
    if (!config.apiKey) throw invalidConfig('VietQR: apiKey is required')
    if (!config.bankCode) throw invalidConfig('VietQR: bankCode is required')
    if (!config.bankAccount) throw invalidConfig('VietQR: bankAccount is required')
    if (!config.accountName) throw invalidConfig('VietQR: accountName is required')

    this.config = {
      clientId: config.clientId,
      apiKey: config.apiKey,
      bankCode: config.bankCode,
      bankAccount: config.bankAccount,
      accountName: config.accountName,
      sandbox: config.sandbox ?? false,
      timeout: config.timeout ?? 30_000,
      retries: config.retries ?? 2,
    }
  }

  private get baseUrl(): string {
    return this.config.sandbox ? API_URL.sandbox : API_URL.production
  }

  // Bearer tokens expire in 300 s; cache with a 30 s safety buffer
  private async getAccessToken(): Promise<string> {
    const now = Date.now()
    if (this.cachedToken !== undefined && now < this.tokenExpiresAt) {
      return this.cachedToken
    }

    const credentials = Buffer.from(
      `${this.config.clientId}:${this.config.apiKey}`,
    ).toString('base64')

    let raw: VietQRTokenResponse
    try {
      raw = await httpPost<VietQRTokenResponse>(
        `${this.baseUrl}/peripheral/ecommerce/token_generate`,
        {},
        {
          timeout: this.config.timeout,
          retries: this.config.retries,
          headers: { Authorization: `Basic ${credentials}` },
        },
      )
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    if (!raw.access_token) {
      throw new PaymentSDKError({
        code: ErrorCode.AUTHENTICATION_FAILED,
        message: 'VietQR: failed to obtain access token',
      })
    }

    this.cachedToken = raw.access_token
    this.tokenExpiresAt = now + (raw.expires_in - 30) * 1000
    return this.cachedToken
  }

  // Request checksum: MD5(clientId + orderId + apiKey)
  private buildCheckSum(orderId: string): string {
    return md5(`${this.config.clientId}${orderId}${this.config.apiKey}`)
  }

  // ─── createPayment ───────────────────────────────────────────────────────

  async createPayment(input: CreatePaymentInput): Promise<PaymentResult> {
    if (!input.orderId) throw invalidInput('orderId is required')
    if (!input.amount || input.amount <= 0) throw invalidInput('amount must be positive')
    if (input.currency && input.currency !== 'VND') {
      throw invalidInput('VietQR only supports VND currency')
    }

    const now = new Date()
    const token = await this.getAccessToken()

    const body = {
      bankCode: this.config.bankCode,
      bankAccount: this.config.bankAccount,
      userBankName: this.config.accountName,
      amount: input.amount,
      orderId: input.orderId,
      content: input.description,
      transType: 'C',
      urlLink: input.returnUrl,
      checkSum: this.buildCheckSum(input.orderId),
    }

    let raw: VietQRGenerateResponse
    try {
      raw = await httpPost<VietQRGenerateResponse>(
        `${this.baseUrl}/qr/generate-customer`,
        body,
        {
          timeout: this.config.timeout,
          retries: this.config.retries,
          headers: { Authorization: `Bearer ${token}` },
        },
      )
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const isSuccess = raw.code === '00'

    return {
      success: isSuccess,
      // paymentUrl holds the QR data URL the customer scans to pay
      ...(raw.data?.qrDataURL !== undefined && { paymentUrl: raw.data.qrDataURL }),
      orderId: input.orderId,
      amount: input.amount,
      currency: 'VND',
      status: isSuccess ? PaymentStatus.PENDING : mapCodeToStatus(raw.code),
      gateway: this.name,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapResultCode(raw.code),
          message: raw.message,
          gatewayCode: raw.code,
          gatewayMessage: raw.message,
        },
      }),
      createdAt: now,
    }
  }

  // ─── verifyWebhook ───────────────────────────────────────────────────────

  async verifyWebhook(
    payload: unknown,
    _headers: Record<string, string>,
  ): Promise<WebhookEvent> {
    if (typeof payload !== 'object' || payload === null) {
      throw new PaymentSDKError({
        code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
        message: 'VietQR webhook payload must be an object',
      })
    }

    const data = payload as VietQRWebhookPayload

    if (
      typeof data.data !== 'object' ||
      data.data === null ||
      typeof data.data.checkSum !== 'string'
    ) {
      throw new PaymentSDKError({
        code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
        message: 'VietQR webhook missing data or checkSum',
      })
    }

    const { orderId, transactionId, amount, bankCode, checkSum: receivedCheckSum } = data.data

    // Webhook checksum: MD5(orderId + bankCode + amount + transactionId + apiKey)
    const expectedCheckSum = md5(
      `${orderId ?? ''}${bankCode ?? ''}${String(amount ?? '')}${transactionId ?? ''}${this.config.apiKey}`,
    )

    if (!timingSafeEqual(expectedCheckSum, receivedCheckSum)) {
      throw invalidSignature()
    }

    const isSuccess = data.code === '00' || data.success === true
    const status = isSuccess ? PaymentStatus.SUCCESS : PaymentStatus.FAILED
    const eventType = isSuccess
      ? WebhookEventType.PAYMENT_SUCCESS
      : WebhookEventType.PAYMENT_FAILED

    return {
      gateway: this.name,
      eventType,
      orderId: orderId ?? '',
      transactionId: transactionId ?? '',
      amount: amount ?? 0,
      currency: 'VND',
      status,
      rawData: data,
      receivedAt: new Date(),
    }
  }

  // ─── getTransaction ──────────────────────────────────────────────────────

  async getTransaction(transactionId: string, orderId?: string): Promise<PaymentResult> {
    const resolvedOrderId = orderId ?? transactionId
    const now = new Date()
    const token = await this.getAccessToken()

    const body = {
      orderId: resolvedOrderId,
      bankCode: this.config.bankCode,
      bankAccount: this.config.bankAccount,
      checkSum: this.buildCheckSum(resolvedOrderId),
    }

    let raw: VietQRQueryResponse
    try {
      raw = await httpPost<VietQRQueryResponse>(
        `${this.baseUrl}/transaction/query`,
        body,
        {
          timeout: this.config.timeout,
          retries: this.config.retries,
          headers: { Authorization: `Bearer ${token}` },
        },
      )
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const isSuccess = raw.code === '00'

    return {
      success: isSuccess,
      ...(raw.data?.transactionId !== undefined && { transactionId: raw.data.transactionId }),
      orderId: raw.data?.orderId ?? resolvedOrderId,
      amount: raw.data?.amount ?? 0,
      currency: 'VND',
      status: mapCodeToStatus(raw.code),
      gateway: this.name,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapResultCode(raw.code),
          message: raw.message,
          gatewayCode: raw.code,
          gatewayMessage: raw.message,
        },
      }),
      createdAt: now,
    }
  }

  // ─── refund ──────────────────────────────────────────────────────────────

  async refund(input: RefundInput): Promise<RefundResult> {
    if (!input.transactionId) throw invalidInput('transactionId is required')
    if (!input.orderId) throw invalidInput('orderId is required')
    if (!input.amount || input.amount <= 0) throw invalidInput('amount must be positive')

    const token = await this.getAccessToken()
    // Refund checksum: MD5(clientId + transactionId + amount + apiKey)
    const checkSum = md5(
      `${this.config.clientId}${input.transactionId}${String(input.amount)}${this.config.apiKey}`,
    )

    const body = {
      transactionId: input.transactionId,
      orderId: input.orderId,
      bankCode: this.config.bankCode,
      bankAccount: this.config.bankAccount,
      amount: input.amount,
      checkSum,
      ...(input.reason !== undefined && { remark: input.reason }),
    }

    let raw: VietQRRefundResponse
    try {
      raw = await httpPost<VietQRRefundResponse>(
        `${this.baseUrl}/transaction/refund`,
        body,
        {
          timeout: this.config.timeout,
          retries: this.config.retries,
          headers: { Authorization: `Bearer ${token}` },
        },
      )
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const isSuccess = raw.code === '00'

    return {
      success: isSuccess,
      ...(raw.data?.refundId !== undefined && { refundId: raw.data.refundId }),
      transactionId: input.transactionId,
      orderId: input.orderId,
      amount: input.amount,
      status: isSuccess ? RefundStatus.SUCCESS : RefundStatus.FAILED,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapResultCode(raw.code),
          message: raw.message,
          gatewayCode: raw.code,
          gatewayMessage: raw.message,
        },
      }),
    }
  }
}
