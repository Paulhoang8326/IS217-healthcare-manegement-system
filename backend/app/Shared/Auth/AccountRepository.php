<?php

namespace App\Shared\Auth;

use Illuminate\Support\Facades\DB;
use stdClass;

class AccountRepository
{
    public function findWithRoleByEmail(?string $email): ?stdClass
    {
        return DB::table('account')->leftJoin('role', 'account.role_id', '=', 'role.role_id')
            ->where('account.email', $email)
            ->select('role.role_id as resolved_role_id', 'role.role_name')->first();
    }
}
