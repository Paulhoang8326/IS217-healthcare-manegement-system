<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_test', function (Blueprint $table) {
            $table->id('lab_test_id');
            $table->string('lab_test_name', 200)->unique();
            $table->decimal('price', 15, 2);
            $table->tinyInteger('is_active')->default(1);
        });

        DB::statement('ALTER TABLE lab_test ADD CONSTRAINT chk_labtest_price CHECK (price >= 0)');
        DB::statement('ALTER TABLE lab_test ADD CONSTRAINT chk_labtest_active CHECK (is_active IN (0, 1))');
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_test');
    }
};
