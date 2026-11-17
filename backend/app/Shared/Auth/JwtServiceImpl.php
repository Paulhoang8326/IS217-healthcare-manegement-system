<?php

namespace App\Shared\Auth;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

class JwtServiceImpl
{
    public function generateVerificationToken(string $email): string
    {
        return $this->generate(['sub' => $email], 'verification-token-expiration-ms');
    }

    public function generateToken(?int $accountId, string $email, ?string $roleName): string
    {
        return $this->generate(array_filter([
            'sub' => $email, 'accountId' => $accountId, 'roleName' => $roleName,
        ], fn ($value) => $value !== null), 'access-token-expiration-ms');
    }

    public function validateToken(string $token): bool
    {
        try {
            $this->parse($token);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function extractEmail(string $token): ?string
    {
        return $this->parse($token)->sub ?? null;
    }

    public function extractRole(string $token): ?string
    {
        $role = $this->parse($token)->roleName ?? null;
        if ($role === null || is_string($role)) {
            return $role;
        }

        $type = match (true) {
            is_int($role) => $role >= -2147483648 && $role <= 2147483647 ? 'Integer' : 'Long',
            is_float($role) => 'Double',
            is_bool($role) => 'Boolean',
            default => null,
        };
        $type = $type === null ? (is_array($role) ? 'java.util.ArrayList' : 'java.util.LinkedHashMap') : 'java.lang.'.$type;

        throw new RuntimeException("Cannot convert existing claim value of type 'class ".$type."' to desired type 'class java.lang.String'. JJWT only converts simple String, Date, Long, Integer, Short and Byte types automatically. Anything more complex is expected to be already converted to your desired type by the JSON Deserializer implementation. You may specify a custom Deserializer for a JwtParser with the desired conversion configuration via the JwtParserBuilder.deserializer() method. See https://github.com/jwtk/jjwt#custom-json-processor for more information. If using Jackson, you can specify custom claim POJO types as described in https://github.com/jwtk/jjwt#json-jackson-custom-types");
    }

    public function extractAccountId(string $token): int
    {
        $id = $this->parse($token)->accountId ?? null;
        if (is_int($id)) {
            return $id;
        }
        if (is_float($id)) {
            return $id >= PHP_INT_MAX ? PHP_INT_MAX : ($id <= PHP_INT_MIN ? PHP_INT_MIN : (int) $id);
        }
        if (is_string($id) && preg_match('/[^\s]/u', $id)) {
            $digits = ltrim(ltrim($id, '+-'), '0');
            $bound = str_starts_with($id, '-') ? '9223372036854775808' : '9223372036854775807';
            if (preg_match('/^[+-]?[0-9]+$/D', $id)
                && (strlen($digits) < 19 || (strlen($digits) === 19 && strcmp($digits, $bound) <= 0))) {
                return (int) $id;
            }
            throw new RuntimeException('For input string: "'.$id.'"');
        }

        throw new RuntimeException('Token does not contain a valid accountId claim');
    }

    private function parse(string $token): stdClass
    {
        try {
            $claims = JWT::decode($token, new Key($this->key(), 'HS256'));
        } catch (BeforeValidException $exception) {
            $claims = $exception->getPayload();
            if (isset($claims->nbf)) {
                throw $exception;
            }
            // QUIRK-NEW: JJWT does not reject a future iat; Firebase does.
            if (isset($claims->exp) && time() >= $claims->exp) {
                throw new RuntimeException('Expired token');
            }
        }

        $header = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $token)[0]));
        $critical = $header->crit ?? null;
        if ($critical !== null && $critical !== '' && $critical !== []
            && ! ($critical instanceof stdClass && get_object_vars($critical) === [])) {
            throw new RuntimeException('Unsupported critical JWT header');
        }
        if (isset($claims->sub) && ! is_string($claims->sub)) {
            throw new RuntimeException('Invalid subject claim type');
        }

        return $claims;
    }

    private function key(): string
    {
        $secret = config('jwt.secret');
        if (! is_string($secret)) {
            throw new InvalidArgumentException('JWT secret is not configured');
        }

        return hash('sha256', $secret, true);
    }

    private function generate(array $claims, string $expirationKey): string
    {
        $ttl = config('jwt.'.$expirationKey);
        if (! is_numeric($ttl)) {
            throw new InvalidArgumentException('JWT expiration is not configured');
        }
        $now = (int) floor(microtime(true) * 1000);
        $claims['iat'] = intdiv($now, 1000);
        $claims['exp'] = (int) floor(($now + (int) $ttl) / 1000);
        $header = JWT::urlsafeB64Encode(JWT::jsonEncode(['alg' => 'HS256']));
        $payload = JWT::urlsafeB64Encode(JWT::jsonEncode($claims));
        $input = $header.'.'.$payload;

        return $input.'.'.JWT::urlsafeB64Encode(JWT::sign($input, $this->key(), 'HS256'));
    }
}
