<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Accountant;
use App\Models\Administrator;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Pharmacist;
use App\Models\Receptionist;
use App\Models\Role;
use App\Models\Technician;
use Illuminate\Database\Seeder;

class DemoAccountSeeder extends Seeder
{
    /**
     * One active demo account per role, each with its profile row. All share one
     * bcrypt hash; the plain password is kept outside the repository.
     */
    private const PASSWORD_HASH = '$2y$10$p4ob1hR8B6.B4bNeL4GRs.9ocQ4RpGU4Y05dZ7RvxUa60goQe.2Wu';

    public function run(): void
    {
        $admin = $this->account('admin.demo@healthcare.test', 'ADMIN');
        Administrator::create([
            'account_id' => $admin->account_id,
            'full_name' => 'Demo Administrator',
            'identity_num' => '000000000001',
            'gender' => 'MALE',
            'date_of_birth' => '1985-01-01',
            'hire_date' => '2020-01-01',
        ]);

        $doctor = $this->account('doctor.demo@healthcare.test', 'DOCTOR');
        Doctor::create([
            'account_id' => $doctor->account_id,
            'full_name' => 'Demo Doctor',
            'qualification' => 'MD',
            'license_num' => 'DEMO-DOC-0001',
            'identity_num' => '000000000002',
            'gender' => 'FEMALE',
            'date_of_birth' => '1980-05-10',
            'hire_date' => '2018-03-01',
            'experience' => '10 years',
        ]);

        $patient = $this->account('patient.demo@healthcare.test', 'PATIENT');
        Patient::create([
            'account_id' => $patient->account_id,
            'full_name' => 'Demo Patient',
            'gender' => 'MALE',
            'date_of_birth' => '1995-09-20',
            'identity_num' => '000000000003',
        ]);

        $receptionist = $this->account('receptionist.demo@healthcare.test', 'RECEPTIONIST');
        Receptionist::create([
            'account_id' => $receptionist->account_id,
            'full_name' => 'Demo Receptionist',
            'identity_num' => '000000000004',
            'gender' => 'FEMALE',
            'hire_date' => '2021-06-01',
            'shift' => 'Morning',
        ]);

        $technician = $this->account('technician.demo@healthcare.test', 'TECHNICIAN');
        Technician::create([
            'account_id' => $technician->account_id,
            'full_name' => 'Demo Technician',
            'specialty_area' => 'Laboratory',
            'license_num' => 'DEMO-TEC-0001',
            'identity_num' => '000000000005',
            'gender' => 'MALE',
            'hire_date' => '2022-01-10',
            'experience' => 3,
        ]);

        $pharmacist = $this->account('pharmacist.demo@healthcare.test', 'PHARMACIST');
        Pharmacist::create([
            'account_id' => $pharmacist->account_id,
            'full_name' => 'Demo Pharmacist',
            'license_num' => 'DEMO-PHA-0001',
            'identity_num' => '000000000006',
            'gender' => 'FEMALE',
            'hire_date' => '2019-08-15',
            'experience' => 6,
        ]);

        $accountant = $this->account('accountant.demo@healthcare.test', 'ACCOUNTANT');
        Accountant::create([
            'account_id' => $accountant->account_id,
            'full_name' => 'Demo Accountant',
            'identity_num' => '000000000007',
            'gender' => 'MALE',
            'hire_date' => '2020-11-01',
            'experience' => 5,
        ]);
    }

    private function account(string $email, string $roleName): Account
    {
        return Account::create([
            'email' => $email,
            'password_hash' => self::PASSWORD_HASH,
            'role_id' => Role::where('role_name', $roleName)->value('role_id'),
            'is_active' => 1,
        ]);
    }
}
