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
  networkError,
  timeoutError,
} from '../errors.js'
import { hmacSHA256, timingSafeEqual } from '../utils/crypto.js'

// ─── Config ───────────────────────────────────────────────────────────────────

export interface StripeConfig extends BaseGatewayConfig {
  /** Stripe secret key: sk_test_... (sandbox) or sk_live_... (production) */
  secretKey: string
  /** Stripe webhook signing secret: whsec_... */
  webhookSecret: string
}

// ─── API response shapes ──────────────────────────────────────────────────────

interface StripeError {
  type: string
  code?: string
  decline_code?: string
  message: string
  param?: string
}

interface StripeErrorEnvelope {
  error: StripeError
}

interface StripeCheckoutSession {
  id: string
  object: 'checkout.session'
  url: string | null
  payment_intent: string | null
  payment_status: string
  status: string
  amount_total: number | null
  currency: string
  metadata: Record<string, string>
}

interface StripePaymentIntent {
  id: string
  object: 'payment_intent'
  amount: number
  currency: string
  status: string
  description: string | null
  metadata: Record<string, string>
}

interface StripeRefund {
  id: string
  object: 'refund'
  amount: number
  status: string
  payment_intent: string
  failure_reason?: string
}

interface StripeEvent {
  id: string
  type: string
  data: {
    object: Record<string, unknown>
  }
}

// ─── Constants ────────────────────────────────────────────────────────────────

const BASE_URL = 'https://api.stripe.com/v1'
const STRIPE_API_VERSION = '2023-10-16'

// Amounts for these currencies are already in the smallest unit (no decimal subdivision).
// For all other currencies, callers must pass amounts in minor units (e.g. cents for USD).
const ZERO_DECIMAL_CURRENCIES = new Set([
  'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
  'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
])

function isZeroDecimal(currency: string): boolean {
  return ZERO_DECIMAL_CURRENCIES.has(currency.toUpperCase())
}

function toStripeAmount(amount: number, currency: string): number {
  return isZeroDecimal(currency) ? amount : Math.round(amount * 100)
}

function fromStripeAmount(amount: number, currency: string): number {
  return isZeroDecimal(currency) ? amount : amount / 100
}

// Stripe decline_code → ErrorCode
const DECLINE_CODE_MAP: Record<string, ErrorCode> = {
  insufficient_funds: ErrorCode.INSUFFICIENT_FUNDS,
  card_declined: ErrorCode.CARD_DECLINED,
  do_not_honor: ErrorCode.CARD_DECLINED,
  generic_decline: ErrorCode.CARD_DECLINED,
  lost_card: ErrorCode.CARD_LOCKED,
  stolen_card: ErrorCode.CARD_LOCKED,
  pickup_card: ErrorCode.CARD_LOCKED,
  restricted_card: ErrorCode.CARD_LOCKED,
  pin_try_exceeded: ErrorCode.CARD_LOCKED,
  card_velocity_exceeded: ErrorCode.PAYMENT_FAILED,
  fraudulent: ErrorCode.PAYMENT_FAILED,
  not_permitted: ErrorCode.PAYMENT_FAILED,
  testmode_decline: ErrorCode.PAYMENT_FAILED,
  withdrawal_count_limit_exceeded: ErrorCode.PAYMENT_FAILED,
  invalid_account: ErrorCode.PAYMENT_FAILED,
  new_account_information_available: ErrorCode.CARD_DECLINED,
  reenter_transaction: ErrorCode.PAYMENT_FAILED,
  stop_payment_order: ErrorCode.CARD_DECLINED,
  call_issuer: ErrorCode.CARD_DECLINED,
  card_not_supported: ErrorCode.CARD_DECLINED,
  expired_card: ErrorCode.CARD_DECLINED,
  incorrect_cvc: ErrorCode.AUTHENTICATION_FAILED,
  invalid_cvc: ErrorCode.AUTHENTICATION_FAILED,
  authentication_required: ErrorCode.AUTHENTICATION_FAILED,
  offline_pin_required: ErrorCode.AUTHENTICATION_FAILED,
  online_or_offline_pin_required: ErrorCode.AUTHENTICATION_FAILED,
  incorrect_number: ErrorCode.INVALID_INPUT,
  invalid_number: ErrorCode.INVALID_INPUT,
  invalid_expiry_month: ErrorCode.INVALID_INPUT,
  invalid_expiry_year: ErrorCode.INVALID_INPUT,
  incorrect_zip: ErrorCode.INVALID_INPUT,
  country_code_invalid: ErrorCode.INVALID_INPUT,
  currency_not_supported: ErrorCode.INVALID_INPUT,
  invalid_amount: ErrorCode.INVALID_AMOUNT,
  duplicate_transaction: ErrorCode.DUPLICATE_ORDER,
  issuer_not_available: ErrorCode.BANK_MAINTENANCE,
  processing_error: ErrorCode.GATEWAY_ERROR,
}

