# payment-gateway-sdk

A multi-language Payment SDK monorepo for integrating Vietnamese and international payment gateways.

## Supported Gateways

| Gateway   | Node.js | Go  | PHP |
|-----------|---------|-----|-----|
| VNPay     | ✅      | 🔜  | 🔜  |
| MoMo      | ✅      | 🔜  | 🔜  |
| ZaloPay   | 🔜      | 🔜  | 🔜  |
| Stripe    | 🔜      | 🔜  | 🔜  |
| VietQR    | 🔜      | 🔜  | 🔜  |

## Packages

- [`packages/node`](./packages/node) — TypeScript/JavaScript SDK
- `packages/go` — Go SDK *(coming soon)*
- `packages/php` — PHP SDK *(coming soon)*

## Architecture

See [`specs/`](./specs/) for interface definitions that serve as source of truth across all language implementations.

## License

MIT
