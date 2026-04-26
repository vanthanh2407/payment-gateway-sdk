<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SDKService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PaymentSdk\Exceptions\PaymentSDKException;

class RefundController extends Controller
{
    public function index(): View
    {
        $configured = SDKService::get()->listGateways();

        return view('refund', [
            'gateways' => $configured,
            'result'   => session('refund_result'),
            'error'    => session('refund_error'),
            'input'    => session('refund_input'),
        ]);
    }

    public function process(Request $request): RedirectResponse
    {
        $gateway       = $request->input('gateway', '');
        $transactionId = $request->input('transaction_id', '');
        $orderId       = $request->input('order_id', '');
        $amount        = (int) $request->input('amount', 0);
        $reason        = $request->input('reason', '');

        if (!$gateway || !$transactionId || !$orderId || $amount <= 0) {
            return redirect()->route('refund.index')
                ->with('refund_error', 'Vui lòng điền đầy đủ thông tin hoàn tiền.');
        }

        try {
            $result = SDKService::get()->refund($gateway, [
                'transactionId' => $transactionId,
                'orderId'       => $orderId,
                'amount'        => $amount,
                'reason'        => $reason ?: null,
            ]);

            return redirect()->route('refund.index')
                ->with('refund_input', compact('gateway', 'transactionId', 'orderId', 'amount', 'reason'))
                ->with('refund_result', [
                    'success'       => $result->success,
                    'refundId'      => $result->refundId,
                    'transactionId' => $result->transactionId,
                    'orderId'       => $result->orderId,
                    'amount'        => $result->amount,
                    'status'        => $result->status->value,
                    'rawResponse'   => $result->rawResponse,
                    'error'         => $result->error ? [
                        'code'           => $result->error->code->value,
                        'message'        => $result->error->message,
                        'gatewayCode'    => $result->error->gatewayCode,
                        'gatewayMessage' => $result->error->gatewayMessage,
                    ] : null,
                ]);
        } catch (PaymentSDKException $e) {
            return redirect()->route('refund.index')
                ->with('refund_input', compact('gateway', 'transactionId', 'orderId', 'amount', 'reason'))
                ->with('refund_error', "[{$e->errorCode->value}] {$e->getMessage()}");
        } catch (\Throwable $e) {
            return redirect()->route('refund.index')
                ->with('refund_input', compact('gateway', 'transactionId', 'orderId', 'amount', 'reason'))
                ->with('refund_error', $e->getMessage());
        }
    }
}
