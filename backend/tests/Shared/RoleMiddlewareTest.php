<?php

namespace Tests\Shared;

use App\Shared\Auth\JwtServiceImpl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'role-test-secret', 'jwt.access-token-expiration-ms' => 60000]);
        DB::statement('CREATE TABLE role (role_id INTEGER PRIMARY KEY, role_name TEXT)');
        DB::statement('CREATE TABLE account (account_id INTEGER PRIMARY KEY, email TEXT UNIQUE, role_id INTEGER, is_active INTEGER)');
    }

    private function token(?string $role): string
    {
        return (new JwtServiceImpl)->generateToken(17, 'role@example.test', $role);
    }

    #[DataProvider('accessCases')]
    public function test_authorization_matches_java(string $requirement, ?string $role, bool $allowed): void
    {
        Route::get('/api/role-test', fn () => response()->json(['allowed' => true]))->middleware($requirement);
        $response = $this->getJson('/api/role-test', ['Authorization' => 'Bearer '.$this->token($role)]);
        if ($allowed) {
            $response->assertOk()->assertExactJson(['allowed' => true]);
        } else {
            $response->assertStatus(403)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
                ->assertContent('You do not have permission to perform this action');
        }
    }

    public static function accessCases(): iterable
    {
        yield 'short role match' => ['role:DOCTOR', 'DOCTOR', true];
        yield 'short role denied' => ['role:DOCTOR', 'PATIENT', false];
        yield 'short roles OR second match' => ['role:ADMIN,RECEPTIONIST', 'RECEPTIONIST', true];
        yield 'short roles denied' => ['role:ADMIN,RECEPTIONIST', 'TECHNICIAN', false];
        yield 'hasRole' => ['role:hasRole,DOCTOR', 'DOCTOR', true];
        yield 'hasRole wrong role' => ['role:hasRole,DOCTOR', 'PATIENT', false];
        yield 'hasRole with prefix' => ['role:hasRole,ROLE_DOCTOR', 'DOCTOR', true];
        yield 'prefixed principal' => ['role:DOCTOR', 'ROLE_DOCTOR', true];
        yield 'hasAnyRole second match' => ['role:hasAnyRole,DOCTOR,PATIENT', 'PATIENT', true];
        yield 'hasAnyRole denied' => ['role:hasAnyRole,DOCTOR,PATIENT', 'TECHNICIAN', false];
        yield 'hasAuthority exact' => ['role:hasAuthority,ROLE_DOCTOR', 'DOCTOR', true];
        yield 'hasAuthority does not add prefix' => ['role:hasAuthority,DOCTOR', 'DOCTOR', false];
        yield 'hasAuthority exact prefixed principal' => ['role:hasAuthority,ROLE_DOCTOR', 'ROLE_DOCTOR', true];
        yield 'hasAnyAuthority second match' => ['role:hasAnyAuthority,ROLE_DOCTOR,ROLE_NURSE', 'DOCTOR', true];
        yield 'hasAnyAuthority denied' => ['role:hasAnyAuthority,ROLE_DOCTOR,ROLE_NURSE', 'TECHNICIAN', false];
        yield 'hasAnyAuthority does not add prefix' => ['role:hasAnyAuthority,DOCTOR,PATIENT', 'PATIENT', false];
        yield 'authenticated only' => ['role:isAuthenticated', 'PATIENT', true];
        yield 'authenticated without role' => ['role:isAuthenticated', null, true];
        yield 'missing role denied' => ['role:DOCTOR', null, false];
        yield 'case sensitive requirement' => ['role:doctor', 'DOCTOR', false];
        yield 'case sensitive principal' => ['role:ADMIN', 'admin', false];
        yield 'no configured role denies' => ['role', 'ADMIN', false];
        yield 'empty any roles denies' => ['role:hasAnyRole', 'ADMIN', false];
        yield 'empty any authorities denies' => ['role:hasAnyAuthority', 'ADMIN', false];
        yield 'singular role rejects extra arguments' => ['role:hasRole,ADMIN,PATIENT', 'PATIENT', false];
        yield 'singular authority rejects extra arguments' => ['role:hasAuthority,ROLE_ADMIN,ROLE_PATIENT', 'PATIENT', false];
        yield 'authenticated rejects extra arguments' => ['role:isAuthenticated,ADMIN', 'PATIENT', false];

        foreach (['ADMIN', 'RECEPTIONIST', 'DOCTOR', 'PATIENT', 'ACCOUNTANT'] as $authority) {
            yield 'admin inherits '.$authority => ['role:hasAuthority,ROLE_'.$authority, 'ADMIN', true];
            yield 'prefixed admin inherits '.$authority => ['role:'.$authority, 'ROLE_ADMIN', true];
        }
        foreach (['TECHNICIAN', 'PHARMACIST', 'NURSE'] as $authority) {
            yield 'admin does not inherit '.$authority => ['role:'.$authority, 'ADMIN', false];
            yield 'prefixed admin does not inherit '.$authority => ['role:hasAuthority,ROLE_'.$authority, 'ROLE_ADMIN', false];
        }
        foreach (['ADMIN', 'DOCTOR', 'RECEPTIONIST', 'PATIENT', 'ACCOUNTANT', 'PHARMACIST', 'TECHNICIAN'] as $role) {
            yield 'admission doctor or nurse for '.$role => ['role:hasAnyAuthority,ROLE_DOCTOR,ROLE_NURSE', $role, in_array($role, ['ADMIN', 'DOCTOR'], true)];
        }
        yield 'literal nurse authority' => ['role:hasAuthority,ROLE_NURSE', 'NURSE', true];
        yield 'literal nurse role' => ['role:hasRole,NURSE', 'ROLE_NURSE', true];
        yield 'no nurse substitution for technician' => ['role:NURSE', 'TECHNICIAN', false];
        yield 'no nurse substitution for receptionist' => ['role:NURSE', 'RECEPTIONIST', false];
        yield 'technician retains own authority' => ['role:TECHNICIAN', 'TECHNICIAN', true];
        yield 'pharmacist retains own authority' => ['role:PHARMACIST', 'PHARMACIST', true];
    }

    #[DataProvider('unauthorizedCases')]
    public function test_unauthenticated_requests_return_java_401(string $path, ?string $header, string $message): void
    {
        Route::get($path, fn () => response()->noContent())->middleware('role:DOCTOR');
        $this->getJson($path, $header === null ? [] : ['Authorization' => $header])
            ->assertStatus(401)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent($message);
    }

    public static function unauthorizedCases(): iterable
    {
        foreach (['/api/protected-role', '/api/auth/annotated-role'] as $path) {
            yield $path.' missing' => [$path, null, 'Missing Authorization Bearer token'];
            yield $path.' invalid' => [$path, 'Bearer broken', 'Invalid or expired token'];
            yield $path.' wrong scheme' => [$path, 'Basic abc', 'Authorization header must start with Bearer'];
            yield $path.' blank' => [$path, 'Bearer ""', 'Bearer token is blank'];
        }
    }

    public function test_public_is_authenticated_annotation_requires_a_principal(): void
    {
        Route::get('/api/auth/require-authentication', fn () => response()->noContent())->middleware('role:isAuthenticated');
        $this->getJson('/api/auth/require-authentication')->assertStatus(401)->assertContent('Missing Authorization Bearer token');
        $this->getJson('/api/auth/require-authentication', ['Authorization' => 'Bearer '.$this->token(null)])->assertNoContent();
    }

    public function test_database_role_is_used_instead_of_token_role_without_an_extra_lookup(): void
    {
        DB::insert('INSERT INTO role (role_id, role_name) VALUES (1, ?)', ['PATIENT']);
        DB::insert('INSERT INTO account (account_id, email, role_id) VALUES (17, ?, 1)', ['role@example.test']);
        Route::get('/api/doctor-only', fn () => response()->noContent())->middleware('role:DOCTOR');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/doctor-only', ['Authorization' => 'Bearer '.$this->token('DOCTOR')])
            ->assertStatus(403)->assertContent('You do not have permission to perform this action');
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_role_does_not_leak_between_requests(): void
    {
        Route::get('/api/doctor-only', fn () => response()->noContent())->middleware('role:DOCTOR');
        $this->getJson('/api/doctor-only', ['Authorization' => 'Bearer '.$this->token('ADMIN')])->assertNoContent();
        $this->getJson('/api/doctor-only', ['Authorization' => 'Bearer '.$this->token('PATIENT')])->assertStatus(403);
        $this->getJson('/api/doctor-only', ['Authorization' => ''])->assertStatus(401);
    }
}
