<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor', function (Blueprint $table) {
            $table->id('doctor_id');
            $table->unsignedBigInteger('account_id')->unique();
            $table->string('full_name', 200);
            $table->string('qualification', 200)->nullable();
            $table->string('specialization', 200)->nullable();
            $table->string('license_num', 100)->unique();
            $table->string('identity_num', 50)->nullable()->unique();
            $table->string('gender', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('hire_date')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->string('experience', 500)->nullable();
            $table->unsignedBigInteger('specialty_id')->nullable();

            $table->foreign('account_id', 'fk_doctor_account')->references('account_id')->on('account');
            $table->foreign('specialty_id', 'fk_doctor_specialty')->references('specialty_id')->on('specialty');
            $table->index('specialty_id', 'idx_doctor_specialty');
        });

        DB::statement('ALTER TABLE doctor ADD CONSTRAINT chk_doctor_active CHECK (is_active IN (0, 1))');
        DB::statement("ALTER TABLE doctor ADD CONSTRAINT chk_doctor_gender CHECK (gender IN ('MALE', 'FEMALE', 'OTHER'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor');
    }
};
