<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receptionist extends Model
{
    protected $table = 'receptionist';

    protected $primaryKey = 'receptionist_id';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'full_name',
        'identity_num',
        'gender',
        'phone',
        'address',
        'date_of_birth',
        'hire_date',
        'shift',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => 1,
    ];

    protected function casts(): array
    {
        return [
            'receptionist_id' => 'integer',
            'account_id' => 'integer',
            'date_of_birth' => 'date',
            'hire_date' => 'date',
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
