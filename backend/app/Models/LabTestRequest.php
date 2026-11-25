<?php

namespace App\Models;

use App\Enums\LabTestRequestStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabTestRequest extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'lab_test_request';

    protected $primaryKey = 'lab_test_request_id';

    public $timestamps = false;

    protected $fillable = [
        'med_record_id',
        'request_code',
        'status',
        'payment_status',
        'total_price',
        'note',
        'created_at',
        'updated_at',
        'paid_at',
    ];

    protected $attributes = [
        // Hibernate inserts NULL for an unset field, so the column DEFAULT must not apply.
        'total_price' => null,
    ];

    protected function casts(): array
    {
        return [
            'lab_test_request_id' => 'integer',
            'med_record_id' => 'integer',
            'status' => LabTestRequestStatus::class,
            'payment_status' => PaymentStatus::class,
            'total_price' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'paid_at' => 'datetime',
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
     * @return HasMany<LabTestRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(LabTestRequestItem::class, 'lab_test_request_id', 'lab_test_request_id');
    }

    protected static function booted(): void
    {
        static::creating(function (LabTestRequest $request) {
            $request->created_at = now();
            $request->status ??= LabTestRequestStatus::NOT_COLLECTED;
            $request->payment_status ??= PaymentStatus::UNPAID;
        });

        static::updating(function (LabTestRequest $request) {
            $request->updated_at = now();
        });
    }
}
