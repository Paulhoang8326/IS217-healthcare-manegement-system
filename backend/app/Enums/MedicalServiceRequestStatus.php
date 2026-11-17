<?php

namespace App\Enums;

enum MedicalServiceRequestStatus: string
{
    case NOT_COLLECTED = 'NOT_COLLECTED';
    case SAMPLE_COLLECTED = 'SAMPLE_COLLECTED';
    case RESULT_AVAILABLE = 'RESULT_AVAILABLE';
}
