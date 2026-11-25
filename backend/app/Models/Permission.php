<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use StoresEmptyStringAsNull;

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
