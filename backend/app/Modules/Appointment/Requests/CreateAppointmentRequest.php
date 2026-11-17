<?php

namespace App\Modules\Appointment\Requests;

use App\Shared\Http\Requests\BaseRequest;

class CreateAppointmentRequest extends BaseRequest
{
    protected function validatesBody(): bool
    {
        return true;
    }

    protected function bodyFields(): array
    {
        return [
            'patientId' => ['type' => 'Long', 'constraints' => [['name' => 'NotNull', 'message' => 'Patient id must not be null']]],
            'doctorScheduleId' => ['type' => 'Long', 'constraints' => [['name' => 'NotNull', 'message' => 'Doctor schedule id must not be null']]],
            'initialSymptoms' => ['type' => 'String', 'constraints' => [
                ['name' => 'NotBlank', 'message' => 'Initial symptoms must not be blank'],
                ['name' => 'Size', 'max' => 4000, 'message' => 'Initial symptoms must not exceed 4000 characters'],
            ]],
            'visitReason' => ['type' => 'String', 'constraints' => [
                ['name' => 'NotBlank', 'message' => 'Visit reason must not be blank'],
                ['name' => 'Size', 'max' => 500, 'message' => 'Visit reason must not exceed 500 characters'],
            ]],
            'feeId' => 'Long',
        ];
    }
}
