<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room', function (Blueprint $table) {
            $table->id('room_id');
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('room_code', 50)->unique();
            $table->string('position', 200)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('specialty_id')->nullable();
            $table->integer('floor')->default(1);

            $table->foreign('room_type_id', 'fk_room_type')->references('room_type_id')->on('room_type');
            $table->foreign('branch_id', 'fk_room_branch')->references('branch_id')->on('branch');
            $table->foreign('specialty_id', 'fk_room_specialty')->references('specialty_id')->on('specialty');
            $table->index('specialty_id', 'idx_room_specialty');
            $table->index('floor', 'idx_room_floor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room');
    }
};
