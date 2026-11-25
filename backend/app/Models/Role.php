<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'role';

    protected $primaryKey = 'role_id';

    public $timestamps = false;

    protected $fillable = [
        'role_name',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
        ];
    }
}
