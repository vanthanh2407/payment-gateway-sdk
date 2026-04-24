'use client'

import { useEffect, useState } from 'react'

interface StoredWebhookEvent {
  id: string
  event: {
    gateway: string
    eventType: string
    orderId: string
    transactionId: string
    amount: number
    currency: string
    status: string
    receivedAt: string
  }
  receivedAt: string
}

const EVENT_COLORS: Record<string, string> = {
  PAYMENT_SUCCESS: 'bg-green-100 text-green-800',
  PAYMENT_FAILED: 'bg-red-100 text-red-800',
  PAYMENT_CANCELLED: 'bg-gray-100 text-gray-600',
  REFUND_SUCCESS: 'bg-purple-100 text-purple-800',
  REFUND_FAILED: 'bg-orange-100 text-orange-800',
  DISPUTE_CREATED: 'bg-yellow-100 text-yellow-800',
}

export default function WebhooksPage() {
  const [events, setEvents] = useState<StoredWebhookEvent[]>([])
  const [loading, setLoading] = useState(true)

  async function fetchEvents() {
    try {
      const res = await fetch('/api/webhooks')
      const data = await res.json()
      setEvents(data)
    } catch {
      // silent
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchEvents()
    const interval = setInterval(fetchEvents, 3000)
    return () => clearInterval(interval)
  }, [])

  async function clearLog() {
    await fetch('/api/webhooks', { method: 'DELETE' })
    setEvents([])
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Webhook Log</h1>
          <p className="text-sm text-gray-500 mt-0.5">Tự cập nhật mỗi 3 giây</p>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-sm text-gray-500">{events.length} events</span>
          <button
            onClick={clearLog}
            className="text-sm px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-600 transition-colors"
          >
            Xóa log
          </button>
        </div>
      </div>

      {loading ? (
        <div className="text-center py-12 text-gray-400">Đang tải...</div>
      ) : events.length === 0 ? (
        <div className="text-center py-16 bg-white rounded-xl border border-gray-200">
          <p className="text-gray-400 text-lg mb-2">Chưa có webhook events</p>
          <p className="text-gray-400 text-sm">
            Thực hiện thanh toán qua sandbox để nhận callbacks
          </p>
        </div>
      ) : (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Thời gian</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Gateway</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Event</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Order ID</th>
                <th className="text-right px-4 py-3 font-medium text-gray-600">Số tiền</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {events.map((item) => (
                <tr key={item.id} className="hover:bg-gray-50 transition-colors">
                  <td className="px-4 py-3 text-gray-500 font-mono text-xs whitespace-nowrap">
                    {new Date(item.receivedAt).toLocaleTimeString('vi-VN')}
                  </td>
                  <td className="px-4 py-3 font-medium text-gray-900 uppercase text-xs">
                    {item.event.gateway}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`text-xs font-medium px-2 py-0.5 rounded-full ${EVENT_COLORS[item.event.eventType] ?? 'bg-gray-100 text-gray-600'}`}
                    >
                      {item.event.eventType}
                    </span>
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-gray-700">{item.event.orderId}</td>
                  <td className="px-4 py-3 text-right font-medium text-gray-900">
                    {item.event.amount.toLocaleString()} {item.event.currency}
                  </td>
                  <td className="px-4 py-3 text-xs text-gray-600">{item.event.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
