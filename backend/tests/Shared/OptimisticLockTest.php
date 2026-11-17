<?php

namespace Tests\Shared;

use App\Shared\Exceptions\OptimisticLockException;
use App\Shared\Http\Middleware\JwtFilter;
use App\Shared\Support\OptimisticLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OptimisticLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(function (): void {
            foreach (self::entities() as [$table]) {
                DB::statement("DROP TABLE IF EXISTS $table");
            }
        });

        foreach (self::entities() as [$table, $key, $field]) {
            DB::statement("CREATE TABLE $table ($key INTEGER PRIMARY KEY, $field TEXT, created_at TEXT, updated_at TEXT, version_number BIGINT NOT NULL DEFAULT 0)");
            DB::table($table)->insert([
                [$key => 1, $field => 'original', 'created_at' => '2026-11-15 08:00:00'],
                [$key => 2, $field => 'other', 'created_at' => '2026-11-15 08:00:00'],
            ]);
        }
    }

    public static function entities(): iterable
    {
        yield 'Appointment' => ['appointment', 'appointment_id', 'initial_symptoms'];
        yield 'MedicalRecord' => ['medical_record', 'med_record_id', 'clinical_notes'];
        yield 'DoctorSchedule' => ['doctor_schedule', 'doctor_schedule_id', 'note'];
    }

    public function test_initial_version_matches_java_and_schema_default(): void
    {
        $this->assertSame(0, OptimisticLock::INITIAL_VERSION);
        $this->assertSame(0, DB::table('medical_record')->where('med_record_id', 1)->value('version_number'));
    }

    #[DataProvider('entities')]
    public function test_update_changes_only_the_target_record_and_returns_next_version(string $table, string $key, string $field): void
    {
        $version = OptimisticLock::update($table, $key, 1, 0, [
            $field => 'changed', 'updated_at' => '2026-11-15 09:00:00',
        ]);

        $this->assertSame(1, $version);
        $record = DB::table($table)->where($key, 1)->first();
        $this->assertSame('changed', $record->$field);
        $this->assertSame(1, $record->version_number);
        $this->assertSame('2026-11-15 09:00:00', $record->updated_at);
        $this->assertSame('2026-11-15 08:00:00', $record->created_at);
        $other = DB::table($table)->where($key, 2)->first();
        $this->assertSame('other', $other->$field);
        $this->assertSame(0, $other->version_number);
    }

    #[DataProvider('versions')]
    public function test_version_increment_matches_java_long(int $current, int $next): void
    {
        DB::table('medical_record')->where('med_record_id', 1)->update(['version_number' => $current]);

        $this->assertSame($next, OptimisticLock::update('medical_record', 'med_record_id', 1, $current, ['clinical_notes' => 'changed']));
        $this->assertSame($next, DB::table('medical_record')->where('med_record_id', 1)->value('version_number'));
    }

    public static function versions(): iterable
    {
        yield 'zero' => [0, 1];
        yield 'existing version' => [7, 8];
        yield 'above signed integer' => [2147483647, 2147483648];
        yield 'negative Long' => [-1, 0];
        yield 'last positive Long' => [PHP_INT_MAX - 1, PHP_INT_MAX];
        yield 'Long overflow' => [PHP_INT_MAX, PHP_INT_MIN];
    }

    public function test_two_snapshots_of_the_same_version_allow_only_one_update(): void
    {
        $first = DB::table('medical_record')->where('med_record_id', 1)->first();
        $second = DB::table('medical_record')->where('med_record_id', 1)->first();

        OptimisticLock::update('medical_record', 'med_record_id', 1, $first->version_number, [
            'clinical_notes' => 'first writer', 'updated_at' => '2026-11-15 09:00:00',
        ]);

        try {
            OptimisticLock::update('medical_record', 'med_record_id', 1, $second->version_number, [
                'clinical_notes' => 'second writer', 'updated_at' => '2026-11-15 10:00:00',
            ]);
            $this->fail('The stale snapshot must not overwrite the first writer.');
        } catch (OptimisticLockException) {
            $record = DB::table('medical_record')->where('med_record_id', 1)->first();
            $this->assertSame('first writer', $record->clinical_notes);
            $this->assertSame('2026-11-15 09:00:00', $record->updated_at);
            $this->assertSame(1, $record->version_number);
        }
    }

    public function test_future_version_is_also_a_conflict(): void
    {
        try {
            OptimisticLock::update('medical_record', 'med_record_id', 1, 9, ['clinical_notes' => 'wrong']);
            $this->fail('A future version must not match the persisted version.');
        } catch (OptimisticLockException) {
            $this->assertSame('original', DB::table('medical_record')->where('med_record_id', 1)->value('clinical_notes'));
            $this->assertSame(0, DB::table('medical_record')->where('med_record_id', 1)->value('version_number'));
        }
    }

    public function test_record_deleted_after_loading_causes_conflict_and_is_not_reinserted(): void
    {
        $version = DB::table('medical_record')->where('med_record_id', 1)->value('version_number');
        DB::table('medical_record')->where('med_record_id', 1)->delete();

        try {
            OptimisticLock::update('medical_record', 'med_record_id', 1, $version, ['clinical_notes' => 'changed']);
            $this->fail('A deleted record must cause an optimistic conflict.');
        } catch (OptimisticLockException) {
            $this->assertFalse(DB::table('medical_record')->where('med_record_id', 1)->exists());
        }
    }

    public function test_unknown_primary_key_causes_conflict(): void
    {
        $this->expectException(OptimisticLockException::class);

        OptimisticLock::update('medical_record', 'med_record_id', 999, 0, ['clinical_notes' => 'changed']);
    }

    public function test_version_is_managed_by_the_helper(): void
    {
        $this->assertSame(1, OptimisticLock::update('medical_record', 'med_record_id', 1, 0, [
            'clinical_notes' => null, 'version_number' => 99,
        ]));
        $record = DB::table('medical_record')->where('med_record_id', 1)->first();
        $this->assertNull($record->clinical_notes);
        $this->assertSame(1, $record->version_number);
    }

    public function test_no_dirty_columns_do_not_issue_an_update_or_bump_version(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame(0, OptimisticLock::update('medical_record', 'med_record_id', 1, 0, []));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(0, DB::table('medical_record')->where('med_record_id', 1)->value('version_number'));
    }

    public function test_update_is_one_statement_with_primary_key_and_version_predicates(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        OptimisticLock::update('medical_record', 'med_record_id', 1, 0, ['clinical_notes' => 'changed']);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('update ', $queries[0]['query']);
        $grammar = DB::connection()->getQueryGrammar();
        $this->assertStringContainsString(
            'where '.$grammar->wrap('med_record_id').' = ? and '.$grammar->wrap('version_number').' = ?',
            $queries[0]['query'],
        );
        $this->assertSame(['changed', 1, 1, 0], $queries[0]['bindings']);
    }

    public function test_outer_transaction_rolls_back_successful_updates(): void
    {
        try {
            DB::transaction(function (): void {
                OptimisticLock::update('medical_record', 'med_record_id', 1, 0, ['clinical_notes' => 'changed']);
                throw new RuntimeException('later operation failed');
            });
            $this->fail('The transaction must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('later operation failed', $exception->getMessage());
            $record = DB::table('medical_record')->where('med_record_id', 1)->first();
            $this->assertSame('original', $record->clinical_notes);
            $this->assertSame(0, $record->version_number);
        }
    }

    public function test_conflict_rolls_back_earlier_writes_in_the_business_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                OptimisticLock::update('appointment', 'appointment_id', 1, 0, ['initial_symptoms' => 'changed']);
                OptimisticLock::update('medical_record', 'med_record_id', 1, 5, ['clinical_notes' => 'stale']);
            });
            $this->fail('The stale update must abort the business transaction.');
        } catch (OptimisticLockException) {
            $record = DB::table('appointment')->where('appointment_id', 1)->first();
            $this->assertSame('original', $record->initial_symptoms);
            $this->assertSame(0, $record->version_number);
        }
    }

    public function test_explicit_connection_participates_in_its_own_transaction(): void
    {
        config(['database.connections.optimistic_test' => config('database.connections.'.DB::getDefaultConnection())]);
        $connection = DB::connection('optimistic_test');
        try {
            $connection->statement('CREATE TEMPORARY TABLE medical_record (med_record_id INTEGER PRIMARY KEY, clinical_notes TEXT, version_number BIGINT NOT NULL DEFAULT 0)');
            $connection->table('medical_record')->insert(['med_record_id' => 1, 'clinical_notes' => 'separate']);

            $connection->transaction(function (ConnectionInterface $connection): void {
                $this->assertSame(1, OptimisticLock::update('medical_record', 'med_record_id', 1, 0, ['clinical_notes' => 'changed'], $connection));
            });

            $this->assertSame('changed', $connection->table('medical_record')->where('med_record_id', 1)->value('clinical_notes'));
            $this->assertSame('original', DB::table('medical_record')->where('med_record_id', 1)->value('clinical_notes'));
        } finally {
            DB::purge('optimistic_test');
        }
    }

    public function test_database_errors_are_not_misreported_as_version_conflicts(): void
    {
        $this->expectException(QueryException::class);

        OptimisticLock::update('medical_record', 'med_record_id', 1, 0, ['unknown_column' => 'value']);
    }

    public function test_matching_client_version_is_accepted(): void
    {
        OptimisticLock::assertVersion(0, 0);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('mismatchedVersions')]
    public function test_mismatched_client_version_throws_the_existing_exception(?int $requested, int $loaded): void
    {
        $this->expectException(OptimisticLockException::class);

        OptimisticLock::assertVersion($requested, $loaded);
    }

    public static function mismatchedVersions(): iterable
    {
        yield 'stale' => [0, 1];
        yield 'future' => [2, 1];
        yield 'null' => [null, 0];
    }

    public function test_conflict_uses_java_409_text_response(): void
    {
        $this->withoutMiddleware(JwtFilter::class);
        Route::put('/api/optimistic-lock-test', fn () => OptimisticLock::update(
            'medical_record', 'med_record_id', 1, 1, ['clinical_notes' => 'changed'],
        ));

        $this->putJson('/api/optimistic-lock-test')
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
            ->assertContent('Data was changed by another transaction, please try again');
    }
}
