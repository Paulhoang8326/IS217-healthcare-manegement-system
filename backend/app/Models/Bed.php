<?php

namespace App\Models;

use App\Enums\BedStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bed extends Model
{
    protected $table = 'bed';

    protected $primaryKey = 'bed_id';

    public $timestamps = false;

    protected $fillable = [
        'price',
        'status',
        'room_id',
    ];

    protected $attributes = [
        'status' => 'AVAILABLE',
    ];

    protected function casts(): array
    {
        return [
            'bed_id' => 'integer',
            'price' => 'decimal:2',
            'status' => BedStatus::class,
            'room_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }
}
