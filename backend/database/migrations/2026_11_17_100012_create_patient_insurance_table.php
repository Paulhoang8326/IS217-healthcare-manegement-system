<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_insurance', function (Blueprint $table) {
            $table->id('patient_insurance_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('insurance_num', 100)->unique();
            $table->string('status', 20)->default('ACTIVE');
            $table->date('expiry_date');
            $table->decimal('coverage_percent', 5, 2);

            $table->foreign('patient_id', 'fk_insurance_patient')->references('patient_id')->on('patient');
        });

        DB::statement("ALTER TABLE patient_insurance ADD CONSTRAINT chk_insurance_status CHECK (status IN ('ACTIVE', 'EXPIRED', 'SUSPENDED'))");
        DB::statement('ALTER TABLE patient_insurance ADD CONSTRAINT chk_coverage_percent CHECK (coverage_percent BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_insurance');
    }
};
