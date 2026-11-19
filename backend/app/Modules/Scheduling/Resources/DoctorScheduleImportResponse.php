<?php

namespace App\Modules\Scheduling\Resources;

final class DoctorScheduleImportResponse
{
    public static function toResponse(array $schedules): array
    {
        return ['createdCount' => count($schedules), 'schedules' => $schedules];
    }
}
