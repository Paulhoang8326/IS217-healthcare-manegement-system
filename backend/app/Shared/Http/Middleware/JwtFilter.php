<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Auth\AccountRepository;
use App\Shared\Auth\CurrentUser;
use App\Shared\Auth\JwtServiceImpl;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class JwtFilter
{
    public function __construct(private JwtServiceImpl $jwtService, private AccountRepository $accountRepository) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api', 'api/*')) {
            return $next($request);
        }

        if ($request->attributes->get(self::class)) {
            return $next($request);
        }
        $request->attributes->set(self::class, true);
        $header = $request->header('Authorization');
        $error = null;
        $principal = CurrentUser::fromRequest($request);

        if ($header === null || preg_match('/^\s*$/u', $header)) {
            $error = 'Missing Authorization Bearer token';
        } elseif (strncasecmp($header, 'Bearer ', 7) !== 0) {
            $error = 'Authorization header must start with Bearer';
        } else {
            $token = $this->normalizeToken(substr($header, 7));
            if ($token === '') {
                $error = 'Bearer token is blank';
            } elseif (! $this->jwtService->validateToken($token)) {
                $error = 'Invalid or expired token';
            } elseif ($principal === null) {
                try {
                    $email = $this->jwtService->extractEmail($token);
                    $accountId = $this->jwtService->extractAccountId($token);
                    // QUIRK-NEW: Keep the token ID and allow missing or inactive accounts, as Java does.
                    $account = $this->accountRepository->findWithRoleByEmail($email);
                    $role = $account !== null && $account->resolved_role_id !== null
                        ? $account->role_name : $this->jwtService->extractRole($token);
                    $principal = new CurrentUser($accountId, $email, $role);
                    $request->attributes->set(CurrentUser::class, $principal);
                } catch (RuntimeException $exception) {
                    $error = 'Cannot read authentication data from token: '.$exception->getMessage();
                }
            }
        }
        if ($error !== null) {
            $request->attributes->set('authError', $error);
        }
        $request->setUserResolver(fn () => $principal);

        if ($principal === null && ! $this->isPublic($request)) {
            return $this->unauthenticated($request);
        }

        return $next($request);
    }

    private function unauthenticated(Request $request): Response
    {
        $error = $request->attributes->get('authError');
        $message = is_string($error) ? $error : 'Authentication required; uri='.$request->getPathInfo()
            .'; authorizationHeaderPresent='.($request->headers->has('Authorization') ? 'true' : 'false')
            .'; securityContextAuthentication=false';

        return response($message, 401, ['Content-Type' => 'text/plain;charset=UTF-8']);
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token, "\x00..\x20");
        // QUIRK-NEW: Strip only one case-sensitive inner Bearer prefix, before unquoting.
        if (str_starts_with($token, 'Bearer ')) {
            $token = trim(substr($token, 7), "\x00..\x20");
        }
        if (strlen($token) >= 2 && str_starts_with($token, '"') && str_ends_with($token, '"')) {
            $token = trim(substr($token, 1, -1), "\x00..\x20");
        }

        return $token;
    }

    private function isPublic(Request $request): bool
    {
        return $request->isMethod('OPTIONS') || $request->is(
            'api/auth', 'api/auth/*',
            'api/appointments/sepay/webhook',
            'api/payment-records/medical-records/sepay/webhook',
            'api/payment-records/prescriptions/sepay/webhook',
            'swagger-ui.html', 'swagger-ui', 'swagger-ui/*', 'v3/api-docs', 'v3/api-docs/*',
            'revenue-report-mock.html', 'error',
        );
    }
}
