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
import { hmacSHA256, timingSafeEqual } from '../utils/crypto.js'
import { httpPost } from '../utils/http.js'

// ─── Config ───────────────────────────────────────────────────────────────────

export interface ZaloPayConfig extends BaseGatewayConfig {
  appId: number
  key1: string
  key2: string
}

// ─── ZaloPay API shapes ───────────────────────────────────────────────────────

interface ZaloPayCreateResponse {
  return_code: number
  return_message: string
  sub_return_code?: number
  sub_return_message?: string
  order_url?: string
  zp_trans_token?: string
  order_token?: string
  qr_code?: string
}

interface ZaloPayQueryResponse {
  return_code: number
  return_message: string
  sub_return_code?: number
  sub_return_message?: string
  amount?: number
  zp_trans_id?: number
  zp_user_id?: string
}

interface ZaloPayRefundResponse {
  return_code: number
  return_message: string
  sub_return_code?: number
  sub_return_message?: string
  refund_id?: number
}

interface ZaloPayWebhookData {
  app_id: number
  app_trans_id: string
  app_time: number
  app_user: string
  amount: number
  embed_data: string
  item: string
  zp_trans_id: number
  server_time: number
  channel: number
  merchant_user_id: string
}

// ─── Constants ────────────────────────────────────────────────────────────────

const API_BASE = {
  sandbox: 'https://sb-openapi.zalopay.vn/v2',
  production: 'https://openapi.zalopay.vn/v2',
} as const

const RETURN_CODE_MAP: Record<number, ErrorCode> = {
  2: ErrorCode.GATEWAY_ERROR,
  [-1]: ErrorCode.GATEWAY_ERROR,
  [-2]: ErrorCode.INVALID_INPUT,
  [-3]: ErrorCode.AUTHENTICATION_FAILED,
  [-4]: ErrorCode.AUTHENTICATION_FAILED,
  [-5]: ErrorCode.INVALID_INPUT,
  [-6]: ErrorCode.PAYMENT_EXPIRED,
  [-7]: ErrorCode.INVALID_AMOUNT,
  [-9]: ErrorCode.INVALID_CONFIG,
  [-10]: ErrorCode.INVALID_SIGNATURE,
  [-11]: ErrorCode.DUPLICATE_ORDER,
  [-12]: ErrorCode.REFUND_ALREADY_PROCESSED,
  [-13]: ErrorCode.REFUND_AMOUNT_EXCEEDED,
  [-14]: ErrorCode.REFUND_WINDOW_EXPIRED,
  [-15]: ErrorCode.TRANSACTION_NOT_FOUND,
  [-16]: ErrorCode.PAYMENT_FAILED,
  [-49]: ErrorCode.TRANSACTION_NOT_FOUND,
  [-58]: ErrorCode.CARD_DECLINED,
}

function mapReturnCode(code: number): ErrorCode {
  return RETURN_CODE_MAP[code] ?? ErrorCode.UNKNOWN_ERROR
}

function mapReturnCodeToStatus(code: number): PaymentStatus {
  if (code === 1) return PaymentStatus.SUCCESS
  if (code === 2) return PaymentStatus.PROCESSING
  if (code === -6) return PaymentStatus.EXPIRED
  if (code === -11) return PaymentStatus.FAILED
  return PaymentStatus.FAILED
}

function buildAppTransId(orderId: string): string {
  const now = new Date()
  const yy = String(now.getFullYear()).slice(-2)
  const mm = String(now.getMonth() + 1).padStart(2, '0')
  const dd = String(now.getDate()).padStart(2, '0')
  return `${yy}${mm}${dd}_${orderId}`
}

function buildMRefundId(appId: number, orderId: string, timestamp: number): string {
  const now = new Date()
  const yy = String(now.getFullYear()).slice(-2)
  const mm = String(now.getMonth() + 1).padStart(2, '0')
  const dd = String(now.getDate()).padStart(2, '0')
  return `${yy}${mm}${dd}_${appId}_${orderId}_${timestamp}`
}

// ─── ZaloPayGateway ───────────────────────────────────────────────────────────

export class ZaloPayGateway implements PaymentGateway {
  readonly name = 'zalopay'
  readonly capabilities: GatewayCapabilities = {
    supportRefund: true,
    supportPartialRefund: true,
    supportRecurring: false,
    supportWebhook: true,
    supportQRCode: true,
    supportInstallment: false,
    currencies: ['VND'],
    paymentMethods: ['wallet', 'card', 'banking', 'qr'],
  }

  private readonly config: Required<ZaloPayConfig>

  constructor(config: ZaloPayConfig) {
    if (!config.appId) throw invalidConfig('ZaloPay: appId is required')
    if (!config.key1) throw invalidConfig('ZaloPay: key1 is required')
    if (!config.key2) throw invalidConfig('ZaloPay: key2 is required')

    this.config = {
      appId: config.appId,
      key1: config.key1,
      key2: config.key2,
      sandbox: config.sandbox ?? false,
      timeout: config.timeout ?? 30_000,
      retries: config.retries ?? 2,
    }
  }

  private get baseUrl(): string {
    return this.config.sandbox ? API_BASE.sandbox : API_BASE.production
  }

  // ─── createPayment ───────────────────────────────────────────────────────

