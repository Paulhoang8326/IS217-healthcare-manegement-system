<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRecord extends Model
{
    protected $table = 'payment_record';

    protected $primaryKey = 'payment_record_id';

    public $timestamps = false;

    protected $fillable = [
        'appointment_id',
        'med_record_id',
        'prescription_id',
        'request_code',
        'total_price',
        'received_amount',
        'payment_status',
        'created_at',
        'updated_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_record_id' => 'integer',
            'appointment_id' => 'integer',
            'med_record_id' => 'integer',
            'prescription_id' => 'integer',
            'total_price' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id', 'appointment_id');
    }

    /**
     * @return BelongsTo<MedicalRecord, $this>
     */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'med_record_id', 'med_record_id');
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id', 'prescription_id');
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentRecord $record) {
            $record->created_at ??= now();
            $record->received_amount ??= '0.00';
            $record->payment_status ??= PaymentStatus::UNPAID;
        });

        static::updating(function (PaymentRecord $record) {
            $record->updated_at = now();
        });
    }
}
