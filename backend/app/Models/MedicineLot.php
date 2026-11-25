<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicineLot extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'medicine_lot';

    protected $primaryKey = 'medicine_lot_id';

    public $timestamps = false;

    protected $fillable = [
        'medicine_id',
        'lot_number',
        'manufacturing_date',
        'expiry_date',
        'quantity',
        'import_price',
        'is_active',
        'import_date',
    ];

    protected $attributes = [
        'is_active' => 1,
        // Hibernate inserts NULL for an unset field, so the column DEFAULT must not apply.
        'quantity' => null,
    ];

    protected function casts(): array
    {
        return [
            'medicine_lot_id' => 'integer',
            'medicine_id' => 'integer',
            'manufacturing_date' => 'date',
            'expiry_date' => 'date',
            'quantity' => 'integer',
            'import_price' => 'decimal:2',
            'is_active' => 'integer',
            'import_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }

    protected static function booted(): void
    {
        static::creating(function (MedicineLot $lot) {
            $lot->import_date ??= today();
        });
    }
}
