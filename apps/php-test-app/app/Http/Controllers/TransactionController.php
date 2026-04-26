<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SDKService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PaymentSdk\Exceptions\PaymentSDKException;

class TransactionController extends Controller
{
    public function index(): View
    {
        $configured = SDKService::get()->listGateways();

        return view('status', [
            'gateways' => $configured,
            'result'   => session('transaction_result'),
            'error'    => session('transaction_error'),
            'input'    => session('transaction_input'),
        ]);
    }

    public function query(Request $request): RedirectResponse
    {
        $gateway       = $request->input('gateway', '');
        $orderId       = $request->input('order_id', '');
        $transactionId = $request->input('transaction_id', '');

        if (!$gateway || !$orderId) {
            return redirect()->route('status.index')
                ->with('transaction_error', 'Vui lòng nhập Gateway và Order ID.');
        }

        try {
            $result = SDKService::get()->getTransaction(
                $gateway,
                $transactionId ?: $orderId,
                $orderId ?: null,
            );

            return redirect()->route('status.index')
                ->with('transaction_input', compact('gateway', 'orderId', 'transactionId'))
                ->with('transaction_result', [
                    'success'       => $result->success,
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
            return redirect()->route('status.index')
                ->with('transaction_input', compact('gateway', 'orderId', 'transactionId'))
                ->with('transaction_error', "[{$e->errorCode->value}] {$e->getMessage()}");
        } catch (\Throwable $e) {
            return redirect()->route('status.index')
                ->with('transaction_input', compact('gateway', 'orderId', 'transactionId'))
                ->with('transaction_error', $e->getMessage());
        }
    }
}
