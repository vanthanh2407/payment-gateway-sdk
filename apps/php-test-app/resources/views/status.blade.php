@extends('layouts.app')
@section('title', 'Kiểm tra giao dịch — Payment SDK')

@section('content')
<div class="max-w-lg mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Kiểm tra giao dịch</h1>

    @if(session('transaction_error'))
    <div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
        {{ session('transaction_error') }}
    </div>
    @endif

    @if($result)
    @php
        $statusColors = [
            'SUCCESS'          => 'bg-green-100 text-green-800',
            'FAILED'           => 'bg-red-100 text-red-800',
            'PENDING'          => 'bg-yellow-100 text-yellow-800',
            'PROCESSING'       => 'bg-blue-100 text-blue-800',
            'CANCELLED'        => 'bg-gray-100 text-gray-700',
            'EXPIRED'          => 'bg-orange-100 text-orange-800',
            'REFUNDED'         => 'bg-purple-100 text-purple-800',
            'PARTIAL_REFUNDED' => 'bg-purple-100 text-purple-700',
        ];
        $sc = $statusColors[$result['status']] ?? 'bg-gray-100 text-gray-700';
    @endphp

    <div class="mb-6 rounded-lg border {{ $result['success'] ? 'border-gray-200 bg-white' : 'border-red-200 bg-red-50' }} p-4">
        <div class="flex items-center gap-2 mb-4">
            <span class="font-semibold text-gray-900">Kết quả tra cứu</span>
            <span class="text-xs px-2 py-0.5 rounded-full font-mono bg-gray-100">{{ strtoupper($result['gateway']) }}</span>
        </div>

        <div class="grid grid-cols-2 gap-y-2 gap-x-4 text-sm">
            <div class="text-gray-500">Trạng thái:</div>
            <div><span class="text-xs px-2 py-0.5 rounded-full font-medium {{ $sc }}">{{ $result['status'] }}</span></div>

            <div class="text-gray-500">Order ID:</div>
            <div class="font-mono font-medium text-xs">{{ $result['orderId'] }}</div>

            @if($result['transactionId'])
            <div class="text-gray-500">Transaction ID:</div>
            <div class="font-mono font-medium text-xs">{{ $result['transactionId'] }}</div>
            @endif

            <div class="text-gray-500">Số tiền:</div>
            <div class="font-medium">{{ number_format($result['amount']) }} {{ $result['currency'] }}</div>
        </div>

        @if(!$result['success'] && $result['error'])
        <div class="mt-3 text-sm text-red-800">
            <span class="font-mono font-medium">[{{ $result['error']['code'] }}]</span>
            {{ $result['error']['message'] }}
        </div>
        @endif

        <details class="mt-3">
            <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">Raw response</summary>
            <pre class="mt-2 text-xs bg-gray-800 text-green-400 rounded p-3 overflow-auto max-h-48">{{ json_encode($result['rawResponse'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </details>
    </div>
    @endif

    <form method="POST" action="{{ route('status.query') }}" class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
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
            <label class="block text-sm font-medium text-gray-700 mb-1">Order ID <span class="text-red-500">*</span></label>
            <input type="text" name="order_id" required
                   value="{{ $input['orderId'] ?? request('orderId', '') }}"
                   placeholder="ORDER-12345678"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Transaction ID <span class="text-gray-400">(tùy chọn)</span>
            </label>
            <input type="text" name="transaction_id"
                   value="{{ $input['transactionId'] ?? '' }}"
                   placeholder="Để trống nếu không có"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>

        <button type="submit" @disabled(empty($gateways))
                class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-medium rounded-lg text-sm transition-colors">
            Tra cứu giao dịch
        </button>
    </form>
</div>
@endsection
