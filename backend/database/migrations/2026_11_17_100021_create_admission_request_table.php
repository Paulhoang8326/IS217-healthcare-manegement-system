<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_request', function (Blueprint $table) {
            $table->id('admission_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('med_record_id')->unique();
            $table->unsignedBigInteger('bed_id');
            $table->date('admission_date');
            $table->date('discharge_date')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->decimal('total_price', 15, 2)->nullable()->default(0);

            $table->foreign('patient_id', 'fk_admission_patient')->references('patient_id')->on('patient');
            $table->foreign('med_record_id', 'fk_admission_medrecord')->references('med_record_id')->on('medical_record');
            $table->foreign('bed_id', 'fk_admission_bed')->references('bed_id')->on('bed');
        });

        DB::statement("ALTER TABLE admission_request ADD CONSTRAINT chk_admission_status CHECK (status IN ('PENDING', 'ADMITTED', 'DISCHARGED', 'CANCELLED'))");
        DB::statement('ALTER TABLE admission_request ADD CONSTRAINT chk_admission_price CHECK (total_price >= 0)');
        DB::statement('ALTER TABLE admission_request ADD CONSTRAINT chk_discharge_after CHECK (discharge_date IS NULL OR discharge_date >= admission_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_request');
    }
};
