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
import { hmacSHA256, buildRawString, timingSafeEqual } from '../utils/crypto.js'
import { httpPost } from '../utils/http.js'

// ─── Config ───────────────────────────────────────────────────────────────────

export interface MoMoConfig extends BaseGatewayConfig {
  partnerCode: string
  accessKey: string
  secretKey: string
}

// ─── MoMo API shapes ──────────────────────────────────────────────────────────

interface MoMoCreateResponse {
  partnerCode: string
  requestId: string
  orderId: string
  amount: number
  responseTime: number
  message: string
  resultCode: number
  payUrl?: string
  deeplink?: string
  qrCodeUrl?: string
  deeplinkMiniApp?: string
}

interface MoMoQueryResponse {
  partnerCode: string
  requestId: string
  orderId: string
  extraData: string
  amount: number
  transId: number
  payType: string
  resultCode: number
  message: string
  responseTime: number
  orderInfo: string
  type: number
  refundTrans?: Array<{ transId: number; amount: number; createdTime: number }>
}

interface MoMoRefundResponse {
  partnerCode: string
  orderId: string
  requestId: string
  amount: number
  transId: number
  resultCode: number
  message: string
  responseTime: number
}

interface MoMoWebhookPayload {
  partnerCode: string
  orderId: string
  requestId: string
  amount: number
  orderInfo: string
  orderType: string
  transId: number
  resultCode: number
  message: string
  payType: string
  responseTime: number
  extraData: string
  signature: string
}

// ─── Constants ────────────────────────────────────────────────────────────────

const API_URL = {
  sandbox: 'https://test-payment.momo.vn/v2/gateway/api',
  production: 'https://payment.momo.vn/v2/gateway/api',
} as const

// MoMo resultCode → ErrorCode mapping (per specs/error-codes.md)
const RESULT_CODE_MAP: Record<number, ErrorCode> = {
  1001: ErrorCode.INSUFFICIENT_FUNDS,
  1002: ErrorCode.CARD_DECLINED,
  1003: ErrorCode.PAYMENT_FAILED,
  1004: ErrorCode.PAYMENT_FAILED,
  1005: ErrorCode.PAYMENT_EXPIRED,
  1006: ErrorCode.PAYMENT_CANCELLED,
  1007: ErrorCode.PAYMENT_FAILED,
  1026: ErrorCode.PAYMENT_FAILED,
  1080: ErrorCode.REFUND_FAILED,
  1081: ErrorCode.REFUND_FAILED,
  2001: ErrorCode.AUTHENTICATION_FAILED,
  2007: ErrorCode.AUTHENTICATION_FAILED,
  4001: ErrorCode.AUTHENTICATION_FAILED,
  4100: ErrorCode.AUTHENTICATION_FAILED,
  7002: ErrorCode.PAYMENT_FAILED,
  9001: ErrorCode.DUPLICATE_ORDER,
}

function mapResultCode(code: number): ErrorCode {
  return RESULT_CODE_MAP[code] ?? ErrorCode.UNKNOWN_ERROR
}

function mapResultCodeToStatus(code: number): PaymentStatus {
  if (code === 0) return PaymentStatus.SUCCESS
  if (code === 1000 || code === 7000 || code === 8000) return PaymentStatus.PROCESSING
  if (code === 1006) return PaymentStatus.CANCELLED
  if (code === 1005) return PaymentStatus.EXPIRED
  if (code === 9000) return PaymentStatus.REFUNDED
  return PaymentStatus.FAILED
}

// ─── MoMoGateway ─────────────────────────────────────────────────────────────

export class MoMoGateway implements PaymentGateway {
  readonly name = 'momo'
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

  private readonly config: Required<MoMoConfig>

  constructor(config: MoMoConfig) {
    if (!config.partnerCode) throw invalidConfig('MoMo: partnerCode is required')
    if (!config.accessKey) throw invalidConfig('MoMo: accessKey is required')
    if (!config.secretKey) throw invalidConfig('MoMo: secretKey is required')

    this.config = {
      partnerCode: config.partnerCode,
      accessKey: config.accessKey,
      secretKey: config.secretKey,
      sandbox: config.sandbox ?? false,
      timeout: config.timeout ?? 30_000,
      retries: config.retries ?? 2,
    }
  }

  private get baseUrl(): string {
    return this.config.sandbox ? API_URL.sandbox : API_URL.production
  }

  private buildRequestId(orderId: string): string {
    return `${orderId}_${Date.now()}`
  }

  // ─── createPayment ───────────────────────────────────────────────────────

