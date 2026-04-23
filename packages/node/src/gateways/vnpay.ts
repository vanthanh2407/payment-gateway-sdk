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
import { hmacSHA512, formatVNPayDate, buildSortedQueryString, timingSafeEqual } from '../utils/crypto.js'
import { httpPost } from '../utils/http.js'

// ─── Config ───────────────────────────────────────────────────────────────────

export interface VNPayConfig extends BaseGatewayConfig {
  tmnCode: string
  hashSecret: string
}

// ─── VNPay API shapes ─────────────────────────────────────────────────────────

interface VNPayQueryResponse {
  vnp_ResponseCode: string
  vnp_Message: string
  vnp_TransactionStatus: string
  vnp_TxnRef: string
  vnp_Amount: string
  vnp_BankCode?: string
  vnp_PayDate?: string
  vnp_TransactionNo?: string
  vnp_SecureHash: string
}

interface VNPayRefundResponse {
  vnp_ResponseCode: string
  vnp_Message: string
  vnp_TransactionStatus: string
  vnp_TxnRef: string
  vnp_Amount: string
  vnp_TransactionNo?: string
  vnp_SecureHash: string
}

// ─── Constants ────────────────────────────────────────────────────────────────

const PAYMENT_URL = {
  sandbox: 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
  production: 'https://vnpayment.vn/paymentv2/vpcpay.html',
} as const

const API_URL = {
  sandbox: 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction',
  production: 'https://vnpayment.vn/merchant_webapi/api/transaction',
} as const

// VNPay response code → ErrorCode mapping (per specs/error-codes.md)
const RESPONSE_CODE_MAP: Record<string, ErrorCode> = {
  '07': ErrorCode.PAYMENT_FAILED,
  '09': ErrorCode.AUTHENTICATION_FAILED,
  '10': ErrorCode.AUTHENTICATION_FAILED,
  '11': ErrorCode.PAYMENT_EXPIRED,
  '12': ErrorCode.CARD_LOCKED,
  '13': ErrorCode.AUTHENTICATION_FAILED,
  '24': ErrorCode.PAYMENT_CANCELLED,
  '51': ErrorCode.INSUFFICIENT_FUNDS,
  '65': ErrorCode.PAYMENT_FAILED,
  '75': ErrorCode.BANK_MAINTENANCE,
  '79': ErrorCode.AUTHENTICATION_FAILED,
}

function mapResponseCode(code: string): ErrorCode {
  return RESPONSE_CODE_MAP[code] ?? ErrorCode.UNKNOWN_ERROR
}

function mapTransactionStatus(
  transactionStatus: string,
  responseCode: string,
): PaymentStatus {
  if (transactionStatus === '00') return PaymentStatus.SUCCESS
  if (responseCode === '24') return PaymentStatus.CANCELLED
  if (responseCode === '11') return PaymentStatus.EXPIRED
  return PaymentStatus.FAILED
}

// ─── VNPayGateway ─────────────────────────────────────────────────────────────

export class VNPayGateway implements PaymentGateway {
  readonly name = 'vnpay'
  readonly capabilities: GatewayCapabilities = {
    supportRefund: true,
    supportPartialRefund: false,
    supportRecurring: false,
    supportWebhook: true,
    supportQRCode: true,
    supportInstallment: false,
    currencies: ['VND'],
    paymentMethods: ['card', 'banking', 'qr'],
  }

  private readonly config: Required<VNPayConfig>

  constructor(config: VNPayConfig) {
    if (!config.tmnCode) throw invalidConfig('VNPay: tmnCode is required')
    if (!config.hashSecret) throw invalidConfig('VNPay: hashSecret is required')

    this.config = {
      tmnCode: config.tmnCode,
      hashSecret: config.hashSecret,
      sandbox: config.sandbox ?? false,
      timeout: config.timeout ?? 30_000,
      retries: config.retries ?? 2,
    }
  }

  // ─── createPayment ───────────────────────────────────────────────────────

