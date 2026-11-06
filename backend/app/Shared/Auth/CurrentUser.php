<?php

namespace App\Shared\Auth;

use Illuminate\Http\Request;

final readonly class CurrentUser
{
    public function __construct(public int $accountId, public ?string $email, public ?string $role) {}

    public function getName(): ?string
    {
        return $this->email;
    }

    public static function fromRequest(?Request $request = null): ?self
    {
        $principal = ($request ?? request())->attributes->get(self::class);

        return $principal instanceof self ? $principal : null;
    }
}
