<?php

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Models\Concerns\StoresEmptyStringAsNull;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPermission extends Model
{
    use HasCompositePrimaryKey;
    use StoresEmptyStringAsNull;

    protected $table = 'account_permission';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'permission_id',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'permission_id' => 'integer',
        ];
    }

    public function getCompositeKeyNames(): array
    {
        return ['account_id', 'permission_id'];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }
}
