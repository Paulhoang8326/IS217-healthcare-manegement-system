<?php

namespace App\Enums;

enum PaymentTransactionStatus: string
{
    case PENDING = 'PENDING';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
}