  async createPayment(input: CreatePaymentInput): Promise<PaymentResult> {
    if (!input.orderId) throw invalidInput('orderId is required')
    if (!input.amount || input.amount <= 0) throw invalidInput('amount must be positive')
    if (input.currency && input.currency !== 'VND') {
      throw invalidInput('VNPay only supports VND currency')
    }

    const now = new Date()
    const params: Record<string, string> = {
      vnp_Version: '2.1.0',
      vnp_Command: 'pay',
      vnp_TmnCode: this.config.tmnCode,
      vnp_Locale: input.locale ?? 'vn',
      vnp_CurrCode: 'VND',
      vnp_TxnRef: input.orderId,
      vnp_OrderInfo: input.description,
      vnp_OrderType: 'other',
      vnp_Amount: String(input.amount * 100),
      vnp_ReturnUrl: input.returnUrl,
      vnp_IpAddr: input.customerInfo?.ipAddress ?? '127.0.0.1',
      vnp_CreateDate: formatVNPayDate(now),
    }

    if (input.expireAt) {
      params['vnp_ExpireDate'] = formatVNPayDate(input.expireAt)
    }

    const queryString = buildSortedQueryString(params)
    const secureHash = hmacSHA512(queryString, this.config.hashSecret)
    const baseUrl = this.config.sandbox ? PAYMENT_URL.sandbox : PAYMENT_URL.production
    const paymentUrl = `${baseUrl}?${queryString}&vnp_SecureHash=${secureHash}`

    return {
      success: true,
      paymentUrl,
      orderId: input.orderId,
      amount: input.amount,
      currency: 'VND',
      status: PaymentStatus.PENDING,
      gateway: this.name,
      rawResponse: { params, queryString },
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
        message: 'VNPay webhook payload must be an object',
      })
    }

    const params = payload as Record<string, string>
    const receivedHash = params['vnp_SecureHash']
    if (!receivedHash) throw invalidSignature('Missing vnp_SecureHash')

    // Build the string to verify: remove hash fields, sort, re-sign
    const signParams: Record<string, string> = {}
    for (const [key, val] of Object.entries(params)) {
      if (key !== 'vnp_SecureHash' && key !== 'vnp_SecureHashType') {
        signParams[key] = val
      }
    }

    const queryString = buildSortedQueryString(signParams)
    const expectedHash = hmacSHA512(queryString, this.config.hashSecret)

    if (!timingSafeEqual(expectedHash, receivedHash.toLowerCase())) {
      throw invalidSignature()
    }

    const responseCode = params['vnp_ResponseCode'] ?? '99'
    const transactionStatus = params['vnp_TransactionStatus'] ?? '99'
    const status = mapTransactionStatus(transactionStatus, responseCode)

    const eventType =
      status === PaymentStatus.SUCCESS
        ? WebhookEventType.PAYMENT_SUCCESS
        : status === PaymentStatus.CANCELLED
          ? WebhookEventType.PAYMENT_CANCELLED
          : WebhookEventType.PAYMENT_FAILED

    const rawAmount = params['vnp_Amount'] ?? '0'

    return {
      gateway: this.name,
      eventType,
      orderId: params['vnp_TxnRef'] ?? '',
      transactionId: params['vnp_TransactionNo'] ?? '',
      amount: Math.round(Number(rawAmount) / 100),
      currency: 'VND',
      status,
      rawData: params,
      receivedAt: new Date(),
    }
  }

  // ─── getTransaction ──────────────────────────────────────────────────────

  async getTransaction(transactionId: string, orderId?: string): Promise<PaymentResult> {
    if (!orderId) throw invalidInput('VNPay getTransaction requires orderId')

    const now = new Date()
    const createDate = formatVNPayDate(now)

    const params: Record<string, string> = {
      vnp_RequestId: `${Date.now()}`,
      vnp_Version: '2.1.0',
      vnp_Command: 'querydr',
      vnp_TmnCode: this.config.tmnCode,
      vnp_TxnRef: orderId,
      vnp_OrderInfo: `Query transaction ${orderId}`,
      vnp_TransactionDate: createDate,
      vnp_CreateDate: createDate,
      vnp_IpAddr: '127.0.0.1',
    }

    const signData = [
      params['vnp_RequestId'],
      params['vnp_Version'],
      params['vnp_Command'],
      params['vnp_TmnCode'],
      params['vnp_TxnRef'],
      params['vnp_TransactionDate'],
      params['vnp_CreateDate'],
      params['vnp_IpAddr'],
      params['vnp_OrderInfo'],
    ].join('|')

    params['vnp_SecureHash'] = hmacSHA512(signData, this.config.hashSecret)

    const apiUrl = this.config.sandbox ? API_URL.sandbox : API_URL.production

    let raw: VNPayQueryResponse
    try {
      raw = await httpPost<VNPayQueryResponse>(apiUrl, params, {
        timeout: this.config.timeout,
        retries: this.config.retries,
      })
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const responseCode = raw.vnp_ResponseCode
    const txStatus = raw.vnp_TransactionStatus ?? '99'
    const isSuccess = responseCode === '00' && txStatus === '00'

    if (responseCode === '91') {
      throw new PaymentSDKError({
        code: ErrorCode.TRANSACTION_NOT_FOUND,
        message: `Transaction not found: ${transactionId}`,
        gatewayCode: responseCode,
        gatewayMessage: raw.vnp_Message,
      })
    }

    return {
      success: isSuccess,
      transactionId: raw.vnp_TransactionNo ?? transactionId,
      orderId: raw.vnp_TxnRef,
      amount: Math.round(Number(raw.vnp_Amount) / 100),
      currency: 'VND',
      status: isSuccess
        ? PaymentStatus.SUCCESS
        : mapTransactionStatus(txStatus, responseCode),
      gateway: this.name,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapResponseCode(responseCode),
          message: raw.vnp_Message,
          gatewayCode: responseCode,
          gatewayMessage: raw.vnp_Message,
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

    const now = new Date()
    const createDate = formatVNPayDate(now)

    const params: Record<string, string> = {
      vnp_RequestId: `${Date.now()}`,
      vnp_Version: '2.1.0',
      vnp_Command: 'refund',
      vnp_TmnCode: this.config.tmnCode,
      vnp_TransactionType: '02',
      vnp_TxnRef: input.orderId,
      vnp_Amount: String(input.amount * 100),
      vnp_OrderInfo: input.reason ?? `Refund for order ${input.orderId}`,
      vnp_TransactionNo: input.transactionId,
      vnp_TransactionDate: createDate,
      vnp_CreateDate: createDate,
      vnp_CreateBy: 'sdk',
      vnp_IpAddr: '127.0.0.1',
    }

    const signData = [
      params['vnp_RequestId'],
      params['vnp_Version'],
      params['vnp_Command'],
      params['vnp_TmnCode'],
      params['vnp_TransactionType'],
      params['vnp_TxnRef'],
      params['vnp_Amount'],
      params['vnp_TransactionNo'],
      params['vnp_TransactionDate'],
      params['vnp_CreateBy'],
      params['vnp_CreateDate'],
      params['vnp_IpAddr'],
      params['vnp_OrderInfo'],
    ].join('|')

    params['vnp_SecureHash'] = hmacSHA512(signData, this.config.hashSecret)

    const apiUrl = this.config.sandbox ? API_URL.sandbox : API_URL.production

    let raw: VNPayRefundResponse
    try {
      raw = await httpPost<VNPayRefundResponse>(apiUrl, params, {
        timeout: this.config.timeout,
        retries: this.config.retries,
      })
    } catch (err) {
      throw PaymentSDKError.fromUnknown(err)
    }

    const isSuccess = raw.vnp_ResponseCode === '00' && raw.vnp_TransactionStatus === '00'

    return {
      success: isSuccess,
      ...(raw.vnp_TransactionNo !== undefined && { refundId: raw.vnp_TransactionNo }),
      transactionId: input.transactionId,
      orderId: input.orderId,
      amount: input.amount,
      status: isSuccess ? RefundStatus.SUCCESS : RefundStatus.FAILED,
      rawResponse: raw,
      ...(!isSuccess && {
        error: {
          code: mapResponseCode(raw.vnp_ResponseCode),
          message: raw.vnp_Message,
          gatewayCode: raw.vnp_ResponseCode,
          gatewayMessage: raw.vnp_Message,
        },
      }),
    }
  }
}
