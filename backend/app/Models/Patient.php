<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Patient extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'patient';

    protected $primaryKey = 'patient_id';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'full_name',
        'gender',
        'date_of_birth',
        'phone',
        'address',
        'identity_num',
        'medical_history',
        'allergy',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => 1,
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'account_id' => 'integer',
            'date_of_birth' => 'date',
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
