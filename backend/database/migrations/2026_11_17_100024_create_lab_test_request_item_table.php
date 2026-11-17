<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_test_request_item', function (Blueprint $table) {
            $table->unsignedBigInteger('lab_test_request_id');
            $table->unsignedBigInteger('lab_test_id');
            $table->decimal('snapshot_price', 15, 2);

            $table->primary(['lab_test_request_id', 'lab_test_id']);
            $table->foreign('lab_test_request_id', 'fk_labitem_request')->references('lab_test_request_id')->on('lab_test_request');
            $table->foreign('lab_test_id', 'fk_labitem_labtest')->references('lab_test_id')->on('lab_test');
        });

        DB::statement('ALTER TABLE lab_test_request_item ADD CONSTRAINT chk_labitem_price CHECK (snapshot_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_test_request_item');
    }
};