// Stripe error.type → ErrorCode (fallback when no decline_code)
const ERROR_TYPE_MAP: Record<string, ErrorCode> = {
  card_error: ErrorCode.CARD_DECLINED,
  authentication_error: ErrorCode.AUTHENTICATION_FAILED,
  invalid_request_error: ErrorCode.INVALID_INPUT,
  validation_error: ErrorCode.INVALID_INPUT,
  idempotency_error: ErrorCode.DUPLICATE_ORDER,
  api_error: ErrorCode.GATEWAY_ERROR,
  rate_limit_error: ErrorCode.GATEWAY_ERROR,
}

// Stripe event type → WebhookEventType
const EVENT_TYPE_MAP: Record<string, WebhookEventType> = {
  'payment_intent.succeeded': WebhookEventType.PAYMENT_SUCCESS,
  'payment_intent.payment_failed': WebhookEventType.PAYMENT_FAILED,
  'payment_intent.canceled': WebhookEventType.PAYMENT_CANCELLED,
  'checkout.session.completed': WebhookEventType.PAYMENT_SUCCESS,
  'checkout.session.expired': WebhookEventType.PAYMENT_FAILED,
  'charge.refunded': WebhookEventType.REFUND_SUCCESS,
  'charge.dispute.created': WebhookEventType.DISPUTE_CREATED,
}

function mapStripeError(err: StripeError): ErrorCode {
  if (err.decline_code !== undefined) {
    return DECLINE_CODE_MAP[err.decline_code] ?? ErrorCode.CARD_DECLINED
  }
  if (err.code !== undefined) {
    const byCode = DECLINE_CODE_MAP[err.code]
    if (byCode !== undefined) return byCode
  }
  return ERROR_TYPE_MAP[err.type] ?? ErrorCode.PAYMENT_FAILED
}

function mapIntentStatus(status: string): PaymentStatus {
  switch (status) {
    case 'succeeded': return PaymentStatus.SUCCESS
    case 'processing': return PaymentStatus.PROCESSING
    case 'requires_action':
    case 'requires_confirmation':
    case 'requires_capture':
    case 'requires_payment_method': return PaymentStatus.PENDING
    case 'canceled': return PaymentStatus.CANCELLED
    default: return PaymentStatus.FAILED
  }
}

function mapEventStatus(stripeEventType: string): PaymentStatus {
  switch (stripeEventType) {
    case 'payment_intent.succeeded':
    case 'checkout.session.completed':
      return PaymentStatus.SUCCESS
    case 'payment_intent.canceled':
    case 'checkout.session.expired':
      return PaymentStatus.CANCELLED
    case 'charge.refunded':
      return PaymentStatus.REFUNDED
    default:
      return PaymentStatus.FAILED
  }
}

// ─── StripeGateway ────────────────────────────────────────────────────────────

export class StripeGateway implements PaymentGateway {
  readonly name = 'stripe'

