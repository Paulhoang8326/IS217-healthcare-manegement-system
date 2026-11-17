<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment', function (Blueprint $table) {
            $table->id('appointment_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('doctor_schedule_id');
            $table->integer('queue_num')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->text('initial_symptoms')->nullable();
            $table->string('visit_reason', 500)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('checked_in_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->dateTime('paid_at', 6)->nullable();
            $table->bigInteger('version_number')->default(0);
            $table->string('appointment_code', 30);
            $table->bigInteger('sepay_transaction_id')->nullable();
            $table->string('payment_reference_code', 200)->nullable();
            $table->string('payment_content', 1000)->nullable();
            $table->unsignedBigInteger('fee_id')->nullable();
            $table->string('fee_name_snapshot', 200)->nullable();
            $table->decimal('fee_price_snapshot', 15, 2)->nullable();
            $table->dateTime('payment_expires_at', 6)->nullable();

            $table->foreign('patient_id', 'fk_appt_patient')->references('patient_id')->on('patient');
            $table->foreign('doctor_schedule_id', 'fk_appt_schedule')->references('doctor_schedule_id')->on('doctor_schedule');
            $table->foreign('fee_id', 'fk_appt_fee')->references('fee_id')->on('consultation_fee');
            $table->unique(['doctor_schedule_id', 'queue_num'], 'uq_appt_schedule_queue');
            $table->unique('appointment_code', 'uq_appt_code');
            $table->unique('sepay_transaction_id', 'uq_appt_sepay_tx');
            $table->index(['patient_id', 'status'], 'idx_appt_patient_status');
            $table->index(['status', 'payment_expires_at'], 'idx_appt_payment_expiry');
        });

        DB::statement("ALTER TABLE appointment ADD CONSTRAINT chk_appt_status CHECK (status IN ('PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'PAYMENT_EXPIRED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment');
    }
};
