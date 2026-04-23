# Payment SDK — Interface Definitions

This document is the **source of truth** for all language implementations (Node.js, Go, PHP).
Any change to interfaces here must be ported to all packages before merging.

---

## Table of Contents

1. [Core Types](#core-types)
2. [CreatePaymentInput](#createpaymentinput)
3. [PaymentResult](#paymentresult)
4. [WebhookEvent](#webhookevent)
5. [RefundInput](#refindinput)
6. [RefundResult](#refundresult)
7. [GatewayCapabilities](#gatewaycapabilities)
8. [PaymentGateway Interface](#paymentgateway-interface)
9. [PaymentSDK](#paymentsdk)

---

## Core Types

### PaymentStatus

Represents the current state of a payment transaction.

| Value         | Description                                      |
|---------------|--------------------------------------------------|
| `PENDING`     | Payment initiated, awaiting user action          |
| `PROCESSING`  | Payment in progress at gateway                   |
| `SUCCESS`     | Payment completed successfully                   |
| `FAILED`      | Payment failed                                   |
| `CANCELLED`   | User cancelled the payment                       |
| `EXPIRED`     | Payment session expired                          |
| `REFUNDED`    | Payment was fully refunded                       |
| `PARTIAL_REFUNDED` | Payment was partially refunded              |

### RefundStatus

| Value         | Description                              |
|---------------|------------------------------------------|
| `PENDING`     | Refund initiated, awaiting processing    |
| `SUCCESS`     | Refund completed successfully            |
| `FAILED`      | Refund failed                            |
| `REJECTED`    | Refund rejected by gateway               |

### WebhookEventType

| Value                 | Description                            |
|-----------------------|----------------------------------------|
| `PAYMENT_SUCCESS`     | Payment completed successfully         |
| `PAYMENT_FAILED`      | Payment failed                         |
| `PAYMENT_CANCELLED`   | Payment was cancelled                  |
| `REFUND_SUCCESS`      | Refund completed                       |
| `REFUND_FAILED`       | Refund failed                          |
| `DISPUTE_CREATED`     | Dispute/chargeback created             |

---

## CreatePaymentInput

Input required to initiate a payment. All gateway implementations must accept this shape.

```
CreatePaymentInput {
  orderId:       string          // Unique order ID from merchant system (required)
  amount:        number          // Amount in smallest currency unit, e.g. VND (required)
  currency:      string          // ISO 4217 currency code, e.g. "VND", "USD" (required)
  description:   string          // Order description shown to user (required)
  returnUrl:     string          // URL to redirect after payment (required)
  cancelUrl?:    string          // URL to redirect on cancellation (optional)
  ipnUrl?:       string          // Webhook/IPN URL for server-to-server notification (optional)
  customerInfo?: {
    name?:       string          // Customer full name
    email?:      string          // Customer email address
    phone?:      string          // Customer phone number
    ipAddress?:  string          // Customer IP address (used by some gateways)
  }
  metadata?:     Record<string, unknown>  // Arbitrary key-value pairs, passed through to rawResponse
  locale?:       string          // Language preference: "vi" | "en" (default: "vi")
  expireAt?:     Date            // Payment session expiry time
}
```

**Constraints:**
- `orderId` must be unique per transaction; gateways will reject duplicate IDs
- `amount` must be a positive integer for VND (no decimals)
- `currency` must match gateway's supported currencies (see `GatewayCapabilities.currencies`)
- `returnUrl` must be publicly accessible HTTPS URL in production

---

## PaymentResult

Returned by `createPayment()` and `getTransaction()`.

```
PaymentResult {
  success:        boolean         // Whether the operation itself succeeded
  paymentUrl?:    string          // Redirect URL for user to complete payment (createPayment only)
  transactionId?: string          // Gateway-assigned transaction ID (available after completion)
  orderId:        string          // Echo of input orderId
  amount:         number          // Amount in smallest unit
  currency:       string          // ISO 4217 currency code
  status:         PaymentStatus   // Current payment status
  gateway:        string          // Gateway name, e.g. "vnpay" | "momo"
  rawResponse?:   unknown         // Raw response from gateway, for debugging
  error?:         PaymentError    // Populated when success = false
  createdAt:      Date            // Timestamp of this result
}
```

**Notes:**
- `success: true` on `createPayment` means the payment URL was created, NOT that payment is complete
- `success: false` must always have `error` populated
- `rawResponse` is always included regardless of success/failure

---

## WebhookEvent

Returned by `verifyWebhook()`. Represents a parsed and verified server-to-server notification.

```
WebhookEvent {
  gateway:        string          // Gateway name
  eventType:      WebhookEventType
  orderId:        string          // Merchant order ID
  transactionId:  string          // Gateway transaction ID
  amount:         number          // Transaction amount
  currency:       string          // ISO 4217
  status:         PaymentStatus   // Resulting payment status
  rawData:        unknown         // Original webhook payload, for audit logging
  receivedAt:     Date            // Timestamp when webhook was received
}
```

**Notes:**
- `verifyWebhook()` must throw `PaymentError` with code `INVALID_SIGNATURE` if signature check fails
- Callers must respond to the gateway with the appropriate ack format (gateway-specific)
- `rawData` should be logged for audit purposes

---

## RefundInput

```
RefundInput {
  transactionId:  string          // Gateway transaction ID to refund (required)
  orderId:        string          // Original merchant order ID (required)
  amount:         number          // Amount to refund in smallest unit (required)
  reason?:        string          // Refund reason (optional, shown in gateway dashboard)
}
```

**Constraints:**
- `amount` must not exceed the original transaction amount
- For partial refunds, gateway must support it (check `GatewayCapabilities.supportPartialRefund`)

---

## RefundResult

```
RefundResult {
  success:        boolean         // Whether refund was initiated/completed
  refundId?:      string          // Gateway-assigned refund ID
  transactionId:  string          // Original transaction ID
  orderId:        string          // Original order ID
  amount:         number          // Amount refunded
  status:         RefundStatus    // Current refund status
  rawResponse?:   unknown         // Raw gateway response
  error?:         PaymentError    // Populated when success = false
}
```

---

## GatewayCapabilities

Describes what a gateway implementation supports. Available as a static property on each gateway.

```
GatewayCapabilities {
  supportRefund:         boolean     // Can issue refunds
  supportPartialRefund:  boolean     // Can issue partial refunds
  supportRecurring:      boolean     // Supports recurring/subscription payments
  supportWebhook:        boolean     // Supports server-to-server webhook
  supportQRCode:         boolean     // Supports QR code payment
  supportInstallment:    boolean     // Supports installment payments
  currencies:            string[]    // Supported ISO 4217 currency codes
  paymentMethods:        string[]    // e.g. ["card", "wallet", "banking", "qr"]
}
```

---

## PaymentError

Standardized error object. All errors thrown by gateways must be of this type.

```
PaymentError {
  code:           ErrorCode       // Standardized error code (see error-codes.md)
  message:        string          // Human-readable message
  gatewayCode?:   string          // Original error code from gateway
  gatewayMessage?: string         // Original error message from gateway
  details?:       unknown         // Additional context
}
```

---

## PaymentGateway Interface

All gateways must implement this interface exactly.

```
interface PaymentGateway {
  readonly name: string                                       // e.g. "vnpay"
  readonly capabilities: GatewayCapabilities

  createPayment(input: CreatePaymentInput): Promise<PaymentResult>
  verifyWebhook(
    payload: unknown,
    headers: Record<string, string>
  ): Promise<WebhookEvent>
  getTransaction(
    transactionId: string,
    orderId?: string
  ): Promise<PaymentResult>
  refund(input: RefundInput): Promise<RefundResult>
}
```

---

## PaymentSDK

Top-level SDK entry point that manages gateway instances.

```
class PaymentSDK {
  use(name: string, gateway: PaymentGateway): this
  gateway(name: string): PaymentGateway        // Throws if not registered
  listGateways(): string[]

  // Convenience — delegates to the named gateway
  createPayment(gateway: string, input: CreatePaymentInput): Promise<PaymentResult>
  verifyWebhook(gateway: string, payload: unknown, headers: Record<string, string>): Promise<WebhookEvent>
  getTransaction(gateway: string, transactionId: string, orderId?: string): Promise<PaymentResult>
  refund(gateway: string, input: RefundInput): Promise<RefundResult>
}
```

---

## Gateway Configuration Shapes

Each gateway constructor accepts a typed config object. Common fields:

```
BaseGatewayConfig {
  sandbox:    boolean     // Use sandbox/test environment (default: false)
  timeout?:   number      // HTTP timeout in ms (default: 30000)
  retries?:   number      // Max HTTP retries on transient failure (default: 2)
}
```

### VNPayConfig extends BaseGatewayConfig
```
{
  tmnCode:    string      // Terminal code from VNPay merchant portal
  hashSecret: string      // Hash secret key
}
```

### MoMoConfig extends BaseGatewayConfig
```
{
  partnerCode: string     // Partner code from MoMo merchant portal
  accessKey:   string     // Access key
  secretKey:   string     // Secret key
}
```
