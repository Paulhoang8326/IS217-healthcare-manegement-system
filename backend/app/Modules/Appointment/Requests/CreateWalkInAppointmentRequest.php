<?php

namespace App\Modules\Appointment\Requests;

class CreateWalkInAppointmentRequest extends CreateAppointmentRequest
{
    protected function bodyFields(): array
    {
        return parent::bodyFields() + [
            'receivedAmount' => ['type' => 'BigDecimal', 'constraints' => [
                ['name' => 'NotNull', 'message' => 'Received amount must not be null'],
                ['name' => 'DecimalMin', 'value' => '0.00', 'inclusive' => false, 'message' => 'Received amount must be greater than zero'],
            ]],
            'receiptNumber' => ['type' => 'String', 'constraints' => [['name' => 'Size', 'max' => 100, 'message' => 'Receipt number must not exceed 100 characters']]],
            'note' => ['type' => 'String', 'constraints' => [['name' => 'Size', 'max' => 1000, 'message' => 'Note must not exceed 1000 characters']]],
        ];
    }
}
