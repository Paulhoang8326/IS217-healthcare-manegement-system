<?php

namespace App\Modules\Appointment\Repositories;

use App\Models\DoctorSchedule;

class DoctorScheduleRepository extends VersionedRepository
{
    public function findByIdForUpdate(int $id): ?DoctorSchedule
    {
        return DoctorSchedule::whereKey($id)->lockForUpdate()->first();
    }

    public function save(DoctorSchedule $schedule): DoctorSchedule
    {
        return $this->saveVersioned($schedule);
    }
}