  async createPayment(input: CreatePaymentInput): Promise<PaymentResult> {
    if (!input.orderId) throw invalidInput('orderId is required')
    if (!input.amount || input.amount <= 0) throw invalidInput('amount must be positive')
    if (input.currency && input.currency !== 'VND') {
      throw invalidInput('ZaloPay only supports VND currency')
    }

    const now = new Date()
    const appTime = Date.now()
    const appTransId = buildAppTransId(input.orderId)
    const appUser = input.customerInfo?.name ?? 'user'
    const embedData = JSON.stringify({ redirecturl: input.returnUrl })
    const item = '[]'

    // Signing: `${app_id}|${app_trans_id}|${app_user}|${amount}|${app_time}|${embed_data}|${item}`
    const signData = `${this.config.appId}|${appTransId}|${appUser}|${input.amount}|${appTime}|${embedData}|${item}`
    const mac = hmacSHA256(signData, this.config.key1)

    const body = {
      app_id: this.config.appId,
      app_trans_id: appTransId,
      app_user: appUser,
      amount: input.amount,
      app_time: appTime,
      embed_data: embedData,
      item,
      description: input.description,
      callback_url: input.ipnUrl ?? '',
      mac,
    }

    let raw: ZaloPayCreateResponse
    try {
      raw = await httpPost<ZaloPayCreateResponse>(`${this.baseUrl}/create`, body, {
        timeout: this.config.timeout,
        retries: this.config.retries,
      })
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const isSuccess = raw.return_code === 1

    return {
      success: isSuccess,
      ...(raw.order_url !== undefined && { paymentUrl: raw.order_url }),
      orderId: input.orderId,
      amount: input.amount,
      currency: 'VND',
      status: isSuccess ? PaymentStatus.PENDING : mapReturnCodeToStatus(raw.return_code),
      gateway: this.name,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapReturnCode(raw.return_code),
          message: raw.return_message,
          gatewayCode: String(raw.return_code),
          gatewayMessage: raw.return_message,
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
        message: 'ZaloPay webhook payload must be an object',
      })
    }

    const raw = payload as { data?: unknown; mac?: unknown }

    if (typeof raw.data !== 'string' || typeof raw.mac !== 'string') {
      throw invalidSignature('ZaloPay webhook missing data or mac field')
    }

    const dataStr = raw.data
    const receivedMac = raw.mac.toLowerCase()

    const expectedMac = hmacSHA256(dataStr, this.config.key2)

    if (!timingSafeEqual(expectedMac, receivedMac)) {
      throw invalidSignature()
    }

    let parsedData: ZaloPayWebhookData
    try {
      parsedData = JSON.parse(dataStr) as ZaloPayWebhookData
    } catch {
      throw new PaymentSDKError({
        code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
        message: 'ZaloPay webhook data field is not valid JSON',
      })
    }

    return {
      gateway: this.name,
      eventType: WebhookEventType.PAYMENT_SUCCESS,
      orderId: parsedData.app_trans_id,
      transactionId: String(parsedData.zp_trans_id),
      amount: parsedData.amount,
      currency: 'VND',
      status: PaymentStatus.SUCCESS,
      rawData: payload,
      receivedAt: new Date(),
    }
  }

  // ─── getTransaction ──────────────────────────────────────────────────────

  async getTransaction(transactionId: string, orderId?: string): Promise<PaymentResult> {
    const now = new Date()

    // Signing: `${app_id}|${app_trans_id}|${key1}`
    const signData = `${this.config.appId}|${transactionId}|${this.config.key1}`
    const mac = hmacSHA256(signData, this.config.key1)

    const body = {
      app_id: this.config.appId,
      app_trans_id: transactionId,
      mac,
    }

    let raw: ZaloPayQueryResponse
    try {
      raw = await httpPost<ZaloPayQueryResponse>(`${this.baseUrl}/query`, body, {
        timeout: this.config.timeout,
        retries: this.config.retries,
      })
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    if (raw.return_code === -49 || raw.return_code === -15) {
      throw new PaymentSDKError({
        code: ErrorCode.TRANSACTION_NOT_FOUND,
        message: `Transaction not found: ${transactionId}`,
        gatewayCode: String(raw.return_code),
        gatewayMessage: raw.return_message,
      })
    }

    const isSuccess = raw.return_code === 1
    const resolvedOrderId = orderId ?? transactionId

    return {
      success: isSuccess,
      ...(raw.zp_trans_id !== undefined && { transactionId: String(raw.zp_trans_id) }),
      orderId: resolvedOrderId,
      amount: raw.amount ?? 0,
      currency: 'VND',
      status: mapReturnCodeToStatus(raw.return_code),
      gateway: this.name,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapReturnCode(raw.return_code),
          message: raw.return_message,
          gatewayCode: String(raw.return_code),
          gatewayMessage: raw.return_message,
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

    const timestamp = Date.now()
    const description = input.reason ?? `Refund for order ${input.orderId}`
    const mRefundId = buildMRefundId(this.config.appId, input.orderId, timestamp)

    // Signing: `${app_id}|${zp_trans_id}|${amount}|${description}|${timestamp}`
    const signData = `${this.config.appId}|${input.transactionId}|${input.amount}|${description}|${timestamp}`
    const mac = hmacSHA256(signData, this.config.key1)

    const body = {
      app_id: this.config.appId,
      zp_trans_id: input.transactionId,
      m_refund_id: mRefundId,
      amount: input.amount,
      description,
      timestamp,
      mac,
    }

    let raw: ZaloPayRefundResponse
    try {
      raw = await httpPost<ZaloPayRefundResponse>(`${this.baseUrl}/refund`, body, {
        timeout: this.config.timeout,
        retries: this.config.retries,
      })
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const isSuccess = raw.return_code === 1

    return {
      success: isSuccess,
      ...(raw.refund_id !== undefined && { refundId: String(raw.refund_id) }),
      transactionId: input.transactionId,
      orderId: input.orderId,
      amount: input.amount,
      status: isSuccess ? RefundStatus.SUCCESS : RefundStatus.FAILED,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapReturnCode(raw.return_code),
          message: raw.return_message,
          gatewayCode: String(raw.return_code),
          gatewayMessage: raw.return_message,
        },
      }),
    }
  }
}
