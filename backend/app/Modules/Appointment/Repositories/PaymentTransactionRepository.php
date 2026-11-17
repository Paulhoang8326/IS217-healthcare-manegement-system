<?php

namespace App\Modules\Appointment\Repositories;

use App\Models\PaymentTransaction;

class PaymentTransactionRepository
{
    public function findBySepayTransactionId(string $id): ?PaymentTransaction
    {
        return PaymentTransaction::where('sepay_transaction_id', $id)->first();
    }

    public function save(PaymentTransaction $transaction): PaymentTransaction
    {
        $transaction->save();

        return $transaction;
    }
}
