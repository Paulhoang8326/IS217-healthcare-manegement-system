<?php

namespace App\Models;

use App\Enums\PaymentTransactionStatus;
use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'payment_transaction';

    protected $primaryKey = 'transaction_id';

    public $timestamps = false;

    protected $fillable = [
        'payment_record_id',
        'transfer_type',
        'gateway',
        'account_number',
        'sepay_transaction_id',
        'transfer_amount',
        'transaction_date',
        'reference_code',
        'content',
        'description',
        'receipt_number',
        'confirmed_by_account_id',
        'process_status',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'payment_record_id' => 'integer',
            'transfer_amount' => 'decimal:2',
            'transaction_date' => 'datetime',
            'confirmed_by_account_id' => 'integer',
            'process_status' => PaymentTransactionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<PaymentRecord, $this>
     */
    public function paymentRecord(): BelongsTo
    {
        return $this->belongsTo(PaymentRecord::class, 'payment_record_id', 'payment_record_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'confirmed_by_account_id', 'account_id');
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentTransaction $transaction) {
            $transaction->transaction_date ??= now();
            $transaction->process_status ??= PaymentTransactionStatus::PENDING;
        });
    }
}
