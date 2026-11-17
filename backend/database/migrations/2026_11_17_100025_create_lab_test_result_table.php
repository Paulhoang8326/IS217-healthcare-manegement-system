<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_test_result', function (Blueprint $table) {
            $table->id('lab_test_result_id');
            $table->unsignedBigInteger('lab_test_request_id')->unique();
            $table->text('result_data')->nullable();
            $table->dateTime('result_date');

            $table->foreign('lab_test_request_id', 'fk_labresult_request')->references('lab_test_request_id')->on('lab_test_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_test_result');
    }
};
