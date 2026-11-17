<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Auth\CurrentUser;
use App\Shared\Exceptions\AccessDeniedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function __construct(private JwtFilter $jwtFilter) {}

    public function handle(Request $request, Closure $next, string ...$requirements): Response
    {
        $principal = CurrentUser::fromRequest($request);
        if ($principal === null) {
            return $this->jwtFilter->unauthenticated($request);
        }

        $operation = 'hasAnyRole';
        if (in_array($requirements[0] ?? null, ['hasRole', 'hasAnyRole', 'hasAuthority', 'hasAnyAuthority', 'isAuthenticated'], true)) {
            $operation = array_shift($requirements);
        }

        if ($operation === 'isAuthenticated' && $requirements === []) {
            return $next($request);
        }
        if ($operation === 'isAuthenticated' || $requirements === []
            || (in_array($operation, ['hasRole', 'hasAuthority'], true) && count($requirements) !== 1)) {
            throw new AccessDeniedException;
        }

        $authorities = $this->buildAuthorities($principal->role);
        foreach ($requirements as $required) {
            if (in_array($operation, ['hasRole', 'hasAnyRole'], true) && ! str_starts_with($required, 'ROLE_')) {
                $required = 'ROLE_'.$required;
            }
            // QUIRK-02: Keep ROLE_NURSE literal; do not substitute an existing role.
            if (in_array($required, $authorities, true)) {
                return $next($request);
            }
        }

        throw new AccessDeniedException;
    }

    private function buildAuthorities(?string $role): array
    {
        $authority = $role !== null && str_starts_with($role, 'ROLE_') ? $role : 'ROLE_'.($role ?? 'null');

        // QUIRK-01: ADMIN inherits only these authorities, excluding TECHNICIAN and PHARMACIST.
        return $authority === 'ROLE_ADMIN'
            ? ['ROLE_ADMIN', 'ROLE_RECEPTIONIST', 'ROLE_DOCTOR', 'ROLE_PATIENT', 'ROLE_ACCOUNTANT']
            : [$authority];
    }
}
