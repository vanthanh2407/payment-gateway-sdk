<?php

declare(strict_types=1);

namespace App\Services;

use PaymentSdk\PaymentSDK;
use PaymentSdk\Gateways\VNPayGateway;
use PaymentSdk\Gateways\MoMoGateway;
use PaymentSdk\Gateways\ZaloPayGateway;
use PaymentSdk\Gateways\StripeGateway;
use PaymentSdk\Gateways\VietQRGateway;

final class SDKService
{
    private static ?PaymentSDK $instance = null;

    public static function get(): PaymentSDK
    {
        return self::$instance ??= self::build();
    }

    private static function build(): PaymentSDK
    {
        $sdk = new PaymentSDK();

        if (env('VNPAY_TMN_CODE') && env('VNPAY_HASH_SECRET')) {
            $sdk->use('vnpay', new VNPayGateway(
                tmnCode:    (string) env('VNPAY_TMN_CODE'),
                hashSecret: (string) env('VNPAY_HASH_SECRET'),
                sandbox:    true,
            ));
        }

        if (env('MOMO_PARTNER_CODE') && env('MOMO_ACCESS_KEY') && env('MOMO_SECRET_KEY')) {
            $sdk->use('momo', new MoMoGateway(
                partnerCode: (string) env('MOMO_PARTNER_CODE'),
                accessKey:   (string) env('MOMO_ACCESS_KEY'),
                secretKey:   (string) env('MOMO_SECRET_KEY'),
                sandbox:     true,
            ));
        }

        if (env('ZALOPAY_APP_ID') && env('ZALOPAY_KEY1') && env('ZALOPAY_KEY2')) {
            $sdk->use('zalopay', new ZaloPayGateway(
                appId:   (int) env('ZALOPAY_APP_ID'),
                key1:    (string) env('ZALOPAY_KEY1'),
                key2:    (string) env('ZALOPAY_KEY2'),
                sandbox: true,
            ));
        }

        if (env('STRIPE_SECRET_KEY') && env('STRIPE_WEBHOOK_SECRET')) {
            $sdk->use('stripe', new StripeGateway(
                secretKey:     (string) env('STRIPE_SECRET_KEY'),
                webhookSecret: (string) env('STRIPE_WEBHOOK_SECRET'),
            ));
        }

        if (
            env('VIETQR_CLIENT_ID') && env('VIETQR_API_KEY') &&
            env('VIETQR_BANK_CODE') && env('VIETQR_BANK_ACCOUNT') &&
            env('VIETQR_ACCOUNT_NAME')
        ) {
            $sdk->use('vietqr', new VietQRGateway(
                clientId:    (string) env('VIETQR_CLIENT_ID'),
                apiKey:      (string) env('VIETQR_API_KEY'),
                bankCode:    (string) env('VIETQR_BANK_CODE'),
                bankAccount: (string) env('VIETQR_BANK_ACCOUNT'),
                accountName: (string) env('VIETQR_ACCOUNT_NAME'),
                sandbox:     true,
            ));
        }

        return $sdk;
    }
}
