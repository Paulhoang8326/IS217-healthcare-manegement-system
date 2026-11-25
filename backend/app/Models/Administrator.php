<?php

namespace App\Models;

use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Administrator extends Model
{
    use StoresEmptyStringAsNull;

    protected $table = 'administrator';

    protected $primaryKey = 'administrator_id';

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
        'is_active',
    ];

    protected $attributes = [
        'is_active' => 1,
    ];

    protected function casts(): array
    {
        return [
            'administrator_id' => 'integer',
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
