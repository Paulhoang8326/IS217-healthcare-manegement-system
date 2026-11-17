<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $table = 'permission';

    protected $primaryKey = 'permission_id';

    public $timestamps = false;

    protected $fillable = [
        'permission_name',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'permission_id' => 'integer',
        ];
    }
}
