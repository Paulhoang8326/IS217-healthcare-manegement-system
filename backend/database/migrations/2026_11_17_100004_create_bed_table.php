<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bed', function (Blueprint $table) {
            $table->id('bed_id');
            $table->unsignedBigInteger('room_id');
            $table->decimal('price', 15, 2);
            $table->string('status', 20)->default('AVAILABLE');

            $table->foreign('room_id', 'fk_bed_room')->references('room_id')->on('room');
        });

        DB::statement("ALTER TABLE bed ADD CONSTRAINT chk_bed_status CHECK (status IN ('AVAILABLE', 'OCCUPIED', 'MAINTENANCE'))");
        DB::statement('ALTER TABLE bed ADD CONSTRAINT chk_bed_price CHECK (price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bed');
    }
};
