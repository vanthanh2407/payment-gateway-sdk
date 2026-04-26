@extends('layouts.app')
@section('title', 'Hoàn tiền — Payment SDK')

@section('content')
<div class="max-w-lg mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Hoàn tiền</h1>

    @if(session('refund_error'))
    <div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
        {{ session('refund_error') }}
    </div>
    @endif

    @if($result)
    @php
        $statusColors = [
            'SUCCESS'  => 'bg-green-100 text-green-800',
            'FAILED'   => 'bg-red-100 text-red-800',
            'PENDING'  => 'bg-yellow-100 text-yellow-800',
            'REJECTED' => 'bg-orange-100 text-orange-800',
        ];
        $sc = $statusColors[$result['status']] ?? 'bg-gray-100 text-gray-700';
    @endphp

    <div class="mb-6 rounded-lg border {{ $result['success'] ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }} p-4">
        <div class="flex items-center gap-2 mb-3">
            <span class="text-lg">{{ $result['success'] ? '✓' : '✗' }}</span>
            <span class="font-semibold {{ $result['success'] ? 'text-green-900' : 'text-red-900' }}">
                {{ $result['success'] ? 'Hoàn tiền thành công' : 'Hoàn tiền thất bại' }}
            </span>
        </div>

        <div class="grid grid-cols-2 gap-y-2 gap-x-4 text-sm">
            <div class="text-gray-500">Trạng thái:</div>
            <div><span class="text-xs px-2 py-0.5 rounded-full font-medium {{ $sc }}">{{ $result['status'] }}</span></div>

            @if($result['refundId'])
            <div class="text-gray-500">Refund ID:</div>
            <div class="font-mono text-xs font-medium">{{ $result['refundId'] }}</div>
            @endif

            <div class="text-gray-500">Order ID:</div>
            <div class="font-mono text-xs font-medium">{{ $result['orderId'] }}</div>

            <div class="text-gray-500">Transaction ID:</div>
            <div class="font-mono text-xs font-medium">{{ $result['transactionId'] }}</div>

            <div class="text-gray-500">Số tiền hoàn:</div>
            <div class="font-medium">{{ number_format($result['amount']) }}</div>
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

    <form method="POST" action="{{ route('refund.process') }}" class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Gateway</label>
            @if(empty($gateways))
            <p class="text-sm text-red-600">Chưa có gateway nào được cấu hình.</p>
            @else
            <select name="gateway" required
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                @foreach($gateways as $gw)
                <option value="{{ $gw }}"
                    @selected(($input['gateway'] ?? null) === $gw)>
                    {{ strtoupper($gw) }}
                </option>
                @endforeach
            </select>
            @endif
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Transaction ID <span class="text-red-500">*</span></label>
            <input type="text" name="transaction_id" required
                   value="{{ $input['transactionId'] ?? '' }}"
                   placeholder="Gateway transaction ID"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Order ID <span class="text-red-500">*</span></label>
            <input type="text" name="order_id" required
                   value="{{ $input['orderId'] ?? '' }}"
                   placeholder="ORDER-12345678"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền hoàn <span class="text-red-500">*</span></label>
            <input type="number" name="amount" required min="1"
                   value="{{ $input['amount'] ?? '' }}"
                   placeholder="Nhập số tiền cần hoàn"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Lý do <span class="text-gray-400">(tùy chọn)</span>
            </label>
            <input type="text" name="reason"
                   value="{{ $input['reason'] ?? '' }}"
                   placeholder="Khách hàng yêu cầu hoàn tiền"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <button type="submit" @disabled(empty($gateways))
                class="w-full py-2.5 bg-orange-600 hover:bg-orange-700 disabled:opacity-50 text-white font-medium rounded-lg text-sm transition-colors">
            Xử lý hoàn tiền
        </button>
    </form>
</div>
@endsection
