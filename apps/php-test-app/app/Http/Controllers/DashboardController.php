<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SDKService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** @var array<string, array<string, mixed>> */
    private static array $GATEWAYS = [
        'vnpay' => [
            'name'        => 'VNPay',
            'color'       => 'red',
            'currencies'  => ['VND'],
            'methods'     => ['card', 'banking', 'qr'],
            'description' => 'Cổng thanh toán VNPay',
        ],
        'momo' => [
            'name'        => 'MoMo',
            'color'       => 'pink',
            'currencies'  => ['VND'],
            'methods'     => ['wallet', 'card', 'banking', 'qr'],
            'description' => 'Ví điện tử MoMo',
        ],
        'zalopay' => [
            'name'        => 'ZaloPay',
            'color'       => 'blue',
            'currencies'  => ['VND'],
            'methods'     => ['wallet', 'card', 'banking', 'qr'],
            'description' => 'Ví điện tử ZaloPay',
        ],
        'stripe' => [
            'name'        => 'Stripe',
            'color'       => 'indigo',
            'currencies'  => ['USD', 'EUR', 'GBP', 'SGD', 'VND'],
            'methods'     => ['card', 'bank_transfer'],
            'description' => 'Thanh toán quốc tế Stripe',
        ],
        'vietqr' => [
            'name'        => 'VietQR',
            'color'       => 'green',
            'currencies'  => ['VND'],
            'methods'     => ['banking', 'qr'],
            'description' => 'QR Code VietQR',
        ],
    ];

    public function index(): View
    {
        $configured = SDKService::get()->listGateways();

        $gateways = array_map(function (string $id, array $meta) use ($configured): array {
            return [
                'id'          => $id,
                'name'        => $meta['name'],
                'color'       => $meta['color'],
                'currencies'  => $meta['currencies'],
                'methods'     => $meta['methods'],
                'description' => $meta['description'],
                'configured'  => in_array($id, $configured, true),
                'webhookUrl'  => url("/webhook/{$id}"),
            ];
        }, array_keys(self::$GATEWAYS), array_values(self::$GATEWAYS));

        return view('dashboard', compact('gateways'));
    }
}
