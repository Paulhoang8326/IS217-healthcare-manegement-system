<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrator', function (Blueprint $table) {
            $table->id('administrator_id');
            $table->unsignedBigInteger('account_id')->unique();
            $table->string('full_name', 200);
            $table->string('identity_num', 50)->nullable()->unique();
            $table->string('gender', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('hire_date')->nullable();
            $table->tinyInteger('is_active')->default(1);

            $table->foreign('account_id', 'fk_admin_account')->references('account_id')->on('account');
        });

        DB::statement('ALTER TABLE administrator ADD CONSTRAINT chk_admin_active CHECK (is_active IN (0, 1))');
        DB::statement("ALTER TABLE administrator ADD CONSTRAINT chk_admin_gender CHECK (gender IN ('MALE', 'FEMALE', 'OTHER'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('administrator');
    }
};
