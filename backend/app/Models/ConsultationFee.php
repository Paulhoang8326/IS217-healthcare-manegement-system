<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationFee extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'consultation_fee';

    protected $primaryKey = 'fee_id';

    public $timestamps = false;

    protected $fillable = [
        'fee_code',
        'fee_name',
        'specialty',
        'specialty_id',
        'price',
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
            'fee_id' => 'integer',
            'specialty_id' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->is_active === 1;
    }

    /**
     * Java field name is specialtyRef; the string column `specialty` keeps the name.
     *
     * @return BelongsTo<Specialty, $this>
     */
    public function specialtyRef(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'specialty_id', 'specialty_id');
    }

    protected static function booted(): void
    {
        static::creating(function (ConsultationFee $fee) {
            $fee->created_at ??= now();
            $fee->is_active ??= 1;
        });

        static::updating(function (ConsultationFee $fee) {
            $fee->updated_at = now();
        });
    }
}
