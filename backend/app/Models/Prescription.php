<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Prescription extends Model
{
    protected $table = 'prescription';

    protected $primaryKey = 'prescription_id';

    public $timestamps = false;

    protected $fillable = [
        'med_record_id',
        'note',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $attributes = [
        'is_active' => 1,
    ];

    protected function casts(): array
    {
        return [
            'prescription_id' => 'integer',
            'med_record_id' => 'integer',
            'is_active' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MedicalRecord, $this>
     */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'med_record_id', 'med_record_id');
    }

    /**
     * @return HasMany<PrescriptionDetail, $this>
     */
    public function prescriptionDetails(): HasMany
    {
        return $this->hasMany(PrescriptionDetail::class, 'prescription_id', 'prescription_id');
    }

    /**
     * @return HasOne<PaymentRecord, $this>
     */
    public function paymentRecord(): HasOne
    {
        return $this->hasOne(PaymentRecord::class, 'prescription_id', 'prescription_id');
    }

    protected static function booted(): void
    {
        static::creating(function (Prescription $prescription) {
            $prescription->created_at ??= now();
            $prescription->is_active ??= 1;
        });

        static::updating(function (Prescription $prescription) {
            $prescription->updated_at = now();
        });
    }
}
