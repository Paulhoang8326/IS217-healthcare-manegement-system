<?php

namespace App\Modules\Appointment\Requests;

use App\Shared\Http\Requests\BaseRequest;

class ConfirmManualPaymentRequest extends BaseRequest
{
    protected function validatesBody(): bool
    {
        return true;
    }

    protected function bodyFields(): array
    {
        return [
            'receiptNumber' => ['type' => 'String', 'constraints' => [
                ['name' => 'NotBlank', 'message' => 'Receipt number is required'],
                ['name' => 'Size', 'max' => 100, 'message' => 'Receipt number must not exceed 100 characters'],
            ]],
            'note' => ['type' => 'String', 'constraints' => [['name' => 'Size', 'max' => 500, 'message' => 'Note must not exceed 500 characters']]],
        ];
    }
}
