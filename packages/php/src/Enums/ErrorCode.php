<?php

declare(strict_types=1);

namespace PaymentSdk\Enums;

enum ErrorCode: string
{
    case UNKNOWN_ERROR             = 'UNKNOWN_ERROR';
    case NETWORK_ERROR             = 'NETWORK_ERROR';
    case TIMEOUT                   = 'TIMEOUT';
    case INVALID_CONFIG            = 'INVALID_CONFIG';
    case INVALID_INPUT             = 'INVALID_INPUT';
    case AUTHENTICATION_FAILED     = 'AUTHENTICATION_FAILED';
    case INVALID_SIGNATURE         = 'INVALID_SIGNATURE';
    case PAYMENT_FAILED            = 'PAYMENT_FAILED';
    case PAYMENT_CANCELLED         = 'PAYMENT_CANCELLED';
    case PAYMENT_EXPIRED           = 'PAYMENT_EXPIRED';
    case INSUFFICIENT_FUNDS        = 'INSUFFICIENT_FUNDS';
    case CARD_DECLINED             = 'CARD_DECLINED';
    case DUPLICATE_ORDER           = 'DUPLICATE_ORDER';
    case TRANSACTION_NOT_FOUND     = 'TRANSACTION_NOT_FOUND';
    case REFUND_FAILED             = 'REFUND_FAILED';
    case REFUND_ALREADY_PROCESSED  = 'REFUND_ALREADY_PROCESSED';
    case WEBHOOK_PROCESSING_FAILED = 'WEBHOOK_PROCESSING_FAILED';
    case GATEWAY_ERROR             = 'GATEWAY_ERROR';
}
