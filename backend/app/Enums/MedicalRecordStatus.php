<?php

namespace App\Enums;

enum MedicalRecordStatus: string
{
    case DRAFT = 'DRAFT';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case LOCKED = 'LOCKED';
}
