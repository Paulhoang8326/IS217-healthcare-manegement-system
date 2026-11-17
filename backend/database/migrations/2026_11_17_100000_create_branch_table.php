<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch', function (Blueprint $table) {
            $table->id('branch_id');
            $table->string('branch_name', 200)->unique();
            $table->string('branch_address', 500);
            $table->string('branch_hotline', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch');
    }
};
