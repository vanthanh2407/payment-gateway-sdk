# @payment-sdk/node

Multi-gateway payment SDK for Node.js and TypeScript. Supports VNPay, MoMo, and more.

## Requirements

- Node.js >= 18 (uses native `fetch` and `crypto`)

## Installation

```bash
npm install @payment-sdk/node
```

## Quick Start

### CommonJS (require)

```js
const { PaymentSDK, VNPayGateway, MoMoGateway } = require('@payment-sdk/node')

const sdk = new PaymentSDK()

sdk.use('vnpay', new VNPayGateway({
  tmnCode: process.env.VNPAY_TMN_CODE,
  hashSecret: process.env.VNPAY_HASH_SECRET,
  sandbox: true, // remove in production
}))

sdk.use('momo', new MoMoGateway({
  partnerCode: process.env.MOMO_PARTNER_CODE,
  accessKey: process.env.MOMO_ACCESS_KEY,
  secretKey: process.env.MOMO_SECRET_KEY,
  sandbox: true,
}))
```

### ES Modules (import)

```js
import { PaymentSDK, VNPayGateway, MoMoGateway } from '@payment-sdk/node'

const sdk = new PaymentSDK()
sdk
  .use('vnpay', new VNPayGateway({ tmnCode: '...', hashSecret: '...', sandbox: true }))
  .use('momo', new MoMoGateway({ partnerCode: '...', accessKey: '...', secretKey: '...', sandbox: true }))
```

### TypeScript

```ts
import { PaymentSDK, VNPayGateway, type CreatePaymentInput, PaymentStatus } from '@payment-sdk/node'

const sdk = new PaymentSDK()
sdk.use('vnpay', new VNPayGateway({ tmnCode: '...', hashSecret: '...', sandbox: true }))

const input: CreatePaymentInput = {
  orderId: 'ORDER-001',
  amount: 100_000,       // VND
  currency: 'VND',
  description: 'Thanh toan don hang #001',
  returnUrl: 'https://yoursite.com/payment/return',
  ipnUrl: 'https://yoursite.com/payment/webhook',
}

const result = await sdk.createPayment('vnpay', input)
if (result.success) {
  // Redirect user to result.paymentUrl
  console.log('Redirect to:', result.paymentUrl)
}
```

---

## API Reference

### `new PaymentSDK()`

Top-level orchestrator. Manages gateway instances.

```ts
const sdk = new PaymentSDK()
sdk.use('vnpay', gateway)      // Register a gateway
sdk.gateway('vnpay')           // Get a registered gateway (throws if not found)
sdk.listGateways()             // ['vnpay', 'momo']
```

#### Convenience Methods (delegate to registered gateway)

```ts
sdk.createPayment(gatewayName, input)
sdk.verifyWebhook(gatewayName, payload, headers)
sdk.getTransaction(gatewayName, transactionId, orderId?)
sdk.refund(gatewayName, input)
```

---

### `new VNPayGateway(config)`

```ts
interface VNPayConfig {
  tmnCode:    string    // Terminal code from VNPay merchant portal
  hashSecret: string    // Hash secret key
  sandbox?:   boolean   // Default: false
  timeout?:   number    // HTTP timeout ms, default: 30000
  retries?:   number    // Max retries on transient errors, default: 2
}
```

---

### `new MoMoGateway(config)`

```ts
interface MoMoConfig {
  partnerCode: string   // Partner code from MoMo merchant portal
  accessKey:   string   // Access key
  secretKey:   string   // Secret key
  sandbox?:    boolean  // Default: false
  timeout?:    number   // HTTP timeout ms, default: 30000
  retries?:    number   // Max retries on transient errors, default: 2
}
```

---

### `createPayment(input): Promise<PaymentResult>`

Creates a payment session and returns a redirect URL.

```ts
const result = await sdk.createPayment('vnpay', {
  orderId: 'ORDER-001',
  amount: 100_000,
  currency: 'VND',
  description: 'Thanh toan',
  returnUrl: 'https://example.com/return',
})

// result.success === true → redirect user to result.paymentUrl
// result.success === false → check result.error
```

---

### `verifyWebhook(payload, headers): Promise<WebhookEvent>`

Verifies a server-to-server notification from the gateway.

```ts
// Express.js example
app.post('/payment/webhook/vnpay', async (req, res) => {
  try {
    const event = await sdk.verifyWebhook('vnpay', req.query, req.headers)

    if (event.status === PaymentStatus.SUCCESS) {
      await markOrderPaid(event.orderId, event.transactionId)
    }

    // VNPay expects this exact response body
    res.json({ RspCode: '00', Message: 'Confirm Success' })
  } catch (err) {
    if (err instanceof PaymentSDKError && err.code === ErrorCode.INVALID_SIGNATURE) {
      res.json({ RspCode: '97', Message: 'Invalid signature' })
    } else {
      res.json({ RspCode: '99', Message: 'Unknown error' })
    }
  }
})
```

---

### `getTransaction(transactionId, orderId?): Promise<PaymentResult>`

Queries a transaction's current status.

```ts
const result = await sdk.getTransaction('vnpay', 'TXN123456', 'ORDER-001')
console.log(result.status) // PaymentStatus.SUCCESS
```

---

### `refund(input): Promise<RefundResult>`

Issues a refund for a completed transaction.

```ts
const result = await sdk.refund('momo', {
  transactionId: '4111111111',
  orderId: 'ORDER-002',
  amount: 50_000,
  reason: 'Customer request',
})

if (result.success) {
  console.log('Refund ID:', result.refundId)
}
```

---

## Error Handling

All errors are instances of `PaymentSDKError` with a standardized `code` field:

```ts
import { PaymentSDKError, ErrorCode } from '@payment-sdk/node'

try {
  const result = await sdk.createPayment('vnpay', input)
} catch (err) {
  if (err instanceof PaymentSDKError) {
    switch (err.code) {
      case ErrorCode.INVALID_CONFIG:
        console.error('Gateway misconfigured:', err.message)
        break
      case ErrorCode.NETWORK_ERROR:
        console.error('Network issue, retry later')
        break
      case ErrorCode.INVALID_SIGNATURE:
        console.error('Webhook signature mismatch — possible tampering')
        break
      default:
        console.error(`Payment error [${err.code}]:`, err.message)
    }
    // Original gateway code/message for debugging:
    console.error('Gateway code:', err.gatewayCode)
    console.error('Gateway message:', err.gatewayMessage)
  }
}
```

See [`specs/error-codes.md`](../../specs/error-codes.md) for the full list.

---

## Types

```ts
import type {
  CreatePaymentInput,
  PaymentResult,
  WebhookEvent,
  RefundInput,
  RefundResult,
  GatewayCapabilities,
  PaymentError,
} from '@payment-sdk/node'

import {
  PaymentStatus,
  RefundStatus,
  WebhookEventType,
  ErrorCode,
} from '@payment-sdk/node'
```

---

## Build Outputs

| Path                    | Format     | For                            |
|-------------------------|------------|--------------------------------|
| `dist/cjs/index.js`     | CommonJS   | `require()` (Node.js, bundlers)|
| `dist/esm/index.js`     | ES Module  | `import` (ESM, Vite, Next.js)  |
| `dist/types/index.d.ts` | TypeScript | Type hints in editors          |

---

## Development

```bash
npm install
npm run build          # Build all outputs
npm run test           # Run unit tests
npm run test:coverage  # Test with coverage report
npm run typecheck      # TypeScript type check only
```

## License

MIT
