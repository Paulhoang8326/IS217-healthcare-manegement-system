<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_test_request', function (Blueprint $table) {
            $table->id('lab_test_request_id');
            $table->unsignedBigInteger('med_record_id');
            $table->string('request_code', 100)->unique();
            // QUIRK-NEW: V17 changed the CHECK below to NOT_COLLECTED/SAMPLE_COLLECTED/RESULT_AVAILABLE but kept DEFAULT 'PENDING', so an insert without status violates the CHECK.
            $table->string('status', 20)->default('PENDING');
            $table->string('payment_status', 20)->default('UNPAID');
            $table->decimal('total_price', 15, 2)->nullable()->default(0);
            $table->string('note', 500)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('paid_at', 6)->nullable();

            $table->foreign('med_record_id', 'fk_labreq_medrecord')->references('med_record_id')->on('medical_record');
        });

        DB::statement("ALTER TABLE lab_test_request ADD CONSTRAINT chk_labreq_status CHECK (status IN ('NOT_COLLECTED', 'SAMPLE_COLLECTED', 'RESULT_AVAILABLE'))");
        DB::statement("ALTER TABLE lab_test_request ADD CONSTRAINT chk_labreq_paystatus CHECK (payment_status IN ('UNPAID', 'PAID'))");
        DB::statement('ALTER TABLE lab_test_request ADD CONSTRAINT chk_labreq_price CHECK (total_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_test_request');
    }
};
