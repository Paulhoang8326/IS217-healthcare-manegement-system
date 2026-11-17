<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Doctor extends Model
{
    protected $table = 'doctor';

    protected $primaryKey = 'doctor_id';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'full_name',
        'qualification',
        'specialization',
        'specialty_id',
        'license_num',
        'identity_num',
        'gender',
        'phone',
        'address',
        'date_of_birth',
        'hire_date',
        'experience',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => 1,
    ];

    protected function casts(): array
    {
        return [
            'doctor_id' => 'integer',
            'account_id' => 'integer',
            'specialty_id' => 'integer',
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'is_active' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->is_active === 1;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * @return BelongsTo<Specialty, $this>
     */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'specialty_id', 'specialty_id');
    }
}
