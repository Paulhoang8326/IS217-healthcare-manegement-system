<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_service_request_item', function (Blueprint $table) {
            $table->unsignedBigInteger('med_ser_req_id');
            $table->unsignedBigInteger('med_service_id');
            $table->decimal('snapshot_price', 15, 2);

            $table->primary(['med_ser_req_id', 'med_service_id']);
            $table->foreign('med_ser_req_id', 'fk_seritem_request')->references('med_ser_req_id')->on('medical_service_request');
            $table->foreign('med_service_id', 'fk_seritem_service')->references('med_service_id')->on('medical_service');
        });

        DB::statement('ALTER TABLE medical_service_request_item ADD CONSTRAINT chk_seritem_price CHECK (snapshot_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_service_request_item');
    }
};
