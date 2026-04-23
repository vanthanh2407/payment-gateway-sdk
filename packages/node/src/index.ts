import {
  type PaymentGateway,
  type CreatePaymentInput,
  type PaymentResult,
  type WebhookEvent,
  type RefundInput,
  type RefundResult,
} from './types.js'
import { PaymentSDKError, invalidConfig } from './errors.js'

// ─── PaymentSDK ───────────────────────────────────────────────────────────────

export class PaymentSDK {
  private readonly gateways = new Map<string, PaymentGateway>()

  use(name: string, gateway: PaymentGateway): this {
    this.gateways.set(name, gateway)
    return this
  }

  gateway(name: string): PaymentGateway {
    const gw = this.gateways.get(name)
    if (!gw) {
      throw invalidConfig(
        `Gateway "${name}" is not registered. Call sdk.use("${name}", gateway) first.`,
      )
    }
    return gw
  }

  listGateways(): string[] {
    return Array.from(this.gateways.keys())
  }

  createPayment(gateway: string, input: CreatePaymentInput): Promise<PaymentResult> {
    return this.gateway(gateway).createPayment(input)
  }

  verifyWebhook(
    gateway: string,
    payload: unknown,
    headers: Record<string, string>,
  ): Promise<WebhookEvent> {
    return this.gateway(gateway).verifyWebhook(payload, headers)
  }

  getTransaction(
    gateway: string,
    transactionId: string,
    orderId?: string,
  ): Promise<PaymentResult> {
    return this.gateway(gateway).getTransaction(transactionId, orderId)
  }

  refund(gateway: string, input: RefundInput): Promise<RefundResult> {
    return this.gateway(gateway).refund(input)
  }
}

// ─── Public exports ───────────────────────────────────────────────────────────

export { VNPayGateway } from './gateways/vnpay.js'
export { MoMoGateway } from './gateways/momo.js'
export { ZaloPayGateway } from './gateways/zalopay.js'
export { PaymentSDKError } from './errors.js'

export type {
  PaymentGateway,
  GatewayCapabilities,
  CreatePaymentInput,
  PaymentResult,
  WebhookEvent,
  RefundInput,
  RefundResult,
  CustomerInfo,
  PaymentError,
  BaseGatewayConfig,
} from './types.js'

export type { VNPayConfig } from './gateways/vnpay.js'
export type { MoMoConfig } from './gateways/momo.js'
export type { ZaloPayConfig } from './gateways/zalopay.js'

export {
  PaymentStatus,
  RefundStatus,
  WebhookEventType,
  ErrorCode,
} from './types.js'
