<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_service_result', function (Blueprint $table) {
            $table->id('med_service_result_id');
            $table->unsignedBigInteger('med_ser_req_id')->unique();
            $table->text('result_data')->nullable();
            $table->dateTime('created_at', 6)->useCurrent();

            $table->foreign('med_ser_req_id', 'fk_serresult_request')->references('med_ser_req_id')->on('medical_service_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_service_result');
    }
};
