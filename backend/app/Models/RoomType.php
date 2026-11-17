<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
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
