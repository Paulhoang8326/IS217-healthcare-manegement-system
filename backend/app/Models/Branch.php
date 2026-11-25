<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'branch';

    protected $primaryKey = 'branch_id';

    public $timestamps = false;

    protected $fillable = [
        'branch_name',
        'branch_address',
        'branch_hotline',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
        ];
    }
}
