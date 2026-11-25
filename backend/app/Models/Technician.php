<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Technician extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'technician';

    protected $primaryKey = 'technician_id';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'full_name',
        'qualification',
        'specialty_area',
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
            'technician_id' => 'integer',
            'account_id' => 'integer',
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'experience' => 'integer',
            'is_active' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }
}
