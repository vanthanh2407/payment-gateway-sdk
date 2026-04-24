'use client'

import { useState, Suspense } from 'react'
import { useSearchParams } from 'next/navigation'

const STATUS_COLORS: Record<string, string> = {
  SUCCESS: 'bg-green-100 text-green-800',
  FAILED: 'bg-red-100 text-red-800',
  PENDING: 'bg-yellow-100 text-yellow-800',
  PROCESSING: 'bg-blue-100 text-blue-800',
  CANCELLED: 'bg-gray-100 text-gray-700',
  EXPIRED: 'bg-orange-100 text-orange-800',
  REFUNDED: 'bg-purple-100 text-purple-800',
}

const GATEWAYS = ['vnpay', 'momo', 'zalopay', 'stripe']

function StatusForm() {
  const searchParams = useSearchParams()
  const [form, setForm] = useState({
    gateway: searchParams.get('gateway') ?? 'vnpay',
    orderId: searchParams.get('orderId') ?? '',
    transactionId: searchParams.get('transactionId') ?? '',
  })
  const [loading, setLoading] = useState(false)
  const [result, setResult] = useState<unknown | null>(null)
  const [error, setError] = useState('')

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)
    setResult(null)
    setError('')
    try {
      const params = new URLSearchParams({ gateway: form.gateway })
      if (form.transactionId) params.set('transactionId', form.transactionId)
      const res = await fetch(`/api/transaction/${encodeURIComponent(form.orderId)}?${params}`)
      const data = await res.json()
      if (res.ok) {
        setResult(data)
      } else {
        setError(data.error ?? 'Lỗi tra cứu')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Lỗi kết nối')
    } finally {
      setLoading(false)
    }
  }

  const r = result as Record<string, unknown> | null

  return (
    <div className="max-w-lg">
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Tra cứu giao dịch</h1>

      <form onSubmit={handleSubmit} className="bg-white rounded-xl border border-gray-200 p-6 space-y-4 shadow-sm mb-6">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Gateway</label>
          <select
            value={form.gateway}
            onChange={(e) => setForm((f) => ({ ...f, gateway: e.target.value }))}
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            {GATEWAYS.map((gw) => (
              <option key={gw} value={gw}>{gw}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Order ID</label>
          <input
            value={form.orderId}
            onChange={(e) => setForm((f) => ({ ...f, orderId: e.target.value }))}
            required
            placeholder="ORDER-12345678"
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono"
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Transaction ID <span className="text-gray-400">(optional)</span>
          </label>
          <input
            value={form.transactionId}
            onChange={(e) => setForm((f) => ({ ...f, transactionId: e.target.value }))}
            placeholder="Gateway transaction ID"
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono"
          />
        </div>
        <button
          type="submit"
          disabled={loading}
          className="w-full bg-indigo-600 text-white py-2.5 rounded-lg font-medium hover:bg-indigo-700 disabled:opacity-50 transition-colors"
        >
          {loading ? 'Đang tra cứu...' : 'Tra cứu'}
        </button>
      </form>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-red-800 text-sm">{error}</div>
      )}

      {r && (
        <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-gray-900">Kết quả</h2>
            <span
              className={`text-xs font-medium px-2.5 py-1 rounded-full ${STATUS_COLORS[String(r.status)] ?? 'bg-gray-100 text-gray-700'}`}
            >
              {String(r.status)}
            </span>
          </div>
          <div className="grid grid-cols-2 gap-2 text-sm">
            <div className="text-gray-500">Order ID</div>
            <div className="font-mono text-gray-900">{String(r.orderId ?? '-')}</div>
            <div className="text-gray-500">Transaction ID</div>
            <div className="font-mono text-gray-900">{String(r.transactionId ?? '-')}</div>
            <div className="text-gray-500">Số tiền</div>
            <div className="font-medium text-gray-900">
              {Number(r.amount).toLocaleString()} {String(r.currency)}
            </div>
            <div className="text-gray-500">Gateway</div>
            <div className="text-gray-900">{String(r.gateway)}</div>
            <div className="text-gray-500">Thành công</div>
            <div className={r.success ? 'text-green-600 font-medium' : 'text-red-600 font-medium'}>
              {r.success ? 'Có' : 'Không'}
            </div>
          </div>
          <details>
            <summary className="text-xs text-gray-400 cursor-pointer mt-2">Raw response</summary>
            <pre className="mt-2 text-xs bg-gray-50 p-3 rounded border overflow-auto max-h-60">
              {JSON.stringify(r, null, 2)}
            </pre>
          </details>
        </div>
      )}
    </div>
  )
}

export default function StatusPage() {
  return (
    <Suspense>
      <StatusForm />
    </Suspense>
  )
}
