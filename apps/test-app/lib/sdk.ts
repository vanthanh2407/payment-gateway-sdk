import {
  PaymentSDK,
  VNPayGateway,
  MoMoGateway,
  ZaloPayGateway,
  StripeGateway,
} from '@payment-sdk/node'

const sdk = new PaymentSDK()

if (process.env.VNPAY_TMN_CODE && process.env.VNPAY_HASH_SECRET) {
  sdk.use(
    'vnpay',
    new VNPayGateway({
      tmnCode: process.env.VNPAY_TMN_CODE,
      hashSecret: process.env.VNPAY_HASH_SECRET,
      sandbox: true,
    })
  )
}

if (
  process.env.MOMO_PARTNER_CODE &&
  process.env.MOMO_ACCESS_KEY &&
  process.env.MOMO_SECRET_KEY
) {
  sdk.use(
    'momo',
    new MoMoGateway({
      partnerCode: process.env.MOMO_PARTNER_CODE,
      accessKey: process.env.MOMO_ACCESS_KEY,
      secretKey: process.env.MOMO_SECRET_KEY,
      sandbox: true,
    })
  )
}

if (
  process.env.ZALOPAY_APP_ID &&
  process.env.ZALOPAY_KEY1 &&
  process.env.ZALOPAY_KEY2
) {
  sdk.use(
    'zalopay',
    new ZaloPayGateway({
      appId: Number(process.env.ZALOPAY_APP_ID),
      key1: process.env.ZALOPAY_KEY1,
      key2: process.env.ZALOPAY_KEY2,
      sandbox: true,
    })
  )
}

if (process.env.STRIPE_SECRET_KEY && process.env.STRIPE_WEBHOOK_SECRET) {
  sdk.use(
    'stripe',
    new StripeGateway({
      secretKey: process.env.STRIPE_SECRET_KEY,
      webhookSecret: process.env.STRIPE_WEBHOOK_SECRET,
    })
  )
}

export default sdk
