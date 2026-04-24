'use client'

import { useEffect, useState } from 'react'
import Link from 'next/link'

interface Gateway {
  id: string
  name: string
  currency: string
  methods: string[]
  configured: boolean
}

const GATEWAY_ICONS: Record<string, string> = {
  vnpay: '🏦',
  momo: '💜',
  zalopay: '🔵',
  stripe: '⚡',
}

const GATEWAY_COLORS: Record<string, string> = {
  vnpay: 'border-red-200 bg-red-50',
  momo: 'border-purple-200 bg-purple-50',
  zalopay: 'border-blue-200 bg-blue-50',
  stripe: 'border-indigo-200 bg-indigo-50',
}

export default function DashboardPage() {
  const [gateways, setGateways] = useState<Gateway[]>([])

  useEffect(() => {
    fetch('/api/gateways')
      .then((r) => r.json())
      .then(setGateways)
      .catch(console.error)
  }, [])

  const appUrl = process.env.NEXT_PUBLIC_APP_URL ?? 'http://localhost:3000'

  return (
    <div>
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-gray-900">Payment Gateway SDK Tester</h1>
        <p className="mt-1 text-gray-500 text-sm">
          Môi trường sandbox — không có giao dịch thật
        </p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
        {gateways.map((gw) => (
          <div
            key={gw.id}
            className={`rounded-xl border-2 p-5 ${GATEWAY_COLORS[gw.id] ?? 'border-gray-200 bg-white'}`}
          >
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                <span className="text-2xl">{GATEWAY_ICONS[gw.id]}</span>
                <span className="font-semibold text-gray-900">{gw.name}</span>
              </div>
              {gw.configured ? (
                <span className="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">
                  Đã cấu hình
                </span>
              ) : (
                <span className="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full font-medium">
                  Chưa cấu hình
                </span>
              )}
            </div>
            <div className="text-sm text-gray-600 mb-3">
              <span className="font-medium">Tiền tệ:</span> {gw.currency}
            </div>
            <div className="flex flex-wrap gap-1 mb-4">
              {gw.methods.map((m) => (
                <span key={m} className="text-xs bg-white/70 text-gray-600 px-2 py-0.5 rounded border border-gray-200">
                  {m}
                </span>
              ))}
            </div>
            {gw.configured && (
              <Link
                href={`/pay?gateway=${gw.id}`}
                className="inline-block text-sm bg-white border border-gray-300 text-gray-700 px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors font-medium"
              >
                Tạo thanh toán →
              </Link>
            )}
          </div>
        ))}
      </div>

      <div className="bg-amber-50 border border-amber-200 rounded-xl p-5">
        <h2 className="font-semibold text-amber-900 mb-2">Webhook URL để test</h2>
        <p className="text-sm text-amber-800 mb-2">
          Chạy <code className="bg-amber-100 px-1 rounded font-mono">ngrok http 3000</code> rồi cập nhật{' '}
          <code className="bg-amber-100 px-1 rounded font-mono">NEXT_PUBLIC_APP_URL</code> trong{' '}
          <code className="bg-amber-100 px-1 rounded font-mono">.env.local</code>
        </p>
        <div className="space-y-1">
          {['vnpay', 'momo', 'zalopay', 'stripe'].map((gw) => (
            <div key={gw} className="flex items-center gap-2 font-mono text-xs text-amber-900">
              <span className="font-medium w-16">{gw}:</span>
              <code className="bg-white/60 px-2 py-0.5 rounded border border-amber-200">
                {appUrl}/api/webhook/{gw}
              </code>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
