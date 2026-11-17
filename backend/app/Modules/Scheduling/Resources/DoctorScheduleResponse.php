<?php

namespace App\Modules\Scheduling\Resources;

use App\Models\DoctorSchedule;
use App\Shared\Support\JavaDate;

final class DoctorScheduleResponse
{
    public static function toResponse(?DoctorSchedule $schedule): ?array
    {
        if ($schedule === null) {
            return null;
        }
        $shift = $schedule->getAttribute('shift');

        return [
            'doctorScheduleId' => $schedule->doctor_schedule_id,
            'doctorId' => $schedule->doctor?->doctor_id,
            'doctorName' => $schedule->doctor?->full_name,
            'specialtyId' => $schedule->doctor?->specialty?->specialty_id,
            'specialtyName' => $schedule->doctor?->specialty?->specialty_name,
            'roomId' => $schedule->room?->room_id,
            'roomCode' => $schedule->room?->room_code,
            'branchId' => $schedule->room?->branch?->branch_id,
            'branchName' => $schedule->room?->branch?->branch_name,
            'scheduleDate' => JavaDate::localDate($schedule->getAttribute('schedule_date')),
            'shift' => $shift?->value,
            'shiftStartTime' => $shift?->getStartTime(),
            'shiftEndTime' => $shift?->getEndTime(),
            'maxCapacity' => $schedule->max_capacity,
            'currentBookingCount' => $schedule->current_booking_count,
            'lastQueueNumber' => $schedule->last_queue_number,
            'note' => $schedule->note,
            'createdAt' => JavaDate::localDateTime($schedule->getAttribute('created_at')),
            'updatedAt' => JavaDate::localDateTime($schedule->getAttribute('updated_at')),
        ];
    }
}
