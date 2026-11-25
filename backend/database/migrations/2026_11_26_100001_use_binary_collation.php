<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'account',
        'account_permission',
        'accountant',
        'administrator',
        'admission_record',
        'admission_request',
        'appointment',
        'bed',
        'branch',
        'consultation_fee',
        'doctor',
        'doctor_schedule',
        'lab_test',
        'lab_test_request',
        'lab_test_request_item',
        'lab_test_result',
        'medical_record',
        'medical_service',
        'medical_service_request',
        'medical_service_request_item',
        'medical_service_result',
        'medicine',
        'medicine_lot',
        'patient',
        'patient_insurance',
        'payment_record',
        'payment_transaction',
        'permission',
        'pharmacist',
        'prescription',
        'prescription_detail',
        'receptionist',
        'role',
        'role_permission',
        'room',
        'room_type',
        'specialty',
        'technician',
    ];

    /** Oracle compares with NLS_COMP = BINARY and NLS_SORT = BINARY: case, accents and trailing spaces all count. */
    public function up(): void
    {
        $this->convert('utf8mb4_0900_bin');
    }

    /** Fails if rows that only differ by case, accent or trailing space already exist in a unique column. */
    public function down(): void
    {
        $this->convert('utf8mb4_unicode_ci');
    }

    private function convert(string $collation): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE $table CONVERT TO CHARACTER SET utf8mb4 COLLATE $collation");
        }
    }
};
