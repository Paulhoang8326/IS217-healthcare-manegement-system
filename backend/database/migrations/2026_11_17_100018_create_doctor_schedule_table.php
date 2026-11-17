<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_schedule', function (Blueprint $table) {
            $table->id('doctor_schedule_id');
            $table->unsignedBigInteger('doctor_id');
            $table->unsignedBigInteger('room_id');
            $table->date('schedule_date');
            $table->integer('max_capacity');
            $table->integer('current_booking_count')->default(0);
            $table->integer('last_queue_number')->default(0);
            $table->string('note', 500)->nullable();
            $table->string('shift', 20);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->nullable();
            $table->bigInteger('version_number')->default(0);

            $table->foreign('doctor_id', 'fk_schedule_doctor')->references('doctor_id')->on('doctor');
            $table->foreign('room_id', 'fk_schedule_room')->references('room_id')->on('room');
            $table->unique(['doctor_id', 'schedule_date', 'shift'], 'uq_schedule_doctor_shift');
            $table->unique(['room_id', 'schedule_date', 'shift'], 'uq_schedule_room_shift');
            $table->index('schedule_date', 'idx_doctor_schedule_date');
        });

        DB::statement('ALTER TABLE doctor_schedule ADD CONSTRAINT chk_schedule_capacity CHECK (max_capacity > 0)');
        DB::statement('ALTER TABLE doctor_schedule ADD CONSTRAINT chk_schedule_booking CHECK (current_booking_count >= 0)');
        DB::statement("ALTER TABLE doctor_schedule ADD CONSTRAINT chk_schedule_shift CHECK (shift IN ('MORNING', 'AFTERNOON'))");
        DB::statement('ALTER TABLE doctor_schedule ADD CONSTRAINT chk_schedule_queue CHECK (last_queue_number >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedule');
    }
};
