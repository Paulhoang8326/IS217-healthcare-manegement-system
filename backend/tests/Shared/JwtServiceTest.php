<?php

namespace Tests\Shared;

use App\Shared\Auth\JwtServiceImpl;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class JwtServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'local-test-secret', 'jwt.access-token-expiration-ms' => 60000,
            'jwt.verification-token-expiration-ms' => 120000]);
    }

    public function test_access_token_uses_java_key_header_and_claims(): void
    {
        $service = new JwtServiceImpl;
        $before = time();
        $token = $service->generateToken(17, 'patient@example.test', 'PATIENT');
        $claims = JWT::decode($token, new Key(hash('sha256', 'local-test-secret', true), 'HS256'));
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true);
        $this->assertSame(['alg' => 'HS256'], $header);
        $this->assertSame(['sub', 'accountId', 'roleName', 'iat', 'exp'], array_keys((array) $claims));
        $this->assertSame('patient@example.test', $service->extractEmail($token));
        $this->assertSame(17, $service->extractAccountId($token));
        $this->assertSame('PATIENT', $service->extractRole($token));
        $this->assertGreaterThanOrEqual($before, $claims->iat);
        $this->assertSame(60, $claims->exp - $claims->iat);
        $this->assertTrue($service->validateToken($token));
    }

    public function test_verification_token_has_no_account_or_role_claims(): void
    {
        $service = new JwtServiceImpl;
        $token = $service->generateVerificationToken('verify@example.test');
        $claims = JWT::decode($token, new Key(hash('sha256', 'local-test-secret', true), 'HS256'));
        $this->assertSame(['sub', 'iat', 'exp'], array_keys((array) $claims));
        $this->assertSame(120, $claims->exp - $claims->iat);
        $this->assertTrue($service->validateToken($token));
        $this->expectExceptionMessage('Token does not contain a valid accountId claim');
        $service->extractAccountId($token);
    }

    #[DataProvider('ids')]
    public function test_account_id_conversion_matches_java(mixed $id, ?int $expected, ?string $error): void
    {
        $token = JWT::encode(['accountId' => $id], hash('sha256', 'local-test-secret', true), 'HS256');
        if ($error !== null) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($error);
        }
        $this->assertSame($expected, (new JwtServiceImpl)->extractAccountId($token));
    }

    public static function ids(): iterable
    {
        yield [17, 17, null];
        yield ['+0017', 17, null];
        yield ['-17', -17, null];
        yield [17.9, 17, null];
        yield ['9223372036854775807', PHP_INT_MAX, null];
        yield ['-9223372036854775808', PHP_INT_MIN, null];
        yield ['9223372036854775808', null, 'For input string: "9223372036854775808"'];
        yield ['abc', null, 'For input string: "abc"'];
        yield [' 17 ', null, 'For input string: " 17 "'];
        yield [null, null, 'Token does not contain a valid accountId claim'];
        yield ['   ', null, 'Token does not contain a valid accountId claim'];
        yield [true, null, 'Token does not contain a valid accountId claim'];
    }

    #[DataProvider('invalidParsedTokens')]
    public function test_validate_rejects_invalid_parsed_tokens(array $claims, array $header): void
    {
        $token = JWT::encode(array_replace(['sub' => 'valid@example.test', 'accountId' => 17, 'exp' => time() + 60], $claims),
            hash('sha256', 'local-test-secret', true), 'HS256', null, $header);
        $this->assertFalse((new JwtServiceImpl)->validateToken($token));
    }

    public static function invalidParsedTokens(): iterable
    {
        return JwtAuthenticationTest::invalidParsedTokens();
    }

    public function test_standard_headers_and_subject_remain_valid(): void
    {
        $service = new JwtServiceImpl;
        foreach ([[], ['crit' => []], ['crit' => null]] as $header) {
            $token = JWT::encode(['sub' => 'valid@example.test', 'accountId' => 17, 'exp' => time() + 60],
                hash('sha256', 'local-test-secret', true), 'HS256', null, $header);
            $this->assertTrue($service->validateToken($token));
            $this->assertSame('valid@example.test', $service->extractEmail($token));
        }
    }

    public function test_signature_algorithm_expiration_and_not_before_are_validated(): void
    {
        $service = new JwtServiceImpl;
        $key = hash('sha256', 'local-test-secret', true);
        $this->assertFalse($service->validateToken('broken'));
        $this->assertFalse($service->validateToken(JWT::encode(['exp' => time() - 60], $key, 'HS256')));
        $this->assertFalse($service->validateToken(JWT::encode(['nbf' => time() + 60], $key, 'HS256')));
        $this->assertFalse($service->validateToken(JWT::encode(['sub' => 'test'], str_repeat('x', 32), 'HS256')));
        $this->assertFalse($service->validateToken(JWT::encode(['sub' => 'test'], str_repeat('x', 64), 'HS512')));
        $this->assertTrue($service->validateToken(JWT::encode(['nbf' => time() - 60], $key, 'HS256')));
        $this->assertTrue($service->validateToken(JWT::encode(['iat' => time() + 3600, 'exp' => time() + 7200], $key, 'HS256')));
        $this->assertFalse($service->validateToken(JWT::encode(['iat' => time() + 3600, 'exp' => time() - 60], $key, 'HS256')));
    }
}
