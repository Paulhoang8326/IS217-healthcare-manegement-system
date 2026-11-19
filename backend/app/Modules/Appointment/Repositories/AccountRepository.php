<?php

namespace App\Modules\Appointment\Repositories;

use App\Models\Account;

class AccountRepository
{
    public function findById(int $id): ?Account
    {
        return Account::find($id);
    }
}
