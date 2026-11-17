<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_fee', function (Blueprint $table) {
            $table->id('fee_id');
            $table->string('fee_code', 50)->unique();
            $table->string('fee_name', 200);
            $table->string('specialty', 100);
            $table->decimal('price', 15, 2);
            $table->tinyInteger('is_active')->default(1);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unsignedBigInteger('specialty_id')->nullable();

            $table->foreign('specialty_id', 'fk_consultation_fee_specialty')->references('specialty_id')->on('specialty');
        });

        DB::statement('ALTER TABLE consultation_fee ADD CONSTRAINT chk_consultation_fee_price CHECK (price >= 0)');
        DB::statement('ALTER TABLE consultation_fee ADD CONSTRAINT chk_consultation_fee_active CHECK (is_active IN (0, 1))');
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_fee');
    }
};
