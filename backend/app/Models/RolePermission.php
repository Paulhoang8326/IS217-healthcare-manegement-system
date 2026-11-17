<?php

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolePermission extends Model
{
    use HasCompositePrimaryKey;

    protected $table = 'role_permission';

    protected $primaryKey = 'role_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'role_id',
        'permission_id',
    ];

    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'permission_id' => 'integer',
        ];
    }

    public function getCompositeKeyNames(): array
    {
        return ['role_id', 'permission_id'];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }
}
