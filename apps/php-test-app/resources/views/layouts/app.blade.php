<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Payment SDK PHP Test App')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50 min-h-screen">

<nav class="bg-white border-b border-gray-200 shadow-sm">
    <div class="max-w-6xl mx-auto px-4">
        <div class="flex items-center justify-between h-14">
            <span class="font-semibold text-gray-800 text-sm">Payment SDK — PHP Test App</span>
            <div class="flex space-x-1">
                <a href="{{ route('dashboard') }}"
                   class="px-3 py-2 rounded text-sm font-medium {{ request()->routeIs('dashboard') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                    Dashboard
                </a>
                <a href="{{ route('pay.index') }}"
                   class="px-3 py-2 rounded text-sm font-medium {{ request()->routeIs('pay.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                    Tạo thanh toán
                </a>
                <a href="{{ route('status.index') }}"
                   class="px-3 py-2 rounded text-sm font-medium {{ request()->routeIs('status.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                    Kiểm tra GD
                </a>
                <a href="{{ route('refund.index') }}"
                   class="px-3 py-2 rounded text-sm font-medium {{ request()->routeIs('refund.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                    Hoàn tiền
                </a>
                <a href="/webhooks"
                   class="px-3 py-2 rounded text-sm font-medium {{ request()->is('webhooks') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                    Webhook Log
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="max-w-6xl mx-auto px-4 py-8">
    @yield('content')
</main>

</body>
</html>
