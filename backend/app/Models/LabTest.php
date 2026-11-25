<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;

class LabTest extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'lab_test';

    protected $primaryKey = 'lab_test_id';

    public $timestamps = false;

    protected $fillable = [
        'lab_test_name',
        'price',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => 1,
    ];

    protected function casts(): array
    {
        return [
            'lab_test_id' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'integer',
        ];
    }
}
