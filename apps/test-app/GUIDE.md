# Hướng dẫn chạy Payment Gateway Test App

Ứng dụng Next.js để test kết nối với [Payment Gateway SDK](../../packages/node) trong môi trường sandbox.

---

## Yêu cầu

- Node.js 18+
- SDK đã được build (xem bước 1)
- Tài khoản sandbox của ít nhất một gateway

---

## Bước 1 — Build SDK

Trước khi chạy app, cần build SDK ở `packages/node`:

```bash
cd packages/node
npm install
npm run build
```

---

## Bước 2 — Cài đặt dependencies

```bash
cd apps/test-app
npm install
```

---

## Bước 3 — Cấu hình biến môi trường

Tạo file `.env.local` từ template:

```bash
cp .env.local.example .env.local
```

Mở `.env.local` và điền credentials sandbox của từng gateway bạn muốn test. Bỏ trống gateway nào không dùng — app sẽ tự bỏ qua.

### VNPay Sandbox

Đăng ký tại [sandbox.vnpayment.vn](https://sandbox.vnpayment.vn/apis/docs/gioi-thieu/) để lấy:

```env
VNPAY_TMN_CODE=XXXXXXXX
VNPAY_HASH_SECRET=your_hash_secret
```

### MoMo Sandbox

Đăng ký tại [developers.momo.vn](https://developers.momo.vn) để lấy:

```env
MOMO_PARTNER_CODE=MOMO...
MOMO_ACCESS_KEY=your_access_key
MOMO_SECRET_KEY=your_secret_key
```

### ZaloPay Sandbox

Lấy credentials tại [docs.zalopay.vn](https://docs.zalopay.vn/docs/start/):

```env
ZALOPAY_APP_ID=2553
ZALOPAY_KEY1=your_key1
ZALOPAY_KEY2=your_key2
```

### Stripe Test

Lấy key tại [dashboard.stripe.com/test/apikeys](https://dashboard.stripe.com/test/apikeys):

```env
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

> **Lưu ý Stripe Webhook Secret:** Cần tạo webhook endpoint trong Stripe Dashboard → Developers → Webhooks → Add endpoint, trỏ vào `<APP_URL>/api/webhook/stripe`.

---

## Bước 4 — Chạy app

```bash
cd apps/test-app
npm run dev
```

Mở trình duyệt tại **http://localhost:3000**

---

## Bước 5 — Test webhook (tuỳ chọn)

Các gateway sandbox cần gọi về URL công khai để gửi webhook. Dùng **ngrok** để expose localhost:

```bash
# Terminal riêng
ngrok http 3000
```

Ngrok sẽ in ra URL dạng `https://abc123.ngrok-free.app`. Cập nhật vào `.env.local`:

```env
NEXT_PUBLIC_APP_URL=https://abc123.ngrok-free.app
```

Restart dev server sau khi sửa `.env.local`:

```bash
# Ctrl+C để dừng, rồi chạy lại
npm run dev
```

Webhook URL cho từng gateway (hiển thị trên Dashboard):

| Gateway | Webhook URL |
|---------|-------------|
| VNPay | `<APP_URL>/api/webhook/vnpay` |
| MoMo | `<APP_URL>/api/webhook/momo` |
| ZaloPay | `<APP_URL>/api/webhook/zalopay` |
| Stripe | `<APP_URL>/api/webhook/stripe` |

Điền URL này vào phần cấu hình IPN/webhook của từng cổng sandbox.

---

## Sử dụng app

### Dashboard — `/`

Hiển thị danh sách 4 gateway và trạng thái cấu hình (đã điền env hay chưa). Cũng hiển thị các webhook URL để copy vào cổng sandbox.

### Tạo thanh toán — `/pay`

1. Chọn gateway
2. Nhập số tiền (Order ID tự sinh, có thể tạo mới)
3. Kiểm tra Return URL và Webhook URL (tự điền từ `NEXT_PUBLIC_APP_URL`)
4. Nhấn **Tạo thanh toán**
5. Nhấn **Mở cổng thanh toán** để chuyển đến trang sandbox

**Thông tin test card Stripe:**

| Trường | Giá trị |
|--------|---------|
| Card number | `4242 4242 4242 4242` |
| Expiry | Bất kỳ ngày tương lai |
| CVC | Bất kỳ 3 số |

### Tra cứu giao dịch — `/status`

Nhập gateway + Order ID (+ Transaction ID nếu có) để query trạng thái giao dịch từ gateway.

Trang này cũng được tự động mở sau khi thanh toán thành công (Return URL).

### Webhook Log — `/webhooks`

Hiển thị danh sách webhook events nhận được, tự cập nhật mỗi 3 giây. Mỗi event gồm:

- Thời gian nhận
- Gateway
- Loại event (`PAYMENT_SUCCESS`, `PAYMENT_FAILED`, v.v.)
- Order ID, số tiền, trạng thái

Nút **Xóa log** để reset danh sách (log chỉ lưu trong bộ nhớ, mất khi restart server).

---

## Cấu trúc project

```
apps/test-app/
├── app/
│   ├── page.tsx                  ← Dashboard
│   ├── pay/page.tsx              ← Tạo thanh toán
│   ├── status/page.tsx           ← Tra cứu giao dịch
│   ├── webhooks/page.tsx         ← Webhook log
│   └── api/
│       ├── payment/route.ts      ← POST /api/payment
│       ├── transaction/[orderId]/route.ts
│       ├── refund/route.ts       ← POST /api/refund
│       ├── webhook/[gateway]/route.ts  ← Nhận callback
│       ├── webhooks/route.ts     ← GET/DELETE webhook log
│       └── gateways/route.ts     ← Danh sách gateway
├── lib/
│   ├── sdk.ts                    ← PaymentSDK singleton
│   └── webhook-store.ts          ← In-memory event log
├── .env.local.example
└── GUIDE.md
```

---

## Troubleshooting

**Gateway không hiện trên Dashboard**
→ Kiểm tra lại biến môi trường trong `.env.local`. Tất cả fields của gateway đó phải được điền.

**Lỗi khi tạo thanh toán**
→ Mở DevTools → Network → xem response từ `/api/payment`. Thường do credentials sai hoặc amount không hợp lệ (VNPay yêu cầu tối thiểu 1.000 VND).

**Webhook không nhận được**
→ Đảm bảo đang dùng ngrok URL (không phải localhost) và đã điền `NEXT_PUBLIC_APP_URL`. Kiểm tra ngrok dashboard tại `http://localhost:4040` để xem request đến.

**Webhook log mất sau khi restart**
→ Đây là thiết kế — log lưu trong bộ nhớ (in-memory). Không cần database để test.
