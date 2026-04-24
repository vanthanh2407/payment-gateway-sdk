import { NextRequest, NextResponse } from 'next/server'
import sdk from '@/lib/sdk'
import { PaymentSDKError } from '@payment-sdk/node'

export async function GET(
  req: NextRequest,
  { params }: { params: { orderId: string } }
) {
  try {
    const { searchParams } = new URL(req.url)
    const gateway = searchParams.get('gateway')
    const transactionId = searchParams.get('transactionId') ?? undefined

    if (!gateway) {
      return NextResponse.json({ error: 'gateway query param required' }, { status: 400 })
    }

    const result = await sdk.getTransaction(gateway, params.orderId, transactionId)
    return NextResponse.json(result)
  } catch (err) {
    if (err instanceof PaymentSDKError) {
      return NextResponse.json({ error: err.message, code: err.code }, { status: 400 })
    }
    const message = err instanceof Error ? err.message : 'Unknown error'
    return NextResponse.json({ error: message }, { status: 500 })
  }
}
