<?php

namespace Tests\Shared;

use App\Shared\Exceptions\AccessDeniedException;
use App\Shared\Exceptions\BusinessException;
use App\Shared\Exceptions\DataIntegrityViolationException;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Exceptions\HttpMessageNotReadableException;
use App\Shared\Exceptions\OptimisticLockException;
use App\Shared\Exceptions\ResourceNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

class ExceptionHandlingTest extends TestCase
{
    #[DataProvider('errorResponses')]
    public function test_api_errors_preserve_status_type_and_message(
        Throwable $exception, int $status, string $body, string $accept, bool $debug
    ): void {
        config(['app.debug' => $debug]);
        Route::get('/api/exception-test', fn () => throw $exception);

        $this->get('/api/exception-test', ['Accept' => $accept])
            ->assertStatus($status)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
            ->assertContent($body);
    }

    public static function errorResponses(): iterable
    {
        $cases = [
            [new ResourceNotFoundException('Patient khÃ´ng tá»“n táº¡i'), 404, 'Patient khÃ´ng tá»“n táº¡i'],
            [new DuplicateResourceException('Email already exists'), 409, 'Email already exists'],
            [new BusinessException('Appointment is not pending'), 400, 'Appointment is not pending'],
            [new BusinessException(''), 400, ''],
            [new DataIntegrityViolationException('private database detail'), 400,
                'Request violates database constraints. Please check unique fields, enum values and data types'],
            [new AccessDeniedException('private denial reason'), 403,
                'You do not have permission to perform this action'],
            [new AuthorizationException('private gate reason'), 403,
                'You do not have permission to perform this action'],
            [new OptimisticLockException('version detail'), 409,
                'Data was changed by another transaction, please try again'],
            [new HttpMessageNotReadableException('parse detail'), 400,
                'Invalid request body. Please check JSON syntax and date format yyyy-MM-dd'],
            [new RuntimeException('Unexpected detail'), 500, 'An unexpected error occurred: Unexpected detail'],
            [new RuntimeException, 500, 'An unexpected error occurred: '],
        ];

        foreach ($cases as $index => $case) {
            foreach (['application/json', 'text/html', '*/*'] as $accept) {
                foreach ([false, true] as $debug) {
                    yield $index.' '.$accept.' '.($debug ? 'debug' : 'production') => [...$case, $accept, $debug];
                }
            }
        }
    }

    public function test_authorization_with_explicit_forbidden_status_uses_java_response(): void
    {
        Route::get('/api/explicit-authorization-denial', fn () => throw (new AuthorizationException('private authorization detail'))->withStatus(403));

        $this->getJson('/api/explicit-authorization-denial')
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
            ->assertContent('You do not have permission to perform this action');
    }

