<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacist', function (Blueprint $table) {
            $table->id('pharmacist_id');
            $table->unsignedBigInteger('account_id')->unique();
            $table->string('full_name', 200);
            $table->string('qualification', 200)->nullable();
            $table->string('license_num', 100)->unique();
            $table->string('identity_num', 50)->nullable()->unique();
            $table->string('gender', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('hire_date')->nullable();
            $table->smallInteger('experience')->nullable();
            $table->tinyInteger('is_active')->default(1);

            $table->foreign('account_id', 'fk_pharmacist_account')->references('account_id')->on('account');
        });

        DB::statement('ALTER TABLE pharmacist ADD CONSTRAINT chk_pharmacist_active CHECK (is_active IN (0, 1))');
        DB::statement("ALTER TABLE pharmacist ADD CONSTRAINT chk_pharmacist_gender CHECK (gender IN ('MALE', 'FEMALE', 'OTHER'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacist');
    }
};
