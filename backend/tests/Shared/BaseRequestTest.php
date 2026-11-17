<?php

namespace Tests\Shared;

use App\Shared\Http\Requests\BaseRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BaseRequestTest extends TestCase
{
    private const PARSE_ERROR = 'Invalid request body. Please check JSON syntax and date format yyyy-MM-dd';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00'));
        Route::post('/api/auth/base-schema', fn (SchemaValidationFixture $request) => response()->json($request->validated()));
        Route::post('/api/auth/base-prescription', fn (PrescriptionValidationFixture $request) => response()->json($request->safe()->all()));
        Route::post('/api/auth/base-no-valid', fn (NoValidValidationFixture $request) => response()->json($request->validated()));
        Route::post('/api/auth/base-no-cascade', fn (NoCascadeValidationFixture $request) => response()->json($request->validated()));
        Route::post('/api/auth/base-untyped', fn (Request $request) => response()->json(['called' => true]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function validBody(array $overrides = []): array
    {
        return array_replace(['name' => 'ok', 'tags' => ['x']], $overrides);
    }

    public function test_valid_body_is_typed_and_unknown_fields_are_ignored(): void
    {
        $this->postJson('/api/auth/base-schema', $this->validBody([
            'name' => '  keep spaces  ', 'note' => '', 'quantity' => '17', 'temperature' => '37.5',
            'date' => '2026-11-07', 'shift' => 'MORNING', 'ids' => [1, '2'],
            'unknown' => ['date' => 'not a date', 'number' => 'bad'],
        ]))->assertOk()->assertJsonPath('name', '  keep spaces  ')->assertJsonPath('note', '')
            ->assertJsonPath('quantity', 17)->assertJsonPath('temperature', 37.5)
            ->assertJsonPath('date', '2026-11-07')->assertJsonPath('shift', 'MORNING')
            ->assertJsonPath('ids', [1, 2])->assertJsonMissingPath('unknown');
    }

    #[DataProvider('validationFailures')]
    public function test_validation_returns_java_messages(string $field, mixed $value, string $message): void
    {
        $this->postJson('/api/auth/base-schema', $this->validBody([$field => $value]))
            ->assertStatus(400)->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([$field => $message]);
    }

    public static function validationFailures(): iterable
    {
        yield 'blank' => ['name', " \t\n", 'must not be blank'];
        yield 'unicode whitespace' => ['name', "\u{2003}", 'must not be blank'];
        yield 'size default' => ['name', str_repeat('x', 101), 'size must be between 0 and 100'];
        yield 'size uses UTF16' => ['name', str_repeat("\u{1F600}", 51), 'size must be between 0 and 100'];
        yield 'email' => ['email', 'invalid@', 'must be a well-formed email address'];
        yield 'pattern' => ['password', 'weak', 'must match "^(?=.*[A-Z])(?=.*[0-9]).{8,}$"'];
        yield 'past' => ['past', '2026-11-07', 'must be a past date'];
        yield 'past or present' => ['date', '2026-11-08', 'must be a date in the past or in the present'];
        yield 'future or present' => ['future', '2026-11-06', 'must be a date in the present or in the future'];
        yield 'positive' => ['capacity', 0, 'must be greater than 0'];
        yield 'max' => ['capacity', 100000, 'must be less than or equal to 99999'];
        yield 'min' => ['quantity', 0, 'must be greater than or equal to 1'];
        yield 'decimal min exclusive' => ['amount', '0.00', 'must be greater than 0.00'];
        yield 'decimal min inclusive' => ['coverage', '-0.00000000000000000001', 'must be greater than or equal to 0'];
        yield 'decimal max inclusive' => ['coverage', '100.00000000000000000001', 'must be less than or equal to 100'];
        yield 'not empty' => ['tags', [], 'must not be empty'];
    }

    #[DataProvider('malformedBodies')]
    public function test_parse_errors_are_text_and_precede_validation(string $raw, bool $parsed = false): void
    {
        $response = $this->call('POST', '/api/auth/base-schema', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw);
        if ($parsed) {
            $response->assertStatus(400)->assertHeader('Content-Type', 'application/json')
                ->assertExactJson(['name' => 'must not be blank', 'tags' => 'must not be empty']);
        } else {
            $response->assertStatus(400)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent(self::PARSE_ERROR);
        }
    }

    public static function malformedBodies(): iterable
    {
        foreach (['', '{', '{"name":}', 'null', '[]', '"text"', '17', 'true', '{"name":"ok",}',
            '{"name":[],"tags":["x"]}', '{"quantity":"abc"}', '{"quantity":true}',
            '{"quantity":2147483648}', '{"id":"9223372036854775808"}',
            '{"amount":"not numeric"}', '{"temperature":{}}', '{"shift":"morning"}',
            '{"shift":"INVALID"}', '{"shift":{}}', '{"shift":99}', '{"shift":0.5}',
            '{"date":"2026-02-30"}', '{"date":"2025-02-29"}', '{"date":"07/11/2026"}',
            '{"date":"2026-11-07T12:00:00"}', '{"date":20261107}',
            '{"scheduledAt":"2026-11-07T24:00:00"}', '{"scheduledAt":"2026-11-07T12:00:00Z"}',
            '{"ids":[1,"abc"]}', '{"ids":{}}', '{"tags":[{}]}',
            '{"name":"","date":"invalid"}', '{"unknown":{"bad":}}'] as $index => $raw) {
            $parsed = in_array($raw, ['{"date":"2026-11-07T12:00:00"}', '{"scheduledAt":"2026-11-07T12:00:00Z"}'], true);
            yield 'body '.$index => [$raw, $parsed];
        }
    }

    public function test_enum_names_and_ordinals_follow_jackson(): void
    {
        foreach (['MORNING', 0, '0'] as $value) {
            $this->postJson('/api/auth/base-schema', $this->validBody(['shift' => $value]))
                ->assertOk()->assertJsonPath('shift', 'MORNING');
        }
        foreach (['AFTERNOON', 1, '1'] as $value) {
            $this->postJson('/api/auth/base-schema', $this->validBody(['shift' => $value]))
                ->assertOk()->assertJsonPath('shift', 'AFTERNOON');
        }
    }

    #[DataProvider('p2EmptyContainers')]
    public function test_p2_empty_container_strings_are_parse_errors(mixed $details): void
    {
        $body = $this->prescription();
        $body['details'] = $details;
        $this->postJson('/api/auth/base-prescription', $body)->assertStatus(400)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent(self::PARSE_ERROR);
    }

    public static function p2EmptyContainers(): iterable
    {
        yield 'empty list' => [''];
        yield 'blank list' => ['   '];
        yield 'empty nested object' => [['']];
        yield 'blank nested object' => [['   ']];
    }

    #[DataProvider('p2LongStrings')]
    public function test_p2_long_unknown_json_strings_parse_normally(string $value): void
    {
        $this->postJson('/api/auth/base-schema', $this->validBody(['unknown' => $value]))
            ->assertOk()->assertJsonMissingPath('unknown');
    }

    public static function p2LongStrings(): iterable
    {
        yield '16000 characters' => [str_repeat('x', 16000)];
        yield '300000 characters' => [str_repeat('x', 300000)];
        yield 'long escapes and quoted numbers' => [str_repeat('"12.00"\\1e2', 16000)];
    }

    public function test_p2_long_known_string_reaches_size_validation(): void
    {
        $body = $this->prescription();
        $body['note'] = str_repeat('x', 16000);
        $this->postJson('/api/auth/base-prescription', $body)->assertStatus(400)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['note' => 'Note must not exceed 1000 characters']);
    }

    #[DataProvider('p2ValidDates')]
    public function test_p2_jackson_date_formats(string $field, mixed $input, mixed $expected): void
    {
        $this->postJson('/api/auth/base-schema', $this->validBody([$field => $input]))
            ->assertOk()->assertJsonPath($field, $expected);
    }

    public static function p2ValidDates(): iterable
    {
        yield 'date array' => ['date', [2020, 11, 7], '2020-11-07'];
        yield 'date empty array' => ['date', [], null];
        yield 'date from datetime' => ['date', '2020-11-07T10:20:30', '2020-11-07'];
        yield 'date from datetime Z' => ['date', '2020-11-07T10:20:30Z', '2020-11-07'];
        yield 'date from datetime with fraction' => ['date', '2020-11-07T10:20:30.123456789', '2020-11-07'];
        yield 'date leap array' => ['date', [2024, 2, 29], '2024-02-29'];
        yield 'datetime Z' => ['scheduledAt', '2026-11-07T12:00:00Z', '2026-11-07T12:00:00'];
        yield 'datetime Z fraction' => ['scheduledAt', '2026-11-07T12:00:00.123456789Z', '2026-11-07T12:00:00.123456789'];
        yield 'datetime array without seconds' => ['scheduledAt', [2026, 11, 7, 12, 0], '2026-11-07T12:00:00'];
        yield 'datetime array with seconds' => ['scheduledAt', [2026, 11, 7, 12, 0, 1], '2026-11-07T12:00:01'];
        yield 'datetime array with nanos' => ['scheduledAt', [2026, 11, 7, 12, 0, 1, 123400000], '2026-11-07T12:00:01.1234'];
    }

    #[DataProvider('p2InvalidDateBoundaries')]
    public function test_p2_invalid_date_boundaries_still_fail_parsing(string $field, mixed $input): void
    {
        $this->postJson('/api/auth/base-schema', $this->validBody([$field => $input]))
            ->assertStatus(400)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent(self::PARSE_ERROR);
    }

    public static function p2InvalidDateBoundaries(): iterable
    {
        yield ['date', [2025, 2, 29]];
        yield ['date', [2020, 11, 7, 0]];
        yield ['date', [2020, '11', 7]];
        yield ['date', [2020, 13, 7]];
        yield ['date', '2020-11-07T25:00:00'];
        yield ['scheduledAt', [2026, 11, 7]];
        yield ['scheduledAt', [2026, 11, 7, 12, 0, '1']];
        yield ['scheduledAt', [2026, 11, 7, 12, 0, 0, 1000000000]];
        yield ['scheduledAt', [2026, 11, 7, 12, 0, 0, -1]];
        yield ['scheduledAt', [2026, 11, 7, 24, 0]];
        yield ['scheduledAt', '2026-11-07T12:00:00+00:00'];
        yield ['scheduledAt', '2026-11-07T12:00:00ZZ'];
    }

    #[DataProvider('p2LeadingZeroEnums')]
    public function test_p2_enum_leading_zero_is_not_an_ordinal(string $input): void
    {
        $this->postJson('/api/auth/base-schema', $this->validBody(['shift' => $input]))
            ->assertStatus(400)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent(self::PARSE_ERROR);
    }

    public static function p2LeadingZeroEnums(): iterable
    {
        yield ['00'];
        yield ['01'];
        yield ['001'];
        yield ['0001'];
    }

    #[DataProvider('p2NumberStrings')]
    public function test_p2_number_to_string_keeps_the_number_token(string $number): void
    {
        $raw = '{"name":'.$number.',"tags":["x"]}';
        $this->call('POST', '/api/auth/base-schema', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
            ->assertOk()->assertJsonPath('name', $number);
    }

    public static function p2NumberStrings(): iterable
    {
        yield ['1.0'];
        yield ['1.00'];
        yield ['1e+02'];
        yield ['1E2'];
        yield ['-0.0'];
        yield ['-0'];
        yield ['12345678901234567890123456789'];
    }

    #[DataProvider('p2InvalidScales')]
    public function test_p2_bigdecimal_scale_overflow_is_a_parse_error(string $value, bool $quoted): void
    {
        $raw = '{"name":"ok","tags":["x"],"amount":'.($quoted ? json_encode($value) : $value).'}';
        $this->call('POST', '/api/auth/base-schema', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
            ->assertStatus(400)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent(self::PARSE_ERROR);
    }

    public static function p2InvalidScales(): iterable
    {
        foreach (['1e-2147483648', '1.0e-2147483647', '0.0e-2147483647', '1.000e-2147483645'] as $value) {
            yield $value.' number' => [$value, false];
            yield $value.' string' => [$value, true];
        }
    }

    #[DataProvider('p2ValidScales')]
    public function test_p2_bigdecimal_valid_scale_boundaries_remain_accepted(string $value): void
    {
        $raw = '{"name":"ok","tags":["x"],"amount":'.$value.'}';
        $this->call('POST', '/api/auth/base-schema', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
            ->assertOk()->assertJsonPath('amount', $value);
    }

    public static function p2ValidScales(): iterable
    {
        yield ['1e-2147483647'];
        yield ['1.0e-2147483646'];
        yield ['1e2147483647'];
        yield ['1.000e-2147483644'];
    }

    public function test_query_cannot_supply_required_body_field(): void
    {
        $this->postJson('/api/auth/base-schema?name=from-query', ['tags' => ['x']])
            ->assertStatus(400)->assertExactJson(['name' => 'must not be blank']);
    }

    public function test_null_and_empty_constraints_are_distinct(): void
    {
        $this->postJson('/api/auth/base-schema', $this->validBody([
            'email' => '', 'password' => null, 'note' => null, 'amount' => null,
            'date' => null, 'quantity' => null,
        ]))->assertOk()->assertJsonPath('email', '')->assertJsonPath('note', null);
        $this->postJson('/api/auth/base-schema', $this->validBody(['name' => "\u{00A0}"]))->assertOk();
    }

    public function test_valid_dates_numeric_conversions_and_decimal_precision(): void
    {
        $raw = '{"name":"ok","tags":["x"],"amount":0.12345678901234567890123456789,"coverage":1e2,"quantity":17.9,"id":"9223372036854775807","date":"2024-02-29","scheduledAt":"2026-11-07T12:00:00.123456789"}';
        $this->call('POST', '/api/auth/base-schema', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
            ->assertOk()->assertJsonPath('amount', '0.12345678901234567890123456789')
            ->assertJsonPath('coverage', '1e2')->assertJsonPath('quantity', 17)->assertJsonPath('id', PHP_INT_MAX)
            ->assertJsonPath('date', '2024-02-29');
    }

    private function prescription(): array
    {
        return ['medicalRecordId' => 1, 'note' => null, 'details' => [[
            'medicineId' => 2, 'dosage' => 'one', 'frequency' => 'daily', 'duration' => 'one week', 'quantity' => 1,
        ]]];
    }

    public function test_nested_validation_uses_java_keys_and_custom_messages(): void
    {
        $body = $this->prescription();
        $body['details'][0]['quantity'] = 0;
        $body['details'][] = ['medicineId' => 3, 'dosage' => '', 'frequency' => 'daily', 'duration' => 'one week', 'quantity' => null];
        $this->postJson('/api/auth/base-prescription', $body)->assertStatus(400)
            ->assertHeader('Content-Type', 'application/json')->assertExactJson([
                'details[0].quantity' => 'Quantity must be greater than or equal to 1',
                'details[1].dosage' => 'Dosage must not be blank',
                'details[1].quantity' => 'Quantity must not be null',
            ]);
    }

    public function test_missing_fields_return_custom_not_null_and_not_empty_messages(): void
    {
        $this->call('POST', '/api/auth/base-prescription', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400)
            ->assertHeader('Content-Type', 'application/json')->assertExactJson([
                'medicalRecordId' => 'Medical record id must not be null',
                'details' => 'Prescription details must not be empty',
            ]);
    }

    public function test_pattern_is_validation_while_enum_is_parsing_and_unicode_message_is_preserved(): void
    {
        $this->postJson('/api/auth/base-schema', $this->validBody(['gender' => 'male']))->assertOk();
        $this->postJson('/api/auth/base-schema', $this->validBody(['gender' => 'UNKNOWN']))
            ->assertStatus(400)->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['gender' => "Gi\u{1EDB}i t\u{00ED}nh ch\u{1EC9} \u{0111}\u{01B0}\u{1EE3}c l\u{00E0} MALE, FEMALE ho\u{1EB7}c OTHER"]);
        $this->postJson('/api/auth/base-schema', $this->validBody(['shift' => 'morning']))
            ->assertStatus(400)->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent(self::PARSE_ERROR);
    }

    public function test_json_strings_with_quotes_backslashes_and_numbers_are_not_rewritten(): void
    {
        $name = 'value "123.456" with \\ and 1e4';
        $this->postJson('/api/auth/base-schema', $this->validBody(['name' => $name]))->assertOk()->assertJsonPath('name', $name);
    }

    public function test_nested_size_message_matches_prescription_detail(): void
    {
        $body = $this->prescription();
        $body['details'][0]['dosage'] = str_repeat('x', 101);
        $this->postJson('/api/auth/base-prescription', $body)->assertStatus(400)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['details[0].dosage' => 'Dosage must not exceed 100 characters']);
    }

    public function test_nested_type_error_precedes_constraints_in_all_fields(): void
    {
        $body = $this->prescription();
        $body['medicalRecordId'] = null;
        $body['details'][0]['quantity'] = 'not a number';
        $this->postJson('/api/auth/base-prescription', $body)->assertStatus(400)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')->assertContent(self::PARSE_ERROR);
    }

    public function test_valid_nested_body_and_unknown_nested_fields(): void
    {
        $body = $this->prescription();
        $body['details'][0]['extra'] = ['invalidDate' => 'bad'];
        $this->postJson('/api/auth/base-prescription', $body)->assertOk()
            ->assertJsonPath('details.0.instruction', null)->assertJsonMissingPath('details.0.extra');
        $body['details'][] = null;
        $this->postJson('/api/auth/base-prescription', $body)->assertOk()->assertJsonPath('details.1', null);
    }

    public function test_no_valid_skips_constraints_but_not_deserialization(): void
    {
        $this->postJson('/api/auth/base-no-valid', ['name' => '', 'quantity' => 0])->assertOk()->assertJsonPath('name', '');
        $this->postJson('/api/auth/base-no-valid', ['quantity' => 'bad'])->assertStatus(400)->assertContent(self::PARSE_ERROR);
        $this->postJson('/api/auth/base-untyped', ['anything' => []])->assertOk();
    }

    public function test_nested_constraints_are_not_applied_without_valid(): void
    {
        $body = $this->prescription();
        $body['details'][0]['quantity'] = 0;
        $this->postJson('/api/auth/base-no-cascade', $body)->assertOk()->assertJsonPath('details.0.quantity', 0);
        $body['details'][0]['quantity'] = 'bad';
        $this->postJson('/api/auth/base-no-cascade', $body)->assertStatus(400)->assertContent(self::PARSE_ERROR);
    }

    #[DataProvider('emailCases')]
    public function test_email_behavior(string $email, bool $valid): void
    {
        $response = $this->postJson('/api/auth/base-schema', $this->validBody(['email' => $email]));
        $valid ? $response->assertOk() : $response->assertStatus(400)->assertExactJson(['email' => 'must be a well-formed email address']);
    }

    public static function emailCases(): iterable
    {
        yield ['a@example.test', true];
        yield ['a@localhost', true];
        yield ['"a b"@example.test', true];
        yield ['a..b@example.test', false];
        yield ['a@example.test.', false];
        yield ['@example.test', false];
        yield ['a@[127.0.0.1]', true];
        yield ['a@[IPv6:::1]', true];
    }

    public function test_validation_does_not_depend_on_accept_or_laravel_locale(): void
    {
        config(['app.locale' => 'vi']);
        app()->setLocale('vi');
        $raw = '{"tags":["x"]}';
        $this->call('POST', '/api/auth/base-schema', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], $raw)
            ->assertStatus(400)->assertHeader('Content-Type', 'application/json')->assertExactJson(['name' => 'must not be blank']);
    }
}

class SchemaValidationFixture extends BaseRequest
{
    protected function validatesBody(): bool
    {
        return true;
    }

    protected function bodyFields(): array
    {
        return [
            'name' => ['type' => 'String', 'constraints' => ['NotBlank', ['name' => 'Size', 'max' => 100]]],
            'tags' => ['type' => 'List', 'items' => 'String', 'constraints' => ['NotEmpty']],
            'note' => ['type' => 'String', 'constraints' => [['name' => 'Size', 'max' => 100]]],
            'email' => ['type' => 'String', 'constraints' => ['Email']],
            'password' => ['type' => 'String', 'constraints' => [['name' => 'Pattern', 'regexp' => '^(?=.*[A-Z])(?=.*[0-9]).{8,}$']]],
            'date' => ['type' => 'LocalDate', 'constraints' => ['PastOrPresent']],
            'past' => ['type' => 'LocalDate', 'constraints' => ['Past']],
            'future' => ['type' => 'LocalDate', 'constraints' => ['FutureOrPresent']],
            'amount' => ['type' => 'BigDecimal', 'constraints' => [['name' => 'DecimalMin', 'value' => '0.00', 'inclusive' => false]]],
            'coverage' => ['type' => 'BigDecimal', 'constraints' => [['name' => 'DecimalMin', 'value' => '0'], ['name' => 'DecimalMax', 'value' => '100']]],
            'capacity' => ['type' => 'Integer', 'constraints' => ['Positive', ['name' => 'Max', 'value' => 99999]]],
            'quantity' => ['type' => 'Integer', 'constraints' => [['name' => 'Min', 'value' => 1]]],
            'id' => 'Long',
            'temperature' => 'Double',
            'gender' => ['type' => 'String', 'constraints' => [['name' => 'Pattern', 'regexp' => '(?i)MALE|FEMALE|OTHER',
                'message' => "Gi\u{1EDB}i t\u{00ED}nh ch\u{1EC9} \u{0111}\u{01B0}\u{1EE3}c l\u{00E0} MALE, FEMALE ho\u{1EB7}c OTHER"]]],
            'shift' => ['type' => 'Enum', 'values' => ['MORNING', 'AFTERNOON']],
            'scheduledAt' => 'LocalDateTime',
            'ids' => ['type' => 'List', 'items' => 'Long'],
        ];
    }
}

class NoValidValidationFixture extends SchemaValidationFixture
{
    protected function validatesBody(): bool
    {
        return false;
    }
}

class PrescriptionValidationFixture extends BaseRequest
{
    protected function validatesBody(): bool
    {
        return true;
    }

    protected function bodyFields(): array
    {
        return [
            'medicalRecordId' => ['type' => 'Long', 'constraints' => [['name' => 'NotNull', 'message' => 'Medical record id must not be null']]],
            'note' => ['type' => 'String', 'constraints' => [['name' => 'Size', 'max' => 1000, 'message' => 'Note must not exceed 1000 characters']]],
            'details' => ['type' => 'List', 'valid' => true, 'constraints' => [['name' => 'NotEmpty', 'message' => 'Prescription details must not be empty']],
                'items' => ['type' => 'Object', 'fields' => [
                    'medicineId' => ['type' => 'Long', 'constraints' => [['name' => 'NotNull', 'message' => 'Medicine id must not be null']]],
                    'dosage' => ['type' => 'String', 'constraints' => [['name' => 'NotBlank', 'message' => 'Dosage must not be blank'], ['name' => 'Size', 'max' => 100, 'message' => 'Dosage must not exceed 100 characters']]],
                    'frequency' => ['type' => 'String', 'constraints' => [['name' => 'NotBlank', 'message' => 'Frequency must not be blank'], ['name' => 'Size', 'max' => 100, 'message' => 'Frequency must not exceed 100 characters']]],
                    'duration' => ['type' => 'String', 'constraints' => [['name' => 'NotBlank', 'message' => 'Duration must not be blank'], ['name' => 'Size', 'max' => 100, 'message' => 'Duration must not exceed 100 characters']]],
                    'quantity' => ['type' => 'Integer', 'constraints' => [['name' => 'NotNull', 'message' => 'Quantity must not be null'], ['name' => 'Min', 'value' => 1, 'message' => 'Quantity must be greater than or equal to 1']]],
                    'instruction' => ['type' => 'String', 'constraints' => [['name' => 'Size', 'max' => 500, 'message' => 'Instruction must not exceed 500 characters']]],
                ]]],
        ];
    }
}

class NoCascadeValidationFixture extends PrescriptionValidationFixture
{
    protected function bodyFields(): array
    {
        $fields = parent::bodyFields();
        $fields['details']['valid'] = false;

        return $fields;
    }
}
