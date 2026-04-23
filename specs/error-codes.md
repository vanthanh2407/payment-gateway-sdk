# Payment SDK — Error Codes

All errors thrown by gateway implementations must use one of the standardized codes below.
This ensures callers can write gateway-agnostic error handling.

---

## ErrorCode Enum

### General Errors

| Code                  | When to use                                                     |
|-----------------------|-----------------------------------------------------------------|
| `UNKNOWN_ERROR`       | Unexpected error with no better mapping                         |
| `INVALID_CONFIG`      | Gateway instantiated with missing/invalid config fields         |
| `INVALID_INPUT`       | Input to a method is missing required fields or has bad values  |
| `NETWORK_ERROR`       | HTTP request failed (connection refused, DNS failure, etc.)     |
| `TIMEOUT`             | HTTP request exceeded configured timeout                        |
| `GATEWAY_ERROR`       | Gateway returned an unexpected error response (5xx, malformed)  |

### Payment Errors

| Code                  | When to use                                                     |
|-----------------------|-----------------------------------------------------------------|
| `PAYMENT_FAILED`      | Gateway rejected or failed the payment (generic)                |
| `PAYMENT_CANCELLED`   | User explicitly cancelled the payment                           |
| `PAYMENT_EXPIRED`     | Payment session or link expired                                 |
| `INVALID_AMOUNT`      | Amount is zero, negative, or exceeds gateway limits             |
| `DUPLICATE_ORDER`     | orderId already used in a previous successful payment           |
| `INSUFFICIENT_FUNDS`  | Card/wallet does not have enough balance                        |
| `CARD_DECLINED`       | Card was declined by issuing bank                               |
| `CARD_LOCKED`         | Card is temporarily or permanently locked                        |
| `AUTHENTICATION_FAILED` | 3DS/OTP authentication failed                               |
| `BANK_MAINTENANCE`    | Issuing bank is under maintenance                               |

### Webhook Errors

| Code                          | When to use                                                 |
|-------------------------------|-------------------------------------------------------------|
| `INVALID_SIGNATURE`           | Webhook signature verification failed                       |
| `WEBHOOK_PROCESSING_FAILED`   | Webhook payload could not be parsed or processed            |

### Transaction Errors

| Code                      | When to use                                             |
|---------------------------|---------------------------------------------------------|
| `TRANSACTION_NOT_FOUND`   | Transaction ID not found in gateway system              |

### Refund Errors

| Code                      | When to use                                             |
|---------------------------|---------------------------------------------------------|
| `REFUND_FAILED`           | Refund rejected or failed (generic)                     |
| `REFUND_NOT_SUPPORTED`    | Gateway/transaction does not support refunds            |
| `REFUND_AMOUNT_EXCEEDED`  | Refund amount exceeds original transaction amount       |
| `REFUND_ALREADY_PROCESSED`| Transaction was already fully refunded                  |
| `REFUND_WINDOW_EXPIRED`   | Refund time window has passed (gateway policy)          |

---

## Gateway-Specific Code Mappings

### VNPay Response Codes → ErrorCode

| vnp_ResponseCode | ErrorCode               | Description                            |
|------------------|-------------------------|----------------------------------------|
| `00`             | *(success)*             | Transaction successful                 |
| `07`             | `PAYMENT_FAILED`        | Suspicious transaction deducted money  |
| `09`             | `AUTHENTICATION_FAILED` | Card not registered for internet banking |
| `10`             | `AUTHENTICATION_FAILED` | Authentication failed 3 times          |
| `11`             | `PAYMENT_EXPIRED`       | Payment timeout                        |
| `12`             | `CARD_LOCKED`           | Card locked                            |
| `13`             | `AUTHENTICATION_FAILED` | Wrong OTP                              |
| `24`             | `PAYMENT_CANCELLED`     | Customer cancelled                     |
| `51`             | `INSUFFICIENT_FUNDS`    | Insufficient balance                   |
| `65`             | `PAYMENT_FAILED`        | Daily transaction limit exceeded       |
| `75`             | `BANK_MAINTENANCE`      | Bank under maintenance                 |
| `79`             | `AUTHENTICATION_FAILED` | Wrong password too many times          |
| `99`             | `UNKNOWN_ERROR`         | Other errors                           |

### MoMo Result Codes → ErrorCode

