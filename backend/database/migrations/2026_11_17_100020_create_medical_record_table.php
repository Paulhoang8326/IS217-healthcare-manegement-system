<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_record', function (Blueprint $table) {
            $table->id('med_record_id');
            $table->unsignedBigInteger('appointment_id')->unique();
            $table->date('record_date')->nullable();
            $table->string('initial_diagnosis', 1000)->nullable();
            $table->text('clinical_conclusion')->nullable();
            $table->decimal('total_price', 15, 2)->nullable()->default(0);
            $table->unsignedBigInteger('doctor_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('conclusion_type', 30)->nullable();
            $table->text('clinical_notes')->nullable();
            $table->text('treatment_plan')->nullable();
            $table->string('status', 20);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('locked_at', 6)->nullable();
            $table->bigInteger('version_number')->default(0);

            $table->foreign('appointment_id', 'fk_medrecord_appointment')->references('appointment_id')->on('appointment');
            $table->foreign('doctor_id', 'fk_medrecord_doctor')->references('doctor_id')->on('doctor');
            $table->foreign('patient_id', 'fk_medrecord_patient')->references('patient_id')->on('patient');
            $table->index(['patient_id', 'status'], 'idx_medrecord_patient_status');
            $table->index(['doctor_id', 'created_at'], 'idx_medrecord_doctor_date');
        });

        DB::statement('ALTER TABLE medical_record ADD CONSTRAINT chk_medrecord_price CHECK (total_price >= 0)');
        DB::statement("ALTER TABLE medical_record ADD CONSTRAINT chk_medrecord_status CHECK (status IN ('DRAFT', 'IN_PROGRESS', 'COMPLETED', 'LOCKED'))");
        DB::statement("ALTER TABLE medical_record ADD CONSTRAINT chk_medrecord_conclusion_type CHECK (conclusion_type IN ('COMPLETED', 'ADMISSION_REQUIRED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record');
    }
};
