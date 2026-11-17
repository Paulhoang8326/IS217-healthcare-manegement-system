<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_lot', function (Blueprint $table) {
            $table->id('medicine_lot_id');
            $table->unsignedBigInteger('medicine_id');
            $table->string('lot_number', 100);
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date');
            $table->integer('quantity')->default(0);
            $table->decimal('import_price', 15, 2)->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->date('import_date')->useCurrent();

            $table->foreign('medicine_id', 'fk_medicine_lot_medicine')->references('medicine_id')->on('medicine');
            $table->unique(['medicine_id', 'lot_number'], 'uk_medicine_lot_medicine_lot_number');
        });

        DB::statement('ALTER TABLE medicine_lot ADD CONSTRAINT chk_medicine_lot_quantity CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE medicine_lot ADD CONSTRAINT chk_medicine_lot_import_price CHECK (import_price IS NULL OR import_price >= 0)');
        DB::statement('ALTER TABLE medicine_lot ADD CONSTRAINT chk_medicine_lot_expiry_date CHECK (manufacturing_date IS NULL OR expiry_date > manufacturing_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_lot');
    }
};
