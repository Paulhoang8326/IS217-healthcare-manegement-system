<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_service', function (Blueprint $table) {
            $table->id('med_service_id');
            $table->string('medical_service_name', 200)->unique();
            $table->decimal('price', 15, 2);
            $table->tinyInteger('is_active')->default(1);
        });

        DB::statement('ALTER TABLE medical_service ADD CONSTRAINT chk_medservice_price CHECK (price >= 0)');
        DB::statement('ALTER TABLE medical_service ADD CONSTRAINT chk_medservice_active CHECK (is_active IN (0, 1))');
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_service');
    }
};
