<?php

namespace App\Enums;

enum AdmissionStatus: string
{
    case PENDING = 'PENDING';
    case ADMITTED = 'ADMITTED';
    case DISCHARGED = 'DISCHARGED';
    case CANCELLED = 'CANCELLED';
}
