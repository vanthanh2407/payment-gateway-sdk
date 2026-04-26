@extends('layouts.app')
@section('title', 'Dashboard — Payment SDK')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Payment Gateway Dashboard</h1>
    <p class="text-gray-500 text-sm mt-1">Trạng thái cấu hình của các payment gateway</p>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    @foreach($gateways as $gw)
    @php
        $colors = [
            'red'    => ['border' => 'border-red-200',    'badge_yes' => 'bg-red-100 text-red-700',    'badge_no' => 'bg-gray-100 text-gray-500', 'btn' => 'bg-red-600 hover:bg-red-700'],
            'pink'   => ['border' => 'border-pink-200',   'badge_yes' => 'bg-pink-100 text-pink-700',   'badge_no' => 'bg-gray-100 text-gray-500', 'btn' => 'bg-pink-600 hover:bg-pink-700'],
            'blue'   => ['border' => 'border-blue-200',   'badge_yes' => 'bg-blue-100 text-blue-700',   'badge_no' => 'bg-gray-100 text-gray-500', 'btn' => 'bg-blue-600 hover:bg-blue-700'],
            'indigo' => ['border' => 'border-indigo-200', 'badge_yes' => 'bg-indigo-100 text-indigo-700','badge_no' => 'bg-gray-100 text-gray-500', 'btn' => 'bg-indigo-600 hover:bg-indigo-700'],
            'green'  => ['border' => 'border-green-200',  'badge_yes' => 'bg-green-100 text-green-700',  'badge_no' => 'bg-gray-100 text-gray-500', 'btn' => 'bg-green-600 hover:bg-green-700'],
        ];
        $c = $colors[$gw['color']] ?? $colors['blue'];
    @endphp

    <div class="bg-white rounded-lg border {{ $c['border'] }} p-5 flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-gray-900 text-lg">{{ $gw['name'] }}</h2>
            @if($gw['configured'])
                <span class="text-xs px-2 py-1 rounded-full font-medium {{ $c['badge_yes'] }}">✓ Đã cấu hình</span>
            @else
                <span class="text-xs px-2 py-1 rounded-full font-medium {{ $c['badge_no'] }}">Chưa cấu hình</span>
            @endif
        </div>

        <p class="text-gray-500 text-sm">{{ $gw['description'] }}</p>

        <div class="text-xs text-gray-600 space-y-1">
            <div><span class="font-medium">Tiền tệ:</span> {{ implode(', ', $gw['currencies']) }}</div>
            <div><span class="font-medium">Phương thức:</span> {{ implode(', ', $gw['methods']) }}</div>
        </div>

        @if($gw['configured'])
        <div class="mt-1">
            <p class="text-xs text-gray-500 mb-1 font-medium">Webhook URL:</p>
            <div class="flex items-center gap-2">
                <code class="text-xs bg-gray-50 border border-gray-200 rounded px-2 py-1 flex-1 truncate font-mono">
                    {{ $gw['webhookUrl'] }}
                </code>
                <button onclick="navigator.clipboard.writeText('{{ $gw['webhookUrl'] }}')"
                        class="text-xs text-gray-400 hover:text-gray-700 px-1 py-1" title="Copy">
                    📋
                </button>
            </div>
        </div>

        <a href="{{ route('pay.index') }}?gateway={{ $gw['id'] }}"
           class="mt-auto text-center text-xs text-white font-medium py-2 rounded {{ $c['btn'] }} transition-colors">
            Tạo thanh toán →
        </a>
        @else
        <p class="mt-auto text-xs text-gray-400">Thêm credentials vào .env để bật gateway này.</p>
        @endif
    </div>
    @endforeach
</div>

<div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-4">
    <h3 class="font-medium text-blue-900 text-sm mb-2">Hướng dẫn test webhook với ngrok</h3>
    <ol class="text-sm text-blue-800 space-y-1 list-decimal list-inside">
        <li>Chạy: <code class="font-mono bg-blue-100 px-1 rounded">ngrok http 8000</code></li>
        <li>Cập nhật <code class="font-mono bg-blue-100 px-1 rounded">APP_URL</code> trong <code class="font-mono bg-blue-100 px-1 rounded">.env</code></li>
        <li>Restart server: <code class="font-mono bg-blue-100 px-1 rounded">php artisan serve</code></li>
        <li>Đăng ký Webhook URL ở trên vào sandbox của từng gateway</li>
    </ol>
</div>
@endsection
