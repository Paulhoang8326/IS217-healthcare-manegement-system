<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_record', function (Blueprint $table) {
            $table->id('admission_record_id');
            $table->unsignedBigInteger('admission_id');
            $table->string('blood_pressure', 20)->nullable();
            $table->integer('heart_rate')->nullable();
            $table->decimal('temperature', 5, 2)->nullable();
            $table->dateTime('record_date');

            $table->foreign('admission_id', 'fk_admrecord_admission')->references('admission_id')->on('admission_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_record');
    }
};