    #[DataProvider('ordinaryHttpExceptions')]
    public function test_other_http_errors_keep_the_existing_renderer(Throwable $exception, int $status, string $message): void
    {
        config(['app.debug' => false]);
        Route::get('/api/ordinary-http-error', fn () => throw $exception);

        $this->getJson('/api/ordinary-http-error')->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => $message]);
    }

    public static function ordinaryHttpExceptions(): iterable
    {
        yield 'plain http 403' => [new HttpException(403, 'HTTP denial'), 403, 'HTTP denial'];
        yield 'http 403 subtype without authorization cause' => [new AccessDeniedHttpException('HTTP denial'), 403, 'HTTP denial'];
        yield 'http 403 with unrelated cause' => [new HttpException(403, 'HTTP denial', new RuntimeException('unrelated')), 403, 'HTTP denial'];
        yield 'authorization with explicit 404' => [(new AuthorizationException('private detail'))->withStatus(404), 404, 'Not Found'];
    }

    public function test_validation_is_a_flat_map_with_last_message_and_java_nested_paths(): void
    {
        Route::post('/api/validation-test', fn () => throw ValidationException::withMessages([
            'email' => ['must not be blank', 'must be a well-formed email address'],
            'details.0.quantity' => ['must be greater than 0'],
            'groups.1.details.2.quantity' => ['must not be null'],
        ]));

        $this->post('/api/validation-test', [], ['Accept' => 'text/html'])
            ->assertStatus(400)->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'email' => 'must be a well-formed email address',
                'details[0].quantity' => 'must be greater than 0',
                'groups[1].details[2].quantity' => 'must not be null',
            ]);
    }

    public function test_empty_validation_errors_are_an_object(): void
    {
        Route::get('/api/validation-test', fn () => throw ValidationException::withMessages([]));
        $this->getJson('/api/validation-test')->assertStatus(400)->assertContent('{}');
    }

    #[DataProvider('databaseErrors')]
    public function test_database_errors_are_classified_by_sqlstate_and_driver_code(
        string $state, int $code, bool $constraint
    ): void {
        $previous = new PDOException('database failure');
        $previous->errorInfo = [$state, $code, 'database failure'];
        $exception = new QueryException('custom_connection', 'test query', [], $previous);
        Route::get('/api/database-error', fn () => throw $exception);

        $this->getJson('/api/database-error')
            ->assertStatus($constraint ? 400 : 500)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
            ->assertContent($constraint
                ? 'Request violates database constraints. Please check unique fields, enum values and data types'
                : 'An unexpected error occurred: '.$exception->getMessage());
    }

    public static function databaseErrors(): iterable
    {
        yield 'unique' => ['23000', 1062, true];
        yield 'foreign key' => ['23000', 1452, true];
        yield 'not null' => ['23000', 1048, true];
        yield 'check' => ['HY000', 3819, true];
        yield 'invalid enum' => ['01000', 1265, true];
        yield 'missing required default' => ['HY000', 1364, true];
        yield 'invalid numeric value' => ['HY000', 1366, true];
        yield 'invalid date' => ['22007', 1292, true];
        yield 'out of range' => ['22003', 1264, true];
        yield 'too long' => ['22001', 1406, true];
        yield 'connection' => ['HY000', 2002, false];
        yield 'syntax' => ['42000', 1064, false];
        yield 'deadlock' => ['40001', 1213, false];
    }

    #[DataProvider('realDatabaseViolations')]
    public function test_real_database_constraint_is_rendered_through_http(?string $name, int $quantity, int $parent): void
    {
        DB::statement('PRAGMA foreign_keys = ON');
        DB::statement('CREATE TABLE exception_parent (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE exception_test (id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL, quantity INTEGER CHECK (quantity > 0), parent_id INTEGER REFERENCES exception_parent(id))');
        DB::insert('INSERT INTO exception_parent (id) VALUES (1)');
        DB::insert('INSERT INTO exception_test (id, name, quantity, parent_id) VALUES (1, ?, 1, 1)', ['duplicate']);
        Route::post('/api/database-write', function () use ($name, $quantity, $parent) {
            DB::insert('INSERT INTO exception_test (id, name, quantity, parent_id) VALUES (2, ?, ?, ?)', [$name, $quantity, $parent]);
        });

        $this->postJson('/api/database-write')->assertStatus(400)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
            ->assertContent('Request violates database constraints. Please check unique fields, enum values and data types');
    }

    public static function realDatabaseViolations(): iterable
    {
        yield 'unique' => ['duplicate', 1, 1];
        yield 'not null' => [null, 1, 1];
        yield 'check' => ['new', 0, 1];
        yield 'foreign key' => ['new', 1, 99];
    }

    public function test_successful_json_and_no_content_responses_are_preserved(): void
    {
        Route::get('/api/success-test', fn () => response()->json(['id' => 1, 'note' => null], 201));
        Route::delete('/api/success-test', fn () => response()->noContent());

        $this->getJson('/api/success-test')->assertCreated()
            ->assertHeader('Content-Type', 'application/json')->assertExactJson(['id' => 1, 'note' => null]);
        $this->deleteJson('/api/success-test')->assertNoContent();
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_explicit_response_exceptions_and_routing_errors_are_preserved(): void
    {
        Route::get('/api/explicit-response', fn () => throw new HttpResponseException(response('explicit', 418)));
        $this->getJson('/api/explicit-response')->assertStatus(418)->assertContent('explicit');
        $this->getJson('/api/missing-route')->assertNotFound();
    }

    public function test_non_api_exceptions_keep_the_existing_renderer(): void
    {
        Route::get('/web-exception-test', fn () => throw new BusinessException('web detail'));
        $this->get('/web-exception-test')->assertStatus(500)
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
