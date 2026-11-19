<?php

namespace App\Modules\Scheduling\Repositories;

use App\Models\DoctorSchedule;
use App\Shared\Support\OptimisticLock;
use Illuminate\Database\Eloquent\Collection;

class DoctorScheduleRepository
{
    public function findById(int $id): ?DoctorSchedule
    {
        return DoctorSchedule::find($id);
    }

    /** @return Collection<int, DoctorSchedule> */
    public function findAllByFilters(?string $date, ?string $start, ?string $end, ?int $doctorId, ?int $roomId): Collection
    {
        return DoctorSchedule::query()
            ->when($date !== null, fn ($q) => $q->where('schedule_date', $date))
            ->when($start !== null, fn ($q) => $q->where('schedule_date', '>=', $start))
            ->when($end !== null, fn ($q) => $q->where('schedule_date', '<=', $end))
            ->when($doctorId !== null, fn ($q) => $q->where('doctor_id', $doctorId))
            ->when($roomId !== null, fn ($q) => $q->where('room_id', $roomId))
            ->orderByDesc('schedule_date')->orderBy('shift')->get();
    }

    public function existsByDoctor_DoctorIdAndScheduleDateAndShift(int $id, string $date, string $shift): bool
    {
        return $this->existsByDoctor_DoctorIdAndScheduleDateAndShiftAndDoctorScheduleIdNot($id, $date, $shift, null);
    }

    public function existsByDoctor_DoctorIdAndScheduleDateAndShiftAndDoctorScheduleIdNot(int $id, string $date, string $shift, ?int $except): bool
    {
        return DoctorSchedule::where('doctor_id', $id)->where('schedule_date', $date)->where('shift', $shift)
            ->when($except !== null, fn ($q) => $q->where('doctor_schedule_id', '<>', $except))->exists();
    }

    public function existsByRoom_RoomIdAndScheduleDateAndShift(int $id, string $date, string $shift): bool
    {
        return $this->existsByRoom_RoomIdAndScheduleDateAndShiftAndDoctorScheduleIdNot($id, $date, $shift, null);
    }

    public function existsByRoom_RoomIdAndScheduleDateAndShiftAndDoctorScheduleIdNot(int $id, string $date, string $shift, ?int $except): bool
    {
        return DoctorSchedule::where('room_id', $id)->where('schedule_date', $date)->where('shift', $shift)
            ->when($except !== null, fn ($q) => $q->where('doctor_schedule_id', '<>', $except))->exists();
    }

    public function saveAndFlush(DoctorSchedule $schedule): DoctorSchedule
    {
        if (! $schedule->exists) {
            $schedule->save();
        } elseif ($schedule->isDirty()) {
            $dispatcher = $schedule::getEventDispatcher();
            $dispatcher?->until('eloquent.saving: '.$schedule::class, $schedule);
            $dispatcher?->until('eloquent.updating: '.$schedule::class, $schedule);
            $version = OptimisticLock::update($schedule->getTable(), $schedule->getKeyName(), $schedule->getKey(),
                (int) $schedule->getRawOriginal('version_number'), $schedule->getDirty(), $schedule->getConnection());
            $schedule->version_number = $version;
            $schedule->syncChanges();
            $schedule->syncOriginal();
            $dispatcher?->dispatch('eloquent.updated: '.$schedule::class, $schedule);
            $dispatcher?->dispatch('eloquent.saved: '.$schedule::class, $schedule);
        }

        return $schedule;
    }
}