  async createPayment(input: CreatePaymentInput): Promise<PaymentResult> {
    if (!input.orderId) throw invalidInput('orderId is required')
    if (!input.amount || input.amount <= 0) throw invalidInput('amount must be positive')
    if (input.currency && input.currency !== 'VND') {
      throw invalidInput('MoMo only supports VND currency')
    }

    const now = new Date()
    const requestId = this.buildRequestId(input.orderId)
    const extraData = input.metadata
      ? Buffer.from(JSON.stringify(input.metadata)).toString('base64')
      : ''

    const requestType = 'payWithMethod'
    const ipnUrl = input.ipnUrl ?? ''

    // MoMo rawHash keys must be in exactly this order
    const rawHashKeys = [
      'accessKey',
      'amount',
      'extraData',
      'ipnUrl',
      'orderId',
      'orderInfo',
      'partnerCode',
      'redirectUrl',
      'requestId',
      'requestType',
    ] as const

    const signParams: Record<string, string> = {
      accessKey: this.config.accessKey,
      amount: String(input.amount),
      extraData,
      ipnUrl,
      orderId: input.orderId,
      orderInfo: input.description,
      partnerCode: this.config.partnerCode,
      redirectUrl: input.returnUrl,
      requestId,
      requestType,
    }

    const rawHash = buildRawString(signParams, [...rawHashKeys])
    const signature = hmacSHA256(rawHash, this.config.secretKey)

    const body = {
      partnerCode: this.config.partnerCode,
      accessKey: this.config.accessKey,
      requestId,
      amount: input.amount,
      orderId: input.orderId,
      orderInfo: input.description,
      redirectUrl: input.returnUrl,
      ipnUrl,
      extraData,
      requestType,
      signature,
      lang: input.locale === 'en' ? 'en' : 'vi',
    }

    let raw: MoMoCreateResponse
    try {
      raw = await httpPost<MoMoCreateResponse>(`${this.baseUrl}/create`, body, {
        timeout: this.config.timeout,
        retries: this.config.retries,
      })
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const isSuccess = raw.resultCode === 0

    return {
      success: isSuccess,
      ...(raw.payUrl !== undefined && { paymentUrl: raw.payUrl }),
      orderId: input.orderId,
      amount: input.amount,
      currency: 'VND',
      status: isSuccess ? PaymentStatus.PENDING : mapResultCodeToStatus(raw.resultCode),
      gateway: this.name,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapResultCode(raw.resultCode),
          message: raw.message,
          gatewayCode: String(raw.resultCode),
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
        message: 'MoMo webhook payload must be an object',
      })
    }

    const data = payload as MoMoWebhookPayload
    if (!data.signature) throw invalidSignature('Missing MoMo signature')

    // MoMo webhook verification rawHash keys (in exact order)
    const rawHashKeys = [
      'accessKey',
      'amount',
      'extraData',
      'message',
      'orderId',
      'orderInfo',
      'orderType',
      'partnerCode',
      'payType',
      'requestId',
      'responseTime',
      'resultCode',
      'transId',
    ] as const

    const signParams: Record<string, string> = {
      accessKey: this.config.accessKey,
      amount: String(data.amount),
      extraData: data.extraData ?? '',
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
    const expectedSig = hmacSHA256(rawHash, this.config.secretKey)

    if (!timingSafeEqual(expectedSig, data.signature)) {
      throw invalidSignature()
    }

    const status = mapResultCodeToStatus(data.resultCode)
    const eventType =
      status === PaymentStatus.SUCCESS
        ? WebhookEventType.PAYMENT_SUCCESS
        : status === PaymentStatus.CANCELLED
          ? WebhookEventType.PAYMENT_CANCELLED
          : WebhookEventType.PAYMENT_FAILED

    return {
      gateway: this.name,
      eventType,
      orderId: data.orderId,
      transactionId: String(data.transId),
      amount: data.amount,
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
    const requestId = this.buildRequestId(resolvedOrderId)

    const rawHashKeys = ['accessKey', 'orderId', 'partnerCode', 'requestId'] as const

    const signParams: Record<string, string> = {
      accessKey: this.config.accessKey,
      orderId: resolvedOrderId,
      partnerCode: this.config.partnerCode,
      requestId,
    }

    const rawHash = buildRawString(signParams, [...rawHashKeys])
    const signature = hmacSHA256(rawHash, this.config.secretKey)

    const body = {
      partnerCode: this.config.partnerCode,
      accessKey: this.config.accessKey,
      requestId,
      orderId: resolvedOrderId,
      signature,
      lang: 'vi',
    }

    let raw: MoMoQueryResponse
    try {
      raw = await httpPost<MoMoQueryResponse>(`${this.baseUrl}/query`, body, {
        timeout: this.config.timeout,
        retries: this.config.retries,
      })
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const isSuccess = raw.resultCode === 0

    return {
      success: isSuccess,
      transactionId: String(raw.transId),
      orderId: raw.orderId,
      amount: raw.amount,
      currency: 'VND',
      status: mapResultCodeToStatus(raw.resultCode),
      gateway: this.name,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapResultCode(raw.resultCode),
          message: raw.message,
          gatewayCode: String(raw.resultCode),
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

    const requestId = this.buildRequestId(input.orderId)
    const description = input.reason ?? `Refund for order ${input.orderId}`

    const rawHashKeys = [
      'accessKey',
      'amount',
      'description',
      'orderId',
      'partnerCode',
      'requestId',
      'transId',
    ] as const

    const signParams: Record<string, string> = {
      accessKey: this.config.accessKey,
      amount: String(input.amount),
      description,
      orderId: input.orderId,
      partnerCode: this.config.partnerCode,
      requestId,
      transId: input.transactionId,
    }

    const rawHash = buildRawString(signParams, [...rawHashKeys])
    const signature = hmacSHA256(rawHash, this.config.secretKey)

    const body = {
      partnerCode: this.config.partnerCode,
      accessKey: this.config.accessKey,
      requestId,
      amount: input.amount,
      orderId: input.orderId,
      transId: Number(input.transactionId),
      description,
      signature,
      lang: 'vi',
    }

    let raw: MoMoRefundResponse
    try {
      raw = await httpPost<MoMoRefundResponse>(`${this.baseUrl}/refund`, body, {
        timeout: this.config.timeout,
        retries: this.config.retries,
      })
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const isSuccess = raw.resultCode === 0

    return {
      success: isSuccess,
      refundId: String(raw.transId),
      transactionId: input.transactionId,
      orderId: input.orderId,
      amount: raw.amount,
      status: isSuccess ? RefundStatus.SUCCESS : RefundStatus.FAILED,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapResultCode(raw.resultCode),
          message: raw.message,
          gatewayCode: String(raw.resultCode),
          gatewayMessage: raw.message,
        },
      }),
    }
  }
}
