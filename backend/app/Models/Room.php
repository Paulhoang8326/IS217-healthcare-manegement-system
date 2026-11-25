<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'room';

    protected $primaryKey = 'room_id';

    public $timestamps = false;

    protected $fillable = [
        'room_code',
        'position',
        'note',
        'floor',
        'room_type_id',
        'branch_id',
        'specialty_id',
    ];

    protected $attributes = [
        'floor' => 1,
    ];

    protected function casts(): array
    {
        return [
            'room_id' => 'integer',
            'floor' => 'integer',
            'room_type_id' => 'integer',
            'branch_id' => 'integer',
            'specialty_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id', 'room_type_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }

    /**
     * @return BelongsTo<Specialty, $this>
     */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'specialty_id', 'specialty_id');
    }

    /**
     * @return HasMany<Bed, $this>
     */
    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class, 'room_id', 'room_id');
    }
}
