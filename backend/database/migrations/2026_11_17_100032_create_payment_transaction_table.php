<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transaction', function (Blueprint $table) {
            $table->id('transaction_id');
            $table->unsignedBigInteger('payment_record_id');
            $table->string('transfer_type', 50)->nullable();
            $table->string('gateway', 50);
            $table->string('account_number', 100)->nullable();
            $table->string('sepay_transaction_id', 200)->nullable();
            $table->decimal('transfer_amount', 15, 2);
            $table->dateTime('transaction_date', 6)->useCurrent();
            $table->string('reference_code', 200)->nullable();
            $table->string('content', 500)->nullable();
            $table->string('description', 1000)->nullable();
            $table->string('process_status', 20)->default('PENDING');
            $table->text('raw_data')->nullable();
            $table->string('receipt_number', 100)->nullable();
            $table->unsignedBigInteger('confirmed_by_account_id')->nullable();

            $table->foreign('payment_record_id', 'fk_paytxn_record')->references('payment_record_id')->on('payment_record');
            $table->unique('sepay_transaction_id', 'uq_paytxn_sepay_tx');
            $table->foreign('confirmed_by_account_id', 'fk_paytxn_confirmed_by')->references('account_id')->on('account');
        });

        DB::statement('ALTER TABLE payment_transaction ADD CONSTRAINT chk_paytxn_amount CHECK (transfer_amount > 0)');
        DB::statement("ALTER TABLE payment_transaction ADD CONSTRAINT chk_paytxn_status CHECK (process_status IN ('PENDING', 'SUCCESS', 'FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transaction');
    }
};
