<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription', function (Blueprint $table) {
            $table->id('prescription_id');
            $table->unsignedBigInteger('med_record_id');
            $table->text('note')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->nullable();

            $table->foreign('med_record_id', 'fk_prescription_medical_record')->references('med_record_id')->on('medical_record');
            $table->unique('med_record_id', 'uk_prescription_medical_record');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription');
    }
};
