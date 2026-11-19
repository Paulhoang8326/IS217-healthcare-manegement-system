<?php

namespace App\Modules\Scheduling\Repositories;

use App\Models\Doctor;
use Illuminate\Database\Eloquent\Collection;

class DoctorRepository
{
    public function findById(int $id): ?Doctor
    {
        return Doctor::find($id);
    }

    public function findByAccount_AccountId(int $id): ?Doctor
    {
        return Doctor::where('account_id', $id)->first();
    }

    /** @return Collection<int, Doctor> */
    public function findActiveByFullName(string $fullName): Collection
    {
        return Doctor::where('is_active', 1)->whereRaw('UPPER(TRIM(full_name)) = UPPER(TRIM(?))', [$fullName])->get();
    }

    public function save(Doctor $doctor): Doctor
    {
        $doctor->save();
        if ($doctor->account?->isDirty()) {
            $doctor->account->save();
        }

        return $doctor;
    }
}
