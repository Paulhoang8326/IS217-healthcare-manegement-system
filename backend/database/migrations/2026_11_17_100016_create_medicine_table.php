<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine', function (Blueprint $table) {
            $table->id('medicine_id');
            $table->string('medicine_name', 200);
            $table->string('active_ingredient', 200)->nullable();
            $table->string('unit', 50);
            $table->string('description', 500)->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->decimal('selling_price', 15, 2)->default(0);

            $table->unique('medicine_name', 'uk_medicine_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine');
    }
};
