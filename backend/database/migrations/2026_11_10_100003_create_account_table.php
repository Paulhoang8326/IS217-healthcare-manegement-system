<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account', function (Blueprint $table) {
            $table->id('account_id');
            $table->string('email', 255)->unique();
            $table->string('password_hash', 255)->nullable();
            $table->unsignedBigInteger('role_id');
            $table->tinyInteger('is_active')->default(1);
            $table->string('google_id', 255)->nullable();

            $table->unique('google_id', 'uq_account_google_id');
            $table->foreign('role_id', 'fk_account_role')->references('role_id')->on('role');
        });

        DB::statement('ALTER TABLE account ADD CONSTRAINT chk_account_active CHECK (is_active IN (0, 1))');
    }

    public function down(): void
    {
        Schema::dropIfExists('account');
    }
};
