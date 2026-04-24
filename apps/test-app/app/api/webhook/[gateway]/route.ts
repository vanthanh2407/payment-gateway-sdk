import { NextRequest, NextResponse } from 'next/server'
import sdk from '@/lib/sdk'
import { addEvent } from '@/lib/webhook-store'
import { PaymentSDKError, ErrorCode } from '@payment-sdk/node'

async function handle(
  req: NextRequest,
  { params }: { params: { gateway: string } }
) {
  const { gateway } = params
  const rawBody = await req.text()
  const headers: Record<string, string> = {}
  req.headers.forEach((v, k) => { headers[k] = v })

  let payload: unknown
  try {
    // Try JSON first, fall back to URL query params
    const contentType = req.headers.get('content-type') ?? ''
    if (contentType.includes('application/json')) {
      payload = JSON.parse(rawBody)
    } else if (contentType.includes('application/x-www-form-urlencoded')) {
      payload = Object.fromEntries(new URLSearchParams(rawBody))
    } else {
      // VNPay uses GET with query params
      payload = Object.fromEntries(new URL(req.url).searchParams)
    }
  } catch {
    payload = rawBody
  }

  try {
    const event = await sdk.verifyWebhook(gateway, payload, headers)
    addEvent(event, rawBody)

    // Return gateway-specific success response
    if (gateway === 'vnpay') {
      return NextResponse.json({ RspCode: '00', Message: 'Confirmed' })
    }
    if (gateway === 'momo') {
      return NextResponse.json({ resultCode: 0, message: 'Success' })
    }
    if (gateway === 'zalopay') {
      return NextResponse.json({ return_code: 1, return_message: 'Success' })
    }
    return NextResponse.json({ received: true })
  } catch (err) {
    if (
      err instanceof PaymentSDKError &&
      err.code === ErrorCode.INVALID_SIGNATURE
    ) {
      if (gateway === 'vnpay') {
        return NextResponse.json({ RspCode: '97', Message: 'Invalid signature' })
      }
      return NextResponse.json({ error: 'Invalid signature' }, { status: 400 })
    }
    console.error(`[webhook/${gateway}]`, err)
    return NextResponse.json({ error: 'Processing failed' }, { status: 500 })
  }
}

export { handle as GET, handle as POST }
