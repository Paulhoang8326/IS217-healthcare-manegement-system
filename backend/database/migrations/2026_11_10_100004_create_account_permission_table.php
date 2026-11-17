<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_permission', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('permission_id');

            $table->primary(['account_id', 'permission_id']);
            $table->foreign('account_id', 'fk_ap_account')->references('account_id')->on('account');
            $table->foreign('permission_id', 'fk_ap_permission')->references('permission_id')->on('permission');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_permission');
    }
};
