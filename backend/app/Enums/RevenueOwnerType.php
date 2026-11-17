<?php

namespace App\Enums;

enum RevenueOwnerType: string
{
    case APPOINTMENT = 'APPOINTMENT';
    case MEDICAL_RECORD = 'MEDICAL_RECORD';
    case PRESCRIPTION = 'PRESCRIPTION';
}
