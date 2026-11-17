<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Roles inserted by V2__insert_master_data.sql, in the same order.
     */
    public function run(): void
    {
        DB::table('role')->insert([
            ['role_name' => 'ADMIN', 'description' => 'Quản trị viên hệ thống'],
            ['role_name' => 'DOCTOR', 'description' => 'Bác sĩ'],
            ['role_name' => 'PATIENT', 'description' => 'Bệnh nhân'],
            ['role_name' => 'RECEPTIONIST', 'description' => 'Lễ tân'],
            ['role_name' => 'TECHNICIAN', 'description' => 'Kỹ thuật viên'],
            ['role_name' => 'PHARMACIST', 'description' => 'Dược sĩ'],
            ['role_name' => 'ACCOUNTANT', 'description' => 'Kế toán'],
        ]);
    }
}
