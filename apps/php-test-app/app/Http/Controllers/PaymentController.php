<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SDKService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PaymentSdk\Exceptions\PaymentSDKException;

class PaymentController extends Controller
{
    /** @var array<string, string> */
    private static array $CURRENCIES = [
        'vnpay'   => 'VND',
        'momo'    => 'VND',
        'zalopay' => 'VND',
        'stripe'  => 'USD',
        'vietqr'  => 'VND',
    ];

    public function index(): View
    {
        $configured = SDKService::get()->listGateways();
        $orderId    = 'ORDER-' . substr((string) time(), -8);

        return view('pay', [
            'gateways'   => $configured,
            'currencies' => self::$CURRENCIES,
            'orderId'    => $orderId,
            'result'     => session('payment_result'),
            'error'      => session('payment_error'),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $gateway     = $request->input('gateway', '');
        $orderId     = $request->input('order_id', '');
        $amount      = (int) $request->input('amount', 0);
        $currency    = $request->input('currency', 'VND');
        $description = $request->input('description', 'Test payment');
        $returnUrl   = $request->input('return_url', url('/status'));
        $ipnUrl      = $request->input('ipn_url', url("/webhook/{$gateway}"));

        if (!$gateway || !$orderId || $amount <= 0) {
            return redirect()->route('pay.index')
                ->with('payment_error', 'Vui lòng điền đầy đủ thông tin.');
        }

        try {
            $result = SDKService::get()->createPayment($gateway, [
                'orderId'     => $orderId,
                'amount'      => $amount,
                'currency'    => $currency,
                'description' => $description,
                'returnUrl'   => $returnUrl,
                'ipnUrl'      => $ipnUrl,
            ]);

            return redirect()->route('pay.index')->with('payment_result', [
                'success'       => $result->success,
                'paymentUrl'    => $result->paymentUrl,
                'transactionId' => $result->transactionId,
                'orderId'       => $result->orderId,
                'amount'        => $result->amount,
                'currency'      => $result->currency,
                'status'        => $result->status->value,
                'gateway'       => $result->gateway,
                'rawResponse'   => $result->rawResponse,
                'error'         => $result->error ? [
                    'code'           => $result->error->code->value,
                    'message'        => $result->error->message,
                    'gatewayCode'    => $result->error->gatewayCode,
                    'gatewayMessage' => $result->error->gatewayMessage,
                ] : null,
            ]);
        } catch (PaymentSDKException $e) {
            return redirect()->route('pay.index')
                ->with('payment_error', "[{$e->errorCode->value}] {$e->getMessage()}");
        } catch (\Throwable $e) {
            return redirect()->route('pay.index')
                ->with('payment_error', $e->getMessage());
        }
    }
}
