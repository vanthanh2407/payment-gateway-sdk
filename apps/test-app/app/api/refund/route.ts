import { NextRequest, NextResponse } from 'next/server'
import sdk from '@/lib/sdk'
import { PaymentSDKError } from '@payment-sdk/node'

export async function POST(req: NextRequest) {
  try {
    const body = await req.json()
    const { gateway, transactionId, orderId, amount, reason } = body

    if (!gateway || !transactionId || !orderId || !amount) {
      return NextResponse.json({ error: 'Missing required fields' }, { status: 400 })
    }

    const result = await sdk.refund(gateway, {
      transactionId,
      orderId,
      amount: Number(amount),
      ...(reason && { reason }),
    })

    return NextResponse.json(result)
  } catch (err) {
    if (err instanceof PaymentSDKError) {
      return NextResponse.json({ error: err.message, code: err.code }, { status: 400 })
    }
    const message = err instanceof Error ? err.message : 'Unknown error'
    return NextResponse.json({ error: message }, { status: 500 })
  }
}
