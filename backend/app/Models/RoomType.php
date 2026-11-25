<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'room_type';

    protected $primaryKey = 'room_type_id';

    public $timestamps = false;

    protected $fillable = [
        'room_type_name',
    ];

    protected function casts(): array
    {
        return [
            'room_type_id' => 'integer',
        ];
    }
}
