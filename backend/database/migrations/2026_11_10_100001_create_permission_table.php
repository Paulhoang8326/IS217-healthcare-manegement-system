<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission', function (Blueprint $table) {
            $table->id('permission_id');
            $table->string('permission_name', 100)->unique();
            $table->string('detail', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission');
    }
};
