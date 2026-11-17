<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Accountant extends Model
{
    protected $table = 'accountant';

    protected $primaryKey = 'accountant_id';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'full_name',
        'qualification',
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
            'accountant_id' => 'integer',
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
