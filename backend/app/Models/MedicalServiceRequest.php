<?php

namespace App\Models;

use App\Enums\MedicalServiceRequestStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalServiceRequest extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'medical_service_request';

    protected $primaryKey = 'med_ser_req_id';

    public $timestamps = false;

    protected $fillable = [
        'med_record_id',
        'request_code',
        'status',
        'payment_status',
        'total_price',
        'currency',
        'confirmed_at',
        'cancelled_at',
        'note',
        'created_at',
        'updated_at',
        'paid_at',
    ];

    protected $attributes = [
        'currency' => 'VND',
        // Hibernate inserts NULL for an unset field, so the column DEFAULT must not apply.
        'total_price' => null,
    ];

    protected function casts(): array
    {
        return [
            'med_ser_req_id' => 'integer',
            'med_record_id' => 'integer',
            'status' => MedicalServiceRequestStatus::class,
            'payment_status' => PaymentStatus::class,
            'total_price' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MedicalRecord, $this>
     */
    public function medRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'med_record_id', 'med_record_id');
    }

    /**
     * @return HasMany<MedicalServiceRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MedicalServiceRequestItem::class, 'med_ser_req_id', 'med_ser_req_id');
    }

    protected static function booted(): void
    {
        static::creating(function (MedicalServiceRequest $request) {
            $request->created_at ??= now();
            $request->status ??= MedicalServiceRequestStatus::NOT_COLLECTED;
            $request->payment_status ??= PaymentStatus::UNPAID;
            $request->currency ??= 'VND';
        });

        static::updating(function (MedicalServiceRequest $request) {
            $request->updated_at = now();
        });
    }
}
