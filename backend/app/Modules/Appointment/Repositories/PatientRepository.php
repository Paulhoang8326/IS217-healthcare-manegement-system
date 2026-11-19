<?php

namespace App\Modules\Appointment\Repositories;

use App\Models\Patient;

class PatientRepository
{
    public function findByIdForUpdate(int $id): ?Patient
    {
        return Patient::whereKey($id)->lockForUpdate()->first();
    }
}
