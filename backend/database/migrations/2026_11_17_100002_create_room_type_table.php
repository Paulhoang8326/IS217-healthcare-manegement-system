<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_type', function (Blueprint $table) {
            $table->id('room_type_id');
            $table->string('room_type_name', 100)->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type');
    }
};
