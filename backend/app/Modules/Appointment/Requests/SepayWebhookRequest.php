<?php

namespace App\Modules\Appointment\Requests;

use App\Shared\Http\Requests\BaseRequest;

class SepayWebhookRequest extends BaseRequest
{
    protected function bodyFields(): array
    {
        return [
            'id' => 'Long', 'gateway' => 'String', 'transactionDate' => 'String', 'accountNumber' => 'String',
            'code' => 'String', 'content' => 'String', 'transferType' => 'String', 'transferAmount' => 'Long',
            'accumulated' => 'Long', 'subAccount' => 'String', 'referenceCode' => 'String', 'description' => 'String',
        ];
    }
}
