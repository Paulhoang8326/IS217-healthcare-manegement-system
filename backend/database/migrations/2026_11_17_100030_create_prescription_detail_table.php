<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_detail', function (Blueprint $table) {
            $table->id('prescription_detail_id');
            $table->unsignedBigInteger('prescription_id');
            $table->unsignedBigInteger('medicine_id');
            $table->string('dosage', 100);
            $table->string('frequency', 100);
            $table->string('duration', 100);
            $table->integer('quantity');
            $table->string('instruction', 500)->nullable();

            $table->foreign('prescription_id', 'fk_prescription_detail_prescription')->references('prescription_id')->on('prescription');
            $table->foreign('medicine_id', 'fk_prescription_detail_medicine')->references('medicine_id')->on('medicine');
        });

        DB::statement('ALTER TABLE prescription_detail ADD CONSTRAINT chk_prescription_detail_quantity CHECK (quantity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_detail');
    }
};
