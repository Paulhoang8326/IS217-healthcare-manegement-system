<?php

namespace App\Enums;

enum BedStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case OCCUPIED = 'OCCUPIED';
    case MAINTENANCE = 'MAINTENANCE';
}
