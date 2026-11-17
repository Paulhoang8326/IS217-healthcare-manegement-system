<?php

namespace App\Modules\Appointment\Repositories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

class AppointmentRepository extends VersionedRepository
{
    public function findById(int $id): ?Appointment
    {
        return Appointment::find($id);
    }

    public function findByIdForUpdate(int $id): ?Appointment
    {
        return Appointment::whereKey($id)->lockForUpdate()->first();
    }

    public function findByAppointmentCodeForUpdate(string $code): ?Appointment
    {
        return Appointment::where('appointment_code', $code)->lockForUpdate()->first();
    }

    public function existsByAppointmentCode(string $code): bool
    {
        return Appointment::where('appointment_code', $code)->exists();
    }

    public function findFirstByPatient_PatientIdAndStatusIn(int $id, array $statuses): ?Appointment
    {
        return Appointment::where('patient_id', $id)->whereIn('status', $statuses)->first();
    }

    /** @return Collection<int, Appointment> */
    public function findAllByFilters(?int $patientId, ?int $doctorId, ?int $scheduleId, ?string $status): Collection
    {
        return Appointment::query()
            ->when($patientId !== null, fn ($q) => $q->where('patient_id', $patientId))
            ->when($doctorId !== null, fn ($q) => $q->whereHas('doctorSchedule', fn ($s) => $s->where('doctor_id', $doctorId)))
            ->when($scheduleId !== null, fn ($q) => $q->where('doctor_schedule_id', $scheduleId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))->get();
    }

    /** @return Collection<int, Appointment> */
    public function findExpiredPaymentReservationsForUpdate(AppointmentStatus $status, DateTimeInterface $now): Collection
    {
        return Appointment::where('status', $status)->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', $now)->lockForUpdate()->get();
    }

    public function save(Appointment $appointment): Appointment
    {
        return $this->saveVersioned($appointment);
    }

    public function saveAndFlush(Appointment $appointment): Appointment
    {
        return $this->save($appointment);
    }
}
