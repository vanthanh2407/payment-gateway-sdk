'use client'

import { useEffect, useState, Suspense } from 'react'
import { useSearchParams } from 'next/navigation'

interface Gateway {
  id: string
  name: string
  currency: string
  configured: boolean
}

function generateOrderId() {
  return `ORDER-${Date.now().toString().slice(-8)}`
}

function PayForm() {
  const searchParams = useSearchParams()
  const initialGateway = searchParams.get('gateway') ?? ''

  const [gateways, setGateways] = useState<Gateway[]>([])
  const [form, setForm] = useState({
    gateway: initialGateway,
    orderId: generateOrderId(),
    amount: '',
    currency: 'VND',
    description: 'Test payment',
    returnUrl: '',
    ipnUrl: '',
  })
  const [loading, setLoading] = useState(false)
  const [result, setResult] = useState<{ paymentUrl?: string; error?: string; data?: Record<string, unknown> } | null>(null)

  useEffect(() => {
    fetch('/api/gateways')
      .then((r) => r.json())
      .then((data: Gateway[]) => {
        setGateways(data.filter((g) => g.configured))
        if (!initialGateway && data.length > 0) {
          const first = data.find((g) => g.configured)
          if (first) setForm((f) => ({ ...f, gateway: first.id, currency: first.currency }))
        }
      })
      .catch(console.error)

    const appUrl = window.location.origin
    setForm((f) => ({
      ...f,
      returnUrl: `${appUrl}/status?orderId=${f.orderId}`,
      ipnUrl: `${appUrl}/api/webhook/${f.gateway || initialGateway}`,
    }))
  }, [initialGateway])

  function onGatewayChange(gwId: string) {
    const gw = gateways.find((g) => g.id === gwId)
    const appUrl = window.location.origin
    setForm((f) => ({
      ...f,
      gateway: gwId,
      currency: gw?.currency ?? 'VND',
      ipnUrl: `${appUrl}/api/webhook/${gwId}`,
    }))
  }

  function onOrderIdChange(orderId: string) {
    const appUrl = window.location.origin
    setForm((f) => ({
      ...f,
      orderId,
      returnUrl: `${appUrl}/status?orderId=${orderId}`,
    }))
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)
    setResult(null)
    try {
      const res = await fetch('/api/payment', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form, amount: Number(form.amount) }),
      })
      const data = await res.json()
      if (data.paymentUrl) {
        setResult({ paymentUrl: data.paymentUrl, data })
      } else {
        setResult({ error: data.error ?? 'Không tạo được payment URL', data })
      }
    } catch (err) {
      setResult({ error: err instanceof Error ? err.message : 'Lỗi kết nối' })
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="max-w-lg">
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Tạo thanh toán test</h1>

      <form onSubmit={handleSubmit} className="bg-white rounded-xl border border-gray-200 p-6 space-y-4 shadow-sm">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Gateway</label>
          <select
            value={form.gateway}
            onChange={(e) => onGatewayChange(e.target.value)}
            required
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            <option value="">-- Chọn gateway --</option>
            {gateways.map((gw) => (
              <option key={gw.id} value={gw.id}>
                {gw.name} ({gw.currency})
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Order ID</label>
          <div className="flex gap-2">
            <input
              value={form.orderId}
              onChange={(e) => onOrderIdChange(e.target.value)}
              required
              className="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono"
            />
            <button
              type="button"
              onClick={() => onOrderIdChange(generateOrderId())}
              className="text-xs px-3 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 text-gray-600 whitespace-nowrap"
            >
              Tạo mới
            </button>
          </div>
        </div>

        <div className="flex gap-3">
          <div className="flex-1">
            <label className="block text-sm font-medium text-gray-700 mb-1">Số tiền</label>
            <input
              type="number"
              value={form.amount}
              onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))}
              placeholder={form.currency === 'VND' ? '10000' : '10'}
              min="1"
              required
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
          <div className="w-24">
            <label className="block text-sm font-medium text-gray-700 mb-1">Tiền tệ</label>
            <input
              value={form.currency}
              readOnly
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-600"
            />
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Mô tả</label>
          <input
            value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
            required
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Return URL</label>
          <input
            value={form.returnUrl}
            onChange={(e) => setForm((f) => ({ ...f, returnUrl: e.target.value }))}
            required
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono text-xs"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            IPN/Webhook URL <span className="text-gray-400">(optional)</span>
          </label>
          <input
            value={form.ipnUrl}
            onChange={(e) => setForm((f) => ({ ...f, ipnUrl: e.target.value }))}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono text-xs"
          />
        </div>

        <button
          type="submit"
          disabled={loading}
          className="w-full bg-indigo-600 text-white py-2.5 rounded-lg font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          {loading ? 'Đang tạo...' : 'Tạo thanh toán'}
        </button>
      </form>

      {result && (
        <div className={`mt-4 rounded-xl p-5 border ${result.error ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'}`}>
          {result.paymentUrl ? (
            <>
              <p className="font-medium text-green-800 mb-3">Tạo thành công!</p>
              <a
                href={result.paymentUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-block bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700 transition-colors mb-3"
              >
                Mở cổng thanh toán →
              </a>
              <div className="text-xs font-mono bg-white/60 rounded p-2 break-all text-gray-700 border border-green-200">
                {result.paymentUrl}
              </div>
            </>
          ) : (
            <p className="text-red-800 font-medium">{result.error}</p>
          )}
          {result.data && (
            <details className="mt-3">
              <summary className="text-xs text-gray-500 cursor-pointer">Raw response</summary>
              <pre className="mt-2 text-xs bg-white/60 p-2 rounded border overflow-auto max-h-48">
                {JSON.stringify(result.data, null, 2)}
              </pre>
            </details>
          )}
        </div>
      )}
    </div>
  )
}

export default function PayPage() {
  return (
    <Suspense>
      <PayForm />
    </Suspense>
  )
}
