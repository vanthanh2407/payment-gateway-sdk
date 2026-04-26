@extends('layouts.app')
@section('title', 'Tạo thanh toán — Payment SDK')

@section('content')
<div class="max-w-lg mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Tạo thanh toán</h1>

    @if(session('payment_error'))
    <div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
        {{ session('payment_error') }}
    </div>
    @endif

    @if($result)
    <div class="mb-6 rounded-lg border {{ $result['success'] ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }} p-4">
        <div class="flex items-center gap-2 mb-3">
            <span class="text-lg">{{ $result['success'] ? '✓' : '✗' }}</span>
            <span class="font-semibold {{ $result['success'] ? 'text-green-900' : 'text-red-900' }}">
                {{ $result['success'] ? 'Tạo thanh toán thành công' : 'Tạo thanh toán thất bại' }}
            </span>
            <span class="text-xs px-2 py-0.5 rounded-full bg-white border font-mono">{{ strtoupper($result['gateway']) }}</span>
        </div>

        @if($result['success'] && $result['paymentUrl'])
            @if($result['gateway'] === 'vietqr' && str_starts_with($result['paymentUrl'], 'data:image'))
            <div class="mb-3">
                <p class="text-sm text-green-800 mb-2">QR Code (VietQR):</p>
                <img src="{{ $result['paymentUrl'] }}" alt="VietQR Code" class="max-w-48 border rounded">
                <a href="{{ $result['paymentUrl'] }}" download="vietqr.png"
                   class="mt-2 inline-block text-xs text-green-700 underline">Tải QR code</a>
            </div>
            @else
            <a href="{{ $result['paymentUrl'] }}" target="_blank"
               class="inline-block mb-3 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded transition-colors">
                Mở trang thanh toán →
            </a>
            <div class="text-xs text-green-700 font-mono break-all mb-2">{{ $result['paymentUrl'] }}</div>
            @endif
        @endif

        <div class="grid grid-cols-2 gap-2 text-sm">
            <div class="text-gray-600">Order ID:</div><div class="font-mono font-medium">{{ $result['orderId'] }}</div>
            <div class="text-gray-600">Số tiền:</div><div class="font-medium">{{ number_format($result['amount']) }} {{ $result['currency'] }}</div>
            <div class="text-gray-600">Trạng thái:</div>
            <div><span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-800">{{ $result['status'] }}</span></div>
        </div>

        @if(!$result['success'] && $result['error'])
        <div class="mt-3 text-sm text-red-800">
            <span class="font-mono font-medium">[{{ $result['error']['code'] }}]</span>
            {{ $result['error']['message'] }}
            @if($result['error']['gatewayCode'])
            <span class="text-xs text-red-600">(gateway: {{ $result['error']['gatewayCode'] }})</span>
            @endif
        </div>
        @endif

        <details class="mt-3">
            <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">Raw response</summary>
            <pre class="mt-2 text-xs bg-gray-800 text-green-400 rounded p-3 overflow-auto max-h-48">{{ json_encode($result['rawResponse'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </details>
    </div>
    @endif

    <form method="POST" action="{{ route('pay.create') }}" class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Gateway</label>
            @if(empty($gateways))
            <p class="text-sm text-red-600">Chưa có gateway nào được cấu hình. Thêm credentials vào .env.</p>
            @else
            <select name="gateway" id="gateway-select" required
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    onchange="updateCurrency(this.value)">
                @foreach($gateways as $gw)
                <option value="{{ $gw }}"
                    @selected(request('gateway') === $gw || (!request('gateway') && $loop->first))>
                    {{ strtoupper($gw) }}
                </option>
                @endforeach
            </select>
            @endif
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Order ID</label>
            <div class="flex gap-2">
                <input type="text" name="order_id" id="order-id" required
                       value="{{ $orderId }}"
                       class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button type="button" onclick="generateOrderId()"
                        class="px-3 py-2 text-xs border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50">
                    Tạo mới
                </button>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền</label>
                <input type="number" name="amount" required min="1" value="100000"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tiền tệ</label>
                <input type="text" name="currency" id="currency-field" readonly
                       value="VND"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500 cursor-not-allowed">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mô tả</label>
            <input type="text" name="description" value="Test payment"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Return URL</label>
            <input type="url" name="return_url" value="{{ url('/status') }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">IPN / Webhook URL</label>
            <input type="url" name="ipn_url" id="ipn-url"
                   value="{{ url('/webhook/' . ($gateways[0] ?? 'vnpay')) }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <button type="submit" @disabled(empty($gateways))
                class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium rounded-lg text-sm transition-colors">
            Tạo thanh toán
        </button>
    </form>
</div>

<script>
const currencies = @json($currencies);
const appUrl = '{{ rtrim(url('/'), '/') }}';

function updateCurrency(gateway) {
    document.getElementById('currency-field').value = currencies[gateway] ?? 'VND';
    document.getElementById('ipn-url').value = appUrl + '/webhook/' + gateway;
}

function generateOrderId() {
    document.getElementById('order-id').value = 'ORDER-' + Date.now().toString().slice(-8);
}

document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('gateway-select');
    if (sel) updateCurrency(sel.value);
});
</script>
@endsection
