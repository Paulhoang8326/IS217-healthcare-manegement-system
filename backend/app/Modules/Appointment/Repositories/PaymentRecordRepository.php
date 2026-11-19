<?php

namespace App\Modules\Appointment\Repositories;

use App\Models\PaymentRecord;

class PaymentRecordRepository
{
    public function findByAppointmentIdForUpdate(int $id): ?PaymentRecord
    {
        return PaymentRecord::where('appointment_id', $id)->lockForUpdate()->first();
    }

    public function save(PaymentRecord $record): PaymentRecord
    {
        $record->save();

        return $record;
    }
}
