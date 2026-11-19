<?php

namespace App\Modules\Appointment\Repositories;

use App\Models\ConsultationFee;

class ConsultationFeeRepository
{
    public function findById(int $id): ?ConsultationFee
    {
        return ConsultationFee::find($id);
    }

    public function findFirstBySpecialtyRef_SpecialtyIdAndIsActive(int $id, int $active): ?ConsultationFee
    {
        return ConsultationFee::where('specialty_id', $id)->where('is_active', $active)->first();
    }
}
