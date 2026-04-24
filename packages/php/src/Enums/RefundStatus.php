<?php

declare(strict_types=1);

namespace PaymentSdk\Enums;

enum RefundStatus: string
{
    case PENDING  = 'pending';
    case SUCCESS  = 'success';
    case FAILED   = 'failed';
    case REJECTED = 'rejected';
}
