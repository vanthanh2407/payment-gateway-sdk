import { ErrorCode, type PaymentError } from './types.js'

export class PaymentSDKError extends Error {
  readonly code: ErrorCode
  readonly gatewayCode?: string
  readonly gatewayMessage?: string
  readonly details?: unknown

  constructor(error: PaymentError) {
    super(error.message)
    this.name = 'PaymentSDKError'
    this.code = error.code
    if (error.gatewayCode !== undefined) this.gatewayCode = error.gatewayCode
    if (error.gatewayMessage !== undefined) this.gatewayMessage = error.gatewayMessage
    if (error.details !== undefined) this.details = error.details
    // Maintain proper prototype chain in transpiled environments
    Object.setPrototypeOf(this, PaymentSDKError.prototype)
  }

  toPaymentError(): PaymentError {
    return {
      code: this.code,
      message: this.message,
      ...(this.gatewayCode !== undefined && { gatewayCode: this.gatewayCode }),
      ...(this.gatewayMessage !== undefined && { gatewayMessage: this.gatewayMessage }),
      ...(this.details !== undefined && { details: this.details }),
    }
  }

  static fromUnknown(err: unknown, fallbackCode = ErrorCode.UNKNOWN_ERROR): PaymentSDKError {
    if (err instanceof PaymentSDKError) return err
    if (err instanceof Error) {
      return new PaymentSDKError({
        code: fallbackCode,
        message: err.message,
        details: { stack: err.stack },
      })
    }
    return new PaymentSDKError({
      code: fallbackCode,
      message: String(err),
    })
  }
}

// ─── Factory Helpers ──────────────────────────────────────────────────────────

export function invalidConfig(message: string): PaymentSDKError {
  return new PaymentSDKError({ code: ErrorCode.INVALID_CONFIG, message })
}

export function invalidInput(message: string, details?: unknown): PaymentSDKError {
  return new PaymentSDKError({ code: ErrorCode.INVALID_INPUT, message, details })
}

export function invalidSignature(message = 'Webhook signature verification failed'): PaymentSDKError {
  return new PaymentSDKError({ code: ErrorCode.INVALID_SIGNATURE, message })
}

export function networkError(message: string, details?: unknown): PaymentSDKError {
  return new PaymentSDKError({ code: ErrorCode.NETWORK_ERROR, message, details })
}

export function timeoutError(message: string): PaymentSDKError {
  return new PaymentSDKError({ code: ErrorCode.TIMEOUT, message })
}

export function gatewayError(
  message: string,
  gatewayCode?: string,
  gatewayMessage?: string,
): PaymentSDKError {
  return new PaymentSDKError({
    code: ErrorCode.GATEWAY_ERROR,
    message,
    ...(gatewayCode !== undefined && { gatewayCode }),
    ...(gatewayMessage !== undefined && { gatewayMessage }),
  })
}

export function transactionNotFound(transactionId: string): PaymentSDKError {
  return new PaymentSDKError({
    code: ErrorCode.TRANSACTION_NOT_FOUND,
    message: `Transaction not found: ${transactionId}`,
  })
}

// ─── isRetryable ─────────────────────────────────────────────────────────────

const RETRYABLE_CODES = new Set<ErrorCode>([
  ErrorCode.NETWORK_ERROR,
  ErrorCode.TIMEOUT,
  ErrorCode.GATEWAY_ERROR,
])

export function isRetryable(err: PaymentSDKError): boolean {
  return RETRYABLE_CODES.has(err.code)
}
