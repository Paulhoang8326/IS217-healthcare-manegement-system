<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case CHECKED_IN = 'CHECKED_IN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case PAYMENT_EXPIRED = 'PAYMENT_EXPIRED';
}
