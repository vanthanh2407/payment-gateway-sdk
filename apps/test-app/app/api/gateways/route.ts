import { NextResponse } from 'next/server'
import sdk from '@/lib/sdk'

export async function GET() {
  const configured = sdk.listGateways()
  const all = [
    { id: 'vnpay', name: 'VNPay', currency: 'VND', methods: ['card', 'banking', 'qr'] },
    { id: 'momo', name: 'MoMo', currency: 'VND', methods: ['wallet', 'card', 'qr'] },
    { id: 'zalopay', name: 'ZaloPay', currency: 'VND', methods: ['wallet', 'card', 'qr'] },
    { id: 'stripe', name: 'Stripe', currency: 'USD', methods: ['card', 'bank_transfer'] },
  ]
  return NextResponse.json(
    all.map((g) => ({ ...g, configured: configured.includes(g.id) }))
  )
}