  readonly capabilities: GatewayCapabilities = {
    supportRefund: true,
    supportPartialRefund: true,
    supportRecurring: true,
    supportWebhook: true,
    supportQRCode: false,
    supportInstallment: false,
    currencies: ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'SGD', 'VND', 'THB', 'MYR'],
    paymentMethods: ['card', 'bank_transfer'],
  }

  private readonly config: Required<StripeConfig>

  constructor(config: StripeConfig) {
    if (!config.secretKey) throw invalidConfig('Stripe: secretKey is required')
    if (!config.webhookSecret) throw invalidConfig('Stripe: webhookSecret is required')

    this.config = {
      secretKey: config.secretKey,
      webhookSecret: config.webhookSecret,
      sandbox: config.sandbox ?? false,
      timeout: config.timeout ?? 30_000,
      retries: config.retries ?? 2,
    }
  }

  private defaultHeaders(): Record<string, string> {
    return {
      Authorization: `Bearer ${this.config.secretKey}`,
      'Stripe-Version': STRIPE_API_VERSION,
    }
  }

  // Stripe's API uses form-encoded bodies, not JSON.
  // Non-2xx responses contain a structured { error } envelope rather than throwing.
  private async stripePost<T>(
    path: string,
    params: Record<string, string>,
  ): Promise<{ data: T; error: null } | { data: null; error: StripeError }> {
    const url = `${BASE_URL}${path}`
    const headers = {
      ...this.defaultHeaders(),
      'Content-Type': 'application/x-www-form-urlencoded',
    }

    for (let attempt = 0; attempt <= this.config.retries; attempt++) {
      if (attempt > 0) await new Promise<void>((r) => setTimeout(r, 500 * attempt))

      const controller = new AbortController()
      const timer = setTimeout(() => controller.abort(), this.config.timeout)

      try {
        const res = await fetch(url, {
          method: 'POST',
          headers,
          body: new URLSearchParams(params).toString(),
          signal: controller.signal,
        })

        const json = (await res.json()) as T | StripeErrorEnvelope

        if (!res.ok) {
          const stripeErr = (json as StripeErrorEnvelope).error
          // Only retry transient server errors
          if (
            (stripeErr.type === 'api_error' || stripeErr.type === 'rate_limit_error') &&
            attempt < this.config.retries
          ) {
            continue
          }
          return { data: null, error: stripeErr }
        }

        return { data: json as T, error: null }
      } catch (err) {
        if (err instanceof Error && err.name === 'AbortError') {
          throw timeoutError(`Stripe POST ${path} timed out`)
        }
        throw networkError(`Stripe POST ${path} failed: ${String(err)}`)
      } finally {
        clearTimeout(timer)
      }
    }

    // Unreachable: loop always returns or throws
    /* istanbul ignore next */
    throw networkError(`Stripe POST ${path}: retries exhausted`)
  }

  private async stripeGet<T>(
    path: string,
    params?: Record<string, string>,
  ): Promise<{ data: T; error: null } | { data: null; error: StripeError }> {
    const qs = params ? new URLSearchParams(params).toString() : ''
    const url = qs ? `${BASE_URL}${path}?${qs}` : `${BASE_URL}${path}`
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), this.config.timeout)

    try {
      const res = await fetch(url, {
        method: 'GET',
        headers: this.defaultHeaders(),
        signal: controller.signal,
      })

      const json = (await res.json()) as T | StripeErrorEnvelope

      if (!res.ok) {
        return { data: null, error: (json as StripeErrorEnvelope).error }
      }

      return { data: json as T, error: null }
    } catch (err) {
      if (err instanceof Error && err.name === 'AbortError') {
        throw timeoutError(`Stripe GET ${path} timed out`)
      }
      throw networkError(`Stripe GET ${path} failed: ${String(err)}`)
    } finally {
      clearTimeout(timer)
    }
  }

  // ─── createPayment ──────────────────────────────────────────────────────────

  async createPayment(input: CreatePaymentInput): Promise<PaymentResult> {
    if (!input.orderId) throw invalidInput('orderId is required')
    if (!input.amount || input.amount <= 0) throw invalidInput('amount must be positive')
    if (!input.returnUrl) throw invalidInput('returnUrl is required')

    const now = new Date()
    const currency = (input.currency || 'USD').toLowerCase()
    const stripeAmount = toStripeAmount(input.amount, currency)

    const params: Record<string, string> = {
      mode: 'payment',
      success_url: input.returnUrl,
      cancel_url: input.cancelUrl ?? input.returnUrl,
      'line_items[0][price_data][currency]': currency,
      'line_items[0][price_data][unit_amount]': String(stripeAmount),
      'line_items[0][price_data][product_data][name]': input.description || input.orderId,
      'line_items[0][quantity]': '1',
      'metadata[orderId]': input.orderId,
    }

    if (input.customerInfo?.email !== undefined) {
      params['customer_email'] = input.customerInfo.email
    }

    if (input.expireAt !== undefined) {
      params['expires_at'] = String(Math.floor(input.expireAt.getTime() / 1000))
    }

    const result = await this.stripePost<StripeCheckoutSession>('/checkout/sessions', params)

    if (result.error !== null) {
      const err = result.error
      const gatewayCode = err.decline_code ?? err.code
      return {
        success: false,
        orderId: input.orderId,
        amount: input.amount,
        currency: currency.toUpperCase(),
        status: PaymentStatus.FAILED,
        gateway: this.name,
        rawResponse: { error: err },
        error: {
          code: mapStripeError(err),
          message: err.message,
          ...(gatewayCode !== undefined && { gatewayCode }),
          gatewayMessage: err.message,
        },
        createdAt: now,
      }
    }

    const session = result.data
    const isOpen = session.status === 'open'

    return {
      success: isOpen,
      ...(session.url !== null && { paymentUrl: session.url }),
      ...(session.payment_intent !== null && { transactionId: session.payment_intent }),
      orderId: input.orderId,
      amount: input.amount,
      currency: session.currency.toUpperCase(),
      status: isOpen ? PaymentStatus.PENDING : PaymentStatus.FAILED,
      gateway: this.name,
      rawResponse: session,
      createdAt: now,
    }
  }

  // ─── verifyWebhook ──────────────────────────────────────────────────────────

  async verifyWebhook(payload: unknown, headers: Record<string, string>): Promise<WebhookEvent> {
    // Stripe verifies against the raw request body — callers should pass it as a string or Buffer.
    // Passing a parsed object is supported but may fail if the original had non-canonical whitespace.
    let rawBody: string
    if (typeof payload === 'string') {
      rawBody = payload
    } else if (Buffer.isBuffer(payload)) {
      rawBody = payload.toString('utf8')
    } else if (typeof payload === 'object' && payload !== null) {
      rawBody = JSON.stringify(payload)
    } else {
      throw new PaymentSDKError({
        code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
        message: 'Stripe webhook payload must be a string, Buffer, or object',
      })
    }

    const sigHeader =
      headers['stripe-signature'] ?? headers['Stripe-Signature'] ?? ''

    if (!sigHeader) {
      throw new PaymentSDKError({
        code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
        message: 'Stripe webhook missing Stripe-Signature header',
      })
    }

    let timestamp = ''
    const v1Signatures: string[] = []

    for (const part of sigHeader.split(',')) {
      const eq = part.indexOf('=')
      if (eq === -1) continue
      const key = part.slice(0, eq)
      const val = part.slice(eq + 1)
      if (key === 't') timestamp = val
      if (key === 'v1') v1Signatures.push(val)
    }

    if (!timestamp || v1Signatures.length === 0) {
      throw new PaymentSDKError({
        code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
        message: 'Stripe-Signature header is malformed',
      })
    }

    const expectedSig = hmacSHA256(`${timestamp}.${rawBody}`, this.config.webhookSecret)

    const isValid = v1Signatures.some((sig) => timingSafeEqual(expectedSig, sig))
    if (!isValid) throw invalidSignature()

    let event: StripeEvent
    try {
      event = JSON.parse(rawBody) as StripeEvent
    } catch {
      throw new PaymentSDKError({
        code: ErrorCode.WEBHOOK_PROCESSING_FAILED,
        message: 'Stripe webhook payload is not valid JSON',
      })
    }

    const eventType = EVENT_TYPE_MAP[event.type] ?? WebhookEventType.PAYMENT_FAILED
    const status = mapEventStatus(event.type)
    const obj = event.data.object

    const metadata = obj['metadata'] as Record<string, string> | undefined
    const orderId = metadata?.['orderId'] ?? String(obj['client_reference_id'] ?? '')
    const transactionId = String(obj['payment_intent'] ?? obj['id'] ?? '')
    const rawCurrency = String(obj['currency'] ?? obj['amount_currency'] ?? '').toUpperCase()
    const rawAmount =
      typeof obj['amount'] === 'number'
        ? obj['amount']
        : typeof obj['amount_total'] === 'number'
        ? obj['amount_total']
        : 0
    const amount = fromStripeAmount(rawAmount, rawCurrency)

    return {
      gateway: this.name,
      eventType,
      orderId,
      transactionId,
      amount,
      currency: rawCurrency,
      status,
      rawData: event,
      receivedAt: new Date(),
    }
  }

  // ─── getTransaction ─────────────────────────────────────────────────────────

  async getTransaction(transactionId: string, orderId?: string): Promise<PaymentResult> {
    if (!transactionId) throw invalidInput('transactionId is required')

    const now = new Date()
    // Route by ID prefix: cs_ = Checkout Session, pi_ = PaymentIntent
    const path = transactionId.startsWith('cs_')
      ? `/checkout/sessions/${transactionId}`
      : `/payment_intents/${transactionId}`

    const result = await this.stripeGet<StripePaymentIntent | StripeCheckoutSession>(path)

    if (result.error !== null) {
      const err = result.error
      const errorCode =
        err.code === 'resource_missing'
          ? ErrorCode.TRANSACTION_NOT_FOUND
          : mapStripeError(err)
      return {
        success: false,
        orderId: orderId ?? '',
        amount: 0,
        currency: 'UNKNOWN',
        status: PaymentStatus.FAILED,
        gateway: this.name,
        rawResponse: { error: err },
        error: {
          code: errorCode,
          message: err.message,
          ...(err.code !== undefined && { gatewayCode: err.code }),
          gatewayMessage: err.message,
        },
        createdAt: now,
      }
    }

    const raw = result.data

    // Discriminate by object type
    if (raw.object === 'payment_intent') {
      const intent = raw as StripePaymentIntent
      const currency = intent.currency.toUpperCase()
      const resolvedOrderId = intent.metadata?.['orderId'] ?? orderId ?? ''
      const status = mapIntentStatus(intent.status)
      return {
        success: status === PaymentStatus.SUCCESS,
        transactionId: intent.id,
        orderId: resolvedOrderId,
        amount: fromStripeAmount(intent.amount, currency),
        currency,
        status,
        gateway: this.name,
        rawResponse: raw,
        createdAt: now,
      }
    }

    const session = raw as StripeCheckoutSession
    const currency = session.currency.toUpperCase()
    const isSuccess = session.payment_status === 'paid'
    const resolvedOrderId = session.metadata?.['orderId'] ?? orderId ?? ''
    const amount = session.amount_total !== null
      ? fromStripeAmount(session.amount_total, currency)
      : 0

    return {
      success: isSuccess,
      ...(session.payment_intent !== null && { transactionId: session.payment_intent }),
      orderId: resolvedOrderId,
      amount,
      currency,
      status: isSuccess ? PaymentStatus.SUCCESS : mapIntentStatus(session.status),
      gateway: this.name,
      rawResponse: raw,
      createdAt: now,
    }
  }

  // ─── refund ─────────────────────────────────────────────────────────────────

  async refund(input: RefundInput): Promise<RefundResult> {
    if (!input.transactionId) throw invalidInput('transactionId is required')
    if (!input.orderId) throw invalidInput('orderId is required')
    if (!input.amount || input.amount <= 0) throw invalidInput('amount must be positive')

    const params: Record<string, string> = {
      payment_intent: input.transactionId,
      // Amount must be in Stripe's minor unit (same unit as the original PaymentIntent)
      amount: String(input.amount),
    }

    if (input.reason !== undefined) {
      params['reason'] =
        input.reason === 'fraudulent' ? 'fraudulent'
        : input.reason === 'duplicate' ? 'duplicate'
        : 'requested_by_customer'
      params['metadata[reason]'] = input.reason
    }

    const result = await this.stripePost<StripeRefund>('/refunds', params)

    if (result.error !== null) {
      const err = result.error
      const errorCode =
        err.code === 'charge_already_refunded'
          ? ErrorCode.REFUND_ALREADY_PROCESSED
          : err.code === 'charge_exceeds_source_amount' || err.code === 'amount_too_large'
          ? ErrorCode.REFUND_AMOUNT_EXCEEDED
          : err.code === 'refund_not_supported'
          ? ErrorCode.REFUND_NOT_SUPPORTED
          : err.code === 'missing_charge'
          ? ErrorCode.TRANSACTION_NOT_FOUND
          : mapStripeError(err)
      return {
        success: false,
        transactionId: input.transactionId,
        orderId: input.orderId,
        amount: input.amount,
        status: RefundStatus.FAILED,
        rawResponse: { error: err },
        error: {
          code: errorCode,
          message: err.message,
          ...(err.code !== undefined && { gatewayCode: err.code }),
          gatewayMessage: err.message,
        },
      }
    }

    const refund = result.data
    const refundStatus =
      refund.status === 'succeeded' ? RefundStatus.SUCCESS
      : refund.status === 'pending' || refund.status === 'requires_action' ? RefundStatus.PENDING
      : refund.status === 'canceled' ? RefundStatus.REJECTED
      : RefundStatus.FAILED

    const isFailed = refundStatus === RefundStatus.FAILED

    return {
      success: refundStatus === RefundStatus.SUCCESS || refundStatus === RefundStatus.PENDING,
      refundId: refund.id,
      transactionId: input.transactionId,
      orderId: input.orderId,
      amount: input.amount,
      status: refundStatus,
      rawResponse: refund,
      ...(isFailed && {
        error: {
          code: ErrorCode.REFUND_FAILED,
          message: refund.failure_reason ?? 'Refund failed',
          ...(refund.failure_reason !== undefined && { gatewayCode: refund.failure_reason }),
          gatewayMessage: refund.failure_reason ?? 'Refund failed',
        },
      }),
    }
  }
}
