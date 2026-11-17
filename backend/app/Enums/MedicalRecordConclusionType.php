<?php

namespace App\Enums;

enum MedicalRecordConclusionType: string
{
    case COMPLETED = 'COMPLETED';
    case ADMISSION_REQUIRED = 'ADMISSION_REQUIRED';
}
