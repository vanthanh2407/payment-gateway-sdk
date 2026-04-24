<?php

declare(strict_types=1);

namespace PaymentSdk\Enums;

enum PaymentStatus: string
{
    case PENDING          = 'pending';
    case PROCESSING       = 'processing';
    case SUCCESS          = 'success';
    case FAILED           = 'failed';
    case CANCELLED        = 'cancelled';
    case EXPIRED          = 'expired';
    case REFUNDED         = 'refunded';
    case PARTIAL_REFUNDED = 'partial_refunded';
}
