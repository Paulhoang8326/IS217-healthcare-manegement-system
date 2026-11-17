<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialty', function (Blueprint $table) {
            $table->id('specialty_id');
            $table->string('specialty_code', 50)->unique();
            $table->string('specialty_name', 200)->unique();
            $table->tinyInteger('is_active')->default(1);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->nullable();
        });

        DB::statement('ALTER TABLE specialty ADD CONSTRAINT chk_specialty_active CHECK (is_active IN (0, 1))');
    }

    public function down(): void
    {
        Schema::dropIfExists('specialty');
    }
};
