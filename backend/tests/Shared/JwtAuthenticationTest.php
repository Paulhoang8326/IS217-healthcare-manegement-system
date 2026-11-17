<?php

namespace Tests\Shared;

use App\Shared\Auth\CurrentUser;
use App\Shared\Auth\JwtServiceImpl;
use App\Shared\Exceptions\BusinessException;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JwtAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('DROP TABLE IF EXISTS account');
            DB::statement('DROP TABLE IF EXISTS role');
        });
        config(['jwt.secret' => 'local-test-secret', 'jwt.access-token-expiration-ms' => 60000,
            'jwt.verification-token-expiration-ms' => 120000]);
        DB::statement('CREATE TABLE role (role_id INTEGER PRIMARY KEY, role_name TEXT)');
        DB::statement('CREATE TABLE account (account_id INTEGER PRIMARY KEY, email VARCHAR(255) UNIQUE, role_id INTEGER, is_active INTEGER)');
        Route::middleware('auth.jwt')->get('/api/principal', function (Request $request) {
            $user = CurrentUser::fromRequest($request);
            $this->assertSame($user, $request->user());
            $this->assertSame($user, CurrentUser::fromRequest());

            return response()->json(['accountId' => $user?->accountId, 'email' => $user?->getName(), 'role' => $user?->role]);
        });
    }

    private function token(array $claims = []): string
    {
        return JWT::encode(array_replace(['sub' => 'patient@example.test', 'accountId' => 17,
            'roleName' => 'PATIENT', 'iat' => time(), 'exp' => time() + 60], $claims),
            hash('sha256', 'local-test-secret', true), 'HS256');
    }

    #[DataProvider('validHeaders')]
    public function test_bearer_normalization(string $format): void
    {
        $this->getJson('/api/principal', ['Authorization' => sprintf($format, $this->token())])->assertOk()
            ->assertExactJson(['accountId' => 17, 'email' => 'patient@example.test', 'role' => 'PATIENT']);
    }

    public static function validHeaders(): iterable
    {
        yield ['Bearer %s'];
        yield ['bEaReR %s'];
        yield ['Bearer   %s  '];
        yield ['Bearer Bearer %s'];
        yield ['Bearer "%s"'];
        yield ['Bearer Bearer "  %s  "'];
    }

    #[DataProvider('invalidHeaders')]
    public function test_unauthorized_messages_are_exact(?string $header, string $message): void
    {
        $headers = $header === null ? [] : ['Authorization' => $header];
        $this->getJson('/api/principal', $headers)->assertStatus(401)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent($message);
    }

    public static function invalidHeaders(): iterable
    {
        yield [null, 'Missing Authorization Bearer token'];
        yield ['', 'Missing Authorization Bearer token'];
        yield ['   ', 'Missing Authorization Bearer token'];
        yield ['Basic abc', 'Authorization header must start with Bearer'];
        yield [' Bearer abc', 'Authorization header must start with Bearer'];
        yield ['Bearer', 'Authorization header must start with Bearer'];
        yield ['Bearer ', 'Bearer token is blank'];
        yield ['Bearer ""', 'Bearer token is blank'];
        yield ['Bearer broken', 'Invalid or expired token'];
    }

    public function test_normalization_does_not_loop_or_remove_bearer_inside_quotes(): void
    {
        foreach (['Bearer Bearer Bearer %s', 'Bearer bearer %s', 'Bearer "Bearer %s"'] as $format) {
            $this->getJson('/api/principal', ['Authorization' => sprintf($format, $this->token())])
                ->assertStatus(401)->assertContent('Invalid or expired token');
        }
    }

    public function test_database_role_wins_without_changing_token_account_id_or_checking_active(): void
    {
        DB::insert('INSERT INTO role (role_id, role_name) VALUES (1, ?)', ['ROLE_DOCTOR']);
        DB::insert('INSERT INTO account (account_id, email, role_id, is_active) VALUES (99, ?, 1, 0)', ['patient@example.test']);
        $this->getJson('/api/principal', ['Authorization' => 'Bearer '.$this->token()])->assertOk()
            ->assertExactJson(['accountId' => 17, 'email' => 'patient@example.test', 'role' => 'ROLE_DOCTOR']);
    }

    public function test_missing_database_role_falls_back_to_claim(): void
    {
        DB::insert('INSERT INTO account (account_id, email, role_id) VALUES (99, ?, NULL)', ['patient@example.test']);
        $this->getJson('/api/principal', ['Authorization' => 'Bearer '.$this->token()])->assertOk()->assertJsonPath('role', 'PATIENT');
    }

    public function test_verification_token_fails_at_principal_extraction(): void
    {
        $this->getJson('/api/principal', ['Authorization' => 'Bearer '.(new JwtServiceImpl)->generateVerificationToken('verify@example.test')])
            ->assertStatus(401)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
            ->assertContent('Cannot read authentication data from token: Token does not contain a valid accountId claim');
    }

    public function test_missing_role_or_email_is_not_an_extra_validation_rule(): void
    {
        $this->getJson('/api/principal', ['Authorization' => 'Bearer '.$this->token(['sub' => null, 'roleName' => null])])
            ->assertOk()->assertExactJson(['accountId' => 17, 'email' => null, 'role' => null]);
    }

    public function test_expired_wrong_signature_and_invalid_account_id_fail(): void
    {
        $this->getJson('/api/principal', ['Authorization' => 'Bearer '.$this->token(['exp' => time() - 60])])
            ->assertStatus(401)->assertContent('Invalid or expired token');
        $wrong = JWT::encode(['accountId' => 17], str_repeat('x', 32), 'HS256');
        $this->getJson('/api/principal', ['Authorization' => 'Bearer '.$wrong])->assertStatus(401)->assertContent('Invalid or expired token');
        $this->getJson('/api/principal', ['Authorization' => 'Bearer '.$this->token(['accountId' => 'abc'])])
            ->assertStatus(401)->assertContent('Cannot read authentication data from token: For input string: "abc"');
    }

    #[DataProvider('publicPaths')]
    public function test_public_requests_continue_even_with_invalid_tokens(string $method, string $path): void
    {
        Route::match([$method], $path, fn () => response()->json(['public' => true]))->middleware('auth.jwt');
        $this->json($method, $path)->assertOk();
        $this->json($method, $path, [], ['Authorization' => 'Bearer broken'])->assertOk();
    }

    public static function publicPaths(): iterable
    {
        yield ['POST', '/api/auth/login'];
        yield ['POST', '/api/appointments/sepay/webhook'];
        yield ['POST', '/api/payment-records/medical-records/sepay/webhook'];
        yield ['POST', '/api/payment-records/prescriptions/sepay/webhook'];
        yield ['OPTIONS', '/api/any-protected-path'];
    }

    public function test_public_endpoint_receives_optional_principal(): void
    {
        Route::get('/api/auth/optional', fn () => response()->json(['id' => CurrentUser::fromRequest()?->accountId]))
            ->middleware('auth.jwt');
        $this->getJson('/api/auth/optional', ['Authorization' => 'Bearer '.$this->token()])
            ->assertOk()->assertExactJson(['id' => 17]);
        $this->getJson('/api/auth/optional', ['Authorization' => 'Bearer broken'])
            ->assertOk()->assertExactJson(['id' => null]);
    }

    public function test_database_lookup_failure_is_an_authentication_data_error(): void
    {
        DB::statement('DROP TABLE role');
        $response = $this->getJson('/api/principal', ['Authorization' => 'Bearer '.$this->token()]);
        $response->assertStatus(401)->assertHeader('Content-Type', 'text/plain;charset=UTF-8');
        $this->assertStringStartsWith('Cannot read authentication data from token: ', $response->getContent());
    }

    public function test_webhook_exemption_is_an_exact_path(): void
    {
        Route::post('/api/appointments/sepay/webhook/private', fn () => response()->noContent())
            ->middleware('auth.jwt');
        $this->postJson('/api/appointments/sepay/webhook/private')->assertStatus(401)
            ->assertContent('Missing Authorization Bearer token');
    }

    public function test_central_authentication_rejects_protected_route_without_alias(): void
    {
        Route::get('/api/central-protected', fn () => response()->json(['private' => true]));
        $this->getJson('/api/central-protected')->assertStatus(401)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
            ->assertContent('Missing Authorization Bearer token');
    }

    public function test_central_authentication_accepts_valid_token_without_alias(): void
    {
        Route::get('/api/central-protected', function (Request $request) {
            $this->assertSame(CurrentUser::fromRequest($request), $request->user());

            return response()->json(['id' => $request->user()?->accountId]);
        });
        $this->getJson('/api/central-protected', ['Authorization' => 'Bearer '.$this->token()])
            ->assertOk()->assertExactJson(['id' => 17]);
    }

    public function test_central_authentication_protects_health_and_unknown_api_paths(): void
    {
        foreach (['/api/health', '/api/unknown-protected-path', '/api'] as $path) {
            $this->getJson($path)->assertStatus(401)
                ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
                ->assertContent('Missing Authorization Bearer token');
        }
    }

    #[DataProvider('publicPaths')]
    public function test_central_authentication_allows_public_endpoints_and_options_without_alias(string $method, string $path): void
    {
        Route::match([$method], $path, fn () => response()->json(['public' => true]));
        $this->json($method, $path)->assertOk()->assertExactJson(['public' => true]);
        $this->json($method, $path, [], ['Authorization' => 'Bearer broken'])
            ->assertOk()->assertExactJson(['public' => true]);
    }

    #[DataProvider('invalidParsedTokens')]
    public function test_invalid_parsed_tokens_return_401(array $claims, array $header): void
    {
        $token = JWT::encode(array_replace(['sub' => 'patient@example.test', 'accountId' => 17,
            'roleName' => 'PATIENT', 'exp' => time() + 60], $claims),
            hash('sha256', 'local-test-secret', true), 'HS256', null, $header);
        $this->getJson('/api/principal', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
            ->assertContent('Invalid or expired token');
    }

    public static function invalidParsedTokens(): iterable
    {
        yield 'unsupported critical extension' => [[], ['crit' => ['custom-required'], 'custom-required' => true]];
        yield 'missing critical extension' => [[], ['crit' => ['custom-required']]];
        yield 'array subject' => [['sub' => ['invalid']], []];
        yield 'object subject' => [['sub' => (object) ['email' => 'patient@example.test']], []];
        yield 'numeric subject' => [['sub' => 17], []];
        yield 'boolean subject' => [['sub' => true], []];
        yield 'future iat with invalid subject' => [['sub' => ['invalid'], 'iat' => time() + 3600, 'exp' => time() + 7200], []];
        yield 'future iat with critical extension' => [['iat' => time() + 3600, 'exp' => time() + 7200], ['crit' => ['custom-required'], 'custom-required' => true]];
    }

    public function test_principal_is_not_retained_between_requests(): void
    {
        $this->getJson('/api/principal', ['Authorization' => 'Bearer '.$this->token()])->assertOk();
        $this->getJson('/api/principal', ['Authorization' => ''])->assertStatus(401);
        $this->assertNull(CurrentUser::fromRequest());
    }

    public function test_downstream_errors_still_use_existing_exception_renderer(): void
    {
        Route::get('/api/jwt-business-error', fn () => throw new BusinessException('business error'))->middleware('auth.jwt');
        $this->getJson('/api/jwt-business-error', ['Authorization' => 'Bearer '.$this->token()])
            ->assertStatus(400)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent('business error');
    }
}
