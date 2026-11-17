<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medicine extends Model
{
    protected $table = 'medicine';

    protected $primaryKey = 'medicine_id';

    public $timestamps = false;

    protected $fillable = [
        'medicine_name',
        'active_ingredient',
        'unit',
        'description',
        'is_active',
        'selling_price',
    ];

    protected $attributes = [
        'is_active' => 1,
        'selling_price' => '0.00',
    ];

    protected function casts(): array
    {
        return [
            'medicine_id' => 'integer',
            'is_active' => 'integer',
            'selling_price' => 'decimal:2',
        ];
    }
}
