// ─── Enums ───────────────────────────────────────────────────────────────────

export enum PaymentStatus {
  PENDING = 'PENDING',
  PROCESSING = 'PROCESSING',
  SUCCESS = 'SUCCESS',
  FAILED = 'FAILED',
  CANCELLED = 'CANCELLED',
  EXPIRED = 'EXPIRED',
  REFUNDED = 'REFUNDED',
  PARTIAL_REFUNDED = 'PARTIAL_REFUNDED',
}

export enum RefundStatus {
  PENDING = 'PENDING',
  SUCCESS = 'SUCCESS',
  FAILED = 'FAILED',
  REJECTED = 'REJECTED',
}

export enum WebhookEventType {
  PAYMENT_SUCCESS = 'PAYMENT_SUCCESS',
  PAYMENT_FAILED = 'PAYMENT_FAILED',
  PAYMENT_CANCELLED = 'PAYMENT_CANCELLED',
  REFUND_SUCCESS = 'REFUND_SUCCESS',
  REFUND_FAILED = 'REFUND_FAILED',
  DISPUTE_CREATED = 'DISPUTE_CREATED',
}

// ─── Input / Output Shapes ────────────────────────────────────────────────────

export interface CustomerInfo {
  name?: string
  email?: string
  phone?: string
  ipAddress?: string
}

export interface CreatePaymentInput {
  orderId: string
  amount: number
  currency: string
  description: string
  returnUrl: string
  cancelUrl?: string
  ipnUrl?: string
  customerInfo?: CustomerInfo
  metadata?: Record<string, unknown>
  locale?: 'vi' | 'en'
  expireAt?: Date
}

export interface PaymentError {
  code: ErrorCode
  message: string
  gatewayCode?: string
  gatewayMessage?: string
  details?: unknown
}

export interface PaymentResult {
  success: boolean
  paymentUrl?: string
  transactionId?: string
  orderId: string
  amount: number
  currency: string
  status: PaymentStatus
  gateway: string
  rawResponse?: unknown
  error?: PaymentError
  createdAt: Date
}

export interface WebhookEvent {
  gateway: string
  eventType: WebhookEventType
  orderId: string
  transactionId: string
  amount: number
  currency: string
  status: PaymentStatus
  rawData: unknown
  receivedAt: Date
}

export interface RefundInput {
  transactionId: string
  orderId: string
  amount: number
  reason?: string
}

export interface RefundResult {
  success: boolean
  refundId?: string
  transactionId: string
  orderId: string
  amount: number
  status: RefundStatus
  rawResponse?: unknown
  error?: PaymentError
}

export interface GatewayCapabilities {
  supportRefund: boolean
  supportPartialRefund: boolean
  supportRecurring: boolean
  supportWebhook: boolean
  supportQRCode: boolean
  supportInstallment: boolean
  currencies: string[]
  paymentMethods: string[]
}

// ─── Gateway Interface ────────────────────────────────────────────────────────

export interface PaymentGateway {
  readonly name: string
  readonly capabilities: GatewayCapabilities
  createPayment(input: CreatePaymentInput): Promise<PaymentResult>
  verifyWebhook(payload: unknown, headers: Record<string, string>): Promise<WebhookEvent>
  getTransaction(transactionId: string, orderId?: string): Promise<PaymentResult>
  refund(input: RefundInput): Promise<RefundResult>
}

// ─── Gateway Config Base ──────────────────────────────────────────────────────

export interface BaseGatewayConfig {
  sandbox?: boolean
  timeout?: number
  retries?: number
}

// ─── Error Codes ──────────────────────────────────────────────────────────────

export enum ErrorCode {
  // General
  UNKNOWN_ERROR = 'UNKNOWN_ERROR',
  INVALID_CONFIG = 'INVALID_CONFIG',
  INVALID_INPUT = 'INVALID_INPUT',
  NETWORK_ERROR = 'NETWORK_ERROR',
  TIMEOUT = 'TIMEOUT',
  GATEWAY_ERROR = 'GATEWAY_ERROR',

  // Payment
  PAYMENT_FAILED = 'PAYMENT_FAILED',
  PAYMENT_CANCELLED = 'PAYMENT_CANCELLED',
  PAYMENT_EXPIRED = 'PAYMENT_EXPIRED',
  INVALID_AMOUNT = 'INVALID_AMOUNT',
  DUPLICATE_ORDER = 'DUPLICATE_ORDER',
  INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS',
  CARD_DECLINED = 'CARD_DECLINED',
  CARD_LOCKED = 'CARD_LOCKED',
  AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED',
  BANK_MAINTENANCE = 'BANK_MAINTENANCE',

  // Webhook
  INVALID_SIGNATURE = 'INVALID_SIGNATURE',
  WEBHOOK_PROCESSING_FAILED = 'WEBHOOK_PROCESSING_FAILED',

  // Transaction
  TRANSACTION_NOT_FOUND = 'TRANSACTION_NOT_FOUND',

  // Refund
  REFUND_FAILED = 'REFUND_FAILED',
  REFUND_NOT_SUPPORTED = 'REFUND_NOT_SUPPORTED',
  REFUND_AMOUNT_EXCEEDED = 'REFUND_AMOUNT_EXCEEDED',
  REFUND_ALREADY_PROCESSED = 'REFUND_ALREADY_PROCESSED',
  REFUND_WINDOW_EXPIRED = 'REFUND_WINDOW_EXPIRED',
}
