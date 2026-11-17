<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_record', function (Blueprint $table) {
            $table->id('payment_record_id');
            $table->unsignedBigInteger('med_record_id')->nullable()->unique();
            $table->string('request_code', 100)->unique();
            $table->decimal('total_price', 15, 2);
            $table->string('payment_status', 20)->default('UNPAID');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('paid_at', 6)->nullable();
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->decimal('received_amount', 15, 2)->default(0);
            $table->unsignedBigInteger('prescription_id')->nullable()->unique();

            $table->foreign('med_record_id', 'fk_payrecord_medrecord')->references('med_record_id')->on('medical_record');
            $table->foreign('appointment_id', 'fk_payrecord_appointment')->references('appointment_id')->on('appointment');
            $table->unique('appointment_id', 'uq_payrecord_appointment');
            $table->foreign('prescription_id', 'fk_payment_record_prescription')->references('prescription_id')->on('prescription');
        });

        DB::statement("ALTER TABLE payment_record ADD CONSTRAINT chk_payrecord_status CHECK (payment_status IN ('UNPAID', 'PARTIAL', 'PAID'))");
        DB::statement('ALTER TABLE payment_record ADD CONSTRAINT chk_payrecord_price CHECK (total_price >= 0)');
        DB::statement('ALTER TABLE payment_record ADD CONSTRAINT chk_payrecord_received CHECK (received_amount >= 0)');
        DB::statement('ALTER TABLE payment_record ADD CONSTRAINT chk_payrecord_owner CHECK ((med_record_id IS NOT NULL AND appointment_id IS NULL AND prescription_id IS NULL) OR (med_record_id IS NULL AND appointment_id IS NOT NULL AND prescription_id IS NULL) OR (med_record_id IS NULL AND appointment_id IS NULL AND prescription_id IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_record');
    }
};
