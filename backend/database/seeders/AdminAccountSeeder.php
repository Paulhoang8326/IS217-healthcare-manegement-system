<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminAccountSeeder extends Seeder
{
    /**
     * Admin account inserted by V2__insert_master_data.sql.
     */
    public function run(): void
    {
        DB::table('account')->insert([
            'email' => 'admin@gmail.com',
            // QUIRK-NEW: V2 stores the plain text 'admin123' as password_hash, so BCrypt login with this account always fails.
            'password_hash' => 'admin123',
            'role_id' => DB::table('role')->where('role_name', 'ADMIN')->value('role_id'),
            'is_active' => 1,
        ]);
    }
}
