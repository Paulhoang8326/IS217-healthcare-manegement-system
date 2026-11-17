<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialty extends Model
{
    protected $table = 'specialty';

    protected $primaryKey = 'specialty_id';

    public $timestamps = false;

    protected $fillable = [
        'specialty_code',
        'specialty_name',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $attributes = [
        'is_active' => 1,
    ];

    protected function casts(): array
    {
        return [
            'specialty_id' => 'integer',
            'is_active' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->is_active === 1;
    }

    protected static function booted(): void
    {
        static::creating(function (Specialty $specialty) {
            $specialty->created_at ??= now();
            $specialty->is_active ??= 1;
        });

        static::updating(function (Specialty $specialty) {
            $specialty->updated_at = now();
        });
    }
}
