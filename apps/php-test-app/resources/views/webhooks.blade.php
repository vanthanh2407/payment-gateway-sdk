@extends('layouts.app')
@section('title', 'Webhook Log — Payment SDK')

@section('content')
<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Webhook Log</h1>
            <p class="text-gray-500 text-sm mt-1">Tự động cập nhật mỗi 3 giây</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm text-gray-500">
                <span id="event-count">0</span> sự kiện
            </span>
            <button onclick="clearEvents()"
                    class="px-3 py-1.5 text-sm border border-red-200 text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                Xóa tất cả
            </button>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wide">Thời gian</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wide">Gateway</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wide">Sự kiện</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wide">Order ID</th>
                        <th class="text-right px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wide">Số tiền</th>
                        <th class="text-left px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wide">Trạng thái</th>
                    </tr>
                </thead>
                <tbody id="events-body">
                    <tr id="empty-row">
                        <td colspan="6" class="px-4 py-12 text-center text-gray-400 text-sm">
                            Chưa có webhook nào. Thực hiện thanh toán để nhận webhook.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="status-bar" class="mt-3 text-xs text-gray-400 text-right">
        Đang kết nối...
    </div>
</div>

<script>
const eventTypeBadge = {
    'PAYMENT_SUCCESS':   'bg-green-100 text-green-800',
    'PAYMENT_FAILED':    'bg-red-100 text-red-800',
    'PAYMENT_CANCELLED': 'bg-gray-100 text-gray-700',
    'REFUND_SUCCESS':    'bg-purple-100 text-purple-800',
    'REFUND_FAILED':     'bg-orange-100 text-orange-800',
    'DISPUTE_CREATED':   'bg-yellow-100 text-yellow-800',
};
const statusBadge = {
    'SUCCESS':          'bg-green-100 text-green-800',
    'FAILED':           'bg-red-100 text-red-800',
    'PENDING':          'bg-yellow-100 text-yellow-800',
    'PROCESSING':       'bg-blue-100 text-blue-800',
    'CANCELLED':        'bg-gray-100 text-gray-700',
    'EXPIRED':          'bg-orange-100 text-orange-800',
    'REFUNDED':         'bg-purple-100 text-purple-800',
    'PARTIAL_REFUNDED': 'bg-purple-100 text-purple-700',
};

let lastCount = 0;

function formatTime(iso) {
    return new Date(iso).toLocaleString('vi-VN', { hour12: false });
}

function formatAmount(amount, currency) {
    return new Intl.NumberFormat('vi-VN').format(amount) + ' ' + (currency || '');
}

async function fetchEvents() {
    try {
        const res = await fetch('/api/webhooks');
        if (!res.ok) return;
        const events = await res.json();

        document.getElementById('event-count').textContent = events.length;
        document.getElementById('status-bar').textContent =
            'Cập nhật lúc ' + new Date().toLocaleTimeString('vi-VN');

        if (lastCount === events.length && events.length > 0) return;
        lastCount = events.length;

        const tbody = document.getElementById('events-body');
        if (events.length === 0) {
            tbody.innerHTML = `
                <tr id="empty-row">
                    <td colspan="6" class="px-4 py-12 text-center text-gray-400 text-sm">
                        Chưa có webhook nào. Thực hiện thanh toán để nhận webhook.
                    </td>
                </tr>`;
            return;
        }

        tbody.innerHTML = events.map(e => {
            const etClass = eventTypeBadge[e.eventType] || 'bg-gray-100 text-gray-700';
            const stClass = statusBadge[e.status] || 'bg-gray-100 text-gray-700';
            return `
                <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">${formatTime(e.receivedAt)}</td>
                    <td class="px-4 py-3">
                        <span class="text-xs font-mono font-semibold uppercase">${e.gateway}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium ${etClass}">${e.eventType}</span>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">${e.orderId}</td>
                    <td class="px-4 py-3 text-right font-medium text-xs">${formatAmount(e.amount, e.currency)}</td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium ${stClass}">${e.status}</span>
                    </td>
                </tr>`;
        }).join('');
    } catch {
        document.getElementById('status-bar').textContent = 'Lỗi kết nối — thử lại...';
    }
}

async function clearEvents() {
    if (!confirm('Xóa toàn bộ webhook log?')) return;
    await fetch('/api/webhooks', {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
    });
    lastCount = -1;
    fetchEvents();
}

fetchEvents();
setInterval(fetchEvents, 3000);
</script>
@endsection
