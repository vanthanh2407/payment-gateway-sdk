<?php

declare(strict_types=1);

namespace PaymentSdk\Enums;

enum WebhookEventType: string
{
    case PAYMENT_SUCCESS   = 'payment.success';
    case PAYMENT_FAILED    = 'payment.failed';
    case PAYMENT_CANCELLED = 'payment.cancelled';
    case REFUND_SUCCESS    = 'refund.success';
    case REFUND_FAILED     = 'refund.failed';
    case DISPUTE_CREATED   = 'dispute.created';
}
