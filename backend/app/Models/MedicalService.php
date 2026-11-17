<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalService extends Model
{
    protected $table = 'medical_service';

    protected $primaryKey = 'med_service_id';

    public $timestamps = false;

    protected $fillable = [
        'medical_service_name',
        'price',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => 1,
    ];

    protected function casts(): array
    {
        return [
            'med_service_id' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'integer',
        ];
    }
}
