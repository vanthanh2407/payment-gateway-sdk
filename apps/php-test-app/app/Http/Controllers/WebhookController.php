<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SDKService;
use App\Services\WebhookStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PaymentSdk\Enums\ErrorCode;
use PaymentSdk\Exceptions\PaymentSDKException;

class WebhookController extends Controller
{
    public function receive(Request $request, string $gateway): JsonResponse
    {
        // Multi-format payload: JSON body → form data → query params (VNPay GET)
        if ($request->isJson()) {
            $payload = $request->json()->all();
        } else {
            $formData = $request->all();
            $payload  = !empty($formData) ? $formData : (json_decode($request->getContent(), true) ?? []);
        }

        $headers = collect($request->headers->all())
            ->mapWithKeys(fn (array $v, string $k): array => [$k => $v[0]])
            ->all();

        try {
            $event = SDKService::get()->verifyWebhook($gateway, $payload, $headers);
            WebhookStore::add($event, $request->getContent() ?: json_encode($payload), $gateway);
            return response()->json(self::ackFor($gateway));
        } catch (PaymentSDKException $e) {
            $status = $e->errorCode === ErrorCode::INVALID_SIGNATURE ? 400 : 422;
            return response()->json(['error' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /** @return array<string, mixed> */
    private static function ackFor(string $gateway): array
    {
        return match ($gateway) {
            'vnpay'   => ['RspCode' => '00', 'Message' => 'Confirmed'],
            'momo'    => ['resultCode' => 0, 'message' => 'Success'],
            'zalopay' => ['return_code' => 1, 'return_message' => 'Success'],
            default   => ['received' => true],
        };
    }
}
