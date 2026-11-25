<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Oracle CLOB columns; MySQL TEXT stops at 65535 bytes. */
    private const CLOBS = [
        'appointment' => ['initial_symptoms'],
        'lab_test_result' => ['result_data'],
        'medical_record' => ['clinical_conclusion', 'clinical_notes', 'treatment_plan'],
        'medical_service_result' => ['result_data'],
        'patient' => ['medical_history'],
        'payment_transaction' => ['raw_data'],
        'prescription' => ['note'],
    ];

    /** Oracle NUMBER(n) columns whose MySQL type is wider and that have no tighter CHECK: [table, column, max]. */
    private const PRECISIONS = [
        'chk_technician_experience_prec' => ['technician', 'experience', 999],
        'chk_pharmacist_experience_prec' => ['pharmacist', 'experience', 999],
        'chk_accountant_experience_prec' => ['accountant', 'experience', 999],
        'chk_schedule_capacity_prec' => ['doctor_schedule', 'max_capacity', 99999],
        'chk_schedule_booking_prec' => ['doctor_schedule', 'current_booking_count', 99999],
        'chk_schedule_queue_prec' => ['doctor_schedule', 'last_queue_number', 99999],
        'chk_appt_queue_prec' => ['appointment', 'queue_num', 99999],
        'chk_admrecord_heart_rate_prec' => ['admission_record', 'heart_rate', 99999],
        'chk_medicine_active_prec' => ['medicine', 'is_active', 9],
        'chk_medicine_lot_active_prec' => ['medicine_lot', 'is_active', 9],
        'chk_prescription_active_prec' => ['prescription', 'is_active', 9],
    ];

    public function up(): void
    {
        foreach (self::CLOBS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->longText($column)->nullable()->change();
                }
            });
        }

        foreach (self::PRECISIONS as $name => [$table, $column, $max]) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($column BETWEEN -$max AND $max)");
        }
    }

    public function down(): void
    {
        foreach (self::PRECISIONS as $name => [$table]) {
            DB::statement("ALTER TABLE $table DROP CHECK $name");
        }

        foreach (self::CLOBS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->text($column)->nullable()->change();
                }
            });
        }
    }
};