| resultCode | ErrorCode               | Description                                 |
|------------|-------------------------|---------------------------------------------|
| `0`        | *(success)*             | Success                                     |
| `9000`     | `PAYMENT_FAILED`        | Transaction refunded                        |
| `8000`     | `PROCESSING`            | Transaction pending                         |
| `7000`     | `PROCESSING`            | Transaction being processed                 |
| `1000`     | `PROCESSING`            | Transaction initiated                       |
| `1001`     | `INSUFFICIENT_FUNDS`    | Insufficient MoMo wallet balance            |
| `1002`     | `CARD_DECLINED`         | Payment rejected by issuer                  |
| `1003`     | `PAYMENT_FAILED`        | Transaction amount exceeded limit           |
| `1004`     | `PAYMENT_FAILED`        | Amount exceeded daily limit                 |
| `1005`     | `PAYMENT_EXPIRED`       | Payment URL expired                         |
| `1006`     | `PAYMENT_CANCELLED`     | User cancelled                              |
| `1007`     | `PAYMENT_FAILED`        | MoMo account suspended                     |
| `1026`     | `PAYMENT_FAILED`        | Business rules violation                    |
| `1080`     | `REFUND_FAILED`         | Refund failed                               |
| `1081`     | `REFUND_FAILED`         | Partial refund not allowed                  |
| `2001`     | `AUTHENTICATION_FAILED` | Wrong username or password                  |
| `2007`     | `AUTHENTICATION_FAILED` | Service not active                          |
| `4001`     | `AUTHENTICATION_FAILED` | Insufficient permissions                    |
| `4100`     | `AUTHENTICATION_FAILED` | User not logged in to MoMo                  |
| `7002`     | `PAYMENT_FAILED`        | Payment via MoMo wallet not supported       |
| `9001`     | `DUPLICATE_ORDER`       | Duplicate orderId                           |

### ZaloPay return_code → ErrorCode

| return_code | ErrorCode                   | Description                                   |
|-------------|-----------------------------|-----------------------------------------------|
| `1`         | *(success)*                 | Transaction successful                        |
| `2`         | `GATEWAY_ERROR`             | Pending / still processing                    |
| `-1`        | `GATEWAY_ERROR`             | System error                                  |
| `-2`        | `INVALID_INPUT`             | Invalid parameters                            |
| `-3`        | `AUTHENTICATION_FAILED`     | Authentication failure                        |
| `-4`        | `AUTHENTICATION_FAILED`     | App not authorized                            |
| `-5`        | `INVALID_INPUT`             | Invalid timestamp                             |
| `-6`        | `PAYMENT_EXPIRED`           | Request expired                               |
| `-7`        | `INVALID_AMOUNT`            | Invalid amount                                |
| `-9`        | `INVALID_CONFIG`            | App ID invalid                                |
| `-10`       | `INVALID_SIGNATURE`         | MAC verification failed                       |
| `-11`       | `DUPLICATE_ORDER`           | Already paid (duplicate app_trans_id)         |
| `-12`       | `REFUND_ALREADY_PROCESSED`  | Transaction already fully refunded            |
| `-13`       | `REFUND_AMOUNT_EXCEEDED`    | Refund amount exceeds original transaction    |
| `-14`       | `REFUND_WINDOW_EXPIRED`     | Refund time window has passed                 |
| `-15`       | `TRANSACTION_NOT_FOUND`     | Order not found                               |
| `-16`       | `PAYMENT_FAILED`            | Order not in success state for refund         |
| `-49`       | `TRANSACTION_NOT_FOUND`     | Transaction not found (query)                 |
| `-58`       | `CARD_DECLINED`             | Bank declined the payment                     |

---

## Error Handling Principles

1. **Always map to a standard code** — never let a raw gateway code reach the caller
2. **Preserve original codes** — populate `gatewayCode` and `gatewayMessage` for debugging
3. **Include rawResponse** — every `PaymentResult` and `RefundResult` must include `rawResponse`
4. **Network errors** — wrap `ECONNREFUSED`, `ETIMEDOUT`, DNS failures → `NETWORK_ERROR` or `TIMEOUT`
5. **Retry only on transient errors** — `NETWORK_ERROR`, `TIMEOUT`, `GATEWAY_ERROR` (5xx) are retryable
6. **Do not retry** — `PAYMENT_FAILED`, `INVALID_SIGNATURE`, `DUPLICATE_ORDER`, auth errors
