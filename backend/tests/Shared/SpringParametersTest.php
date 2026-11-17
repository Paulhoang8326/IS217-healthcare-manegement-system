<?php

namespace Tests\Shared;

use App\Shared\Http\Requests\SpringParameters;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpringParametersTest extends TestCase
{
    #[DataProvider('numbers')]
    public function test_long_query_conversion_matches_spring_705(?string $input, ?int $expected): void
    {
        $request = Request::create('/api/test'.($input === null ? '' : '?doctorId='.rawurlencode($input)));
        $this->assertSame($expected, SpringParameters::queryLong($request, 'doctorId'));
    }

    public static function numbers(): iterable
    {
        yield [null, null];
        yield ['', null];
        yield [' ', null];
        yield [' 1 2 ', 12];
        yield ['+2', 2];
        yield ['0x10', 16];
        yield ['#10', 16];
        yield ['010', 10];
        yield ['１２', 12];
        yield ['-9223372036854775808', PHP_INT_MIN];
        yield ['9223372036854775807', PHP_INT_MAX];
        yield ['-0x8000000000000000', PHP_INT_MIN];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_long_keeps_the_exact_spring_error(string $input, string $cause): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Method parameter 'doctorId': Failed to convert value of type 'java.lang.String' to required type 'java.lang.Long'; ".$cause);
        SpringParameters::queryLong(Request::create('/api/test?doctorId='.rawurlencode($input)), 'doctorId');
    }

    public static function invalidNumbers(): iterable
    {
        yield ['abc', 'For input string: "abc"'];
        yield [' a b ', 'For input string: "ab"'];
        yield ['9223372036854775808', 'For input string: "9223372036854775808"'];
        yield ['0xGG', 'For input string: "GG" under radix 16'];
        yield ['0x8000000000000000', 'For input string: "8000000000000000" under radix 16'];
        yield ['0x-1', 'Sign character in wrong position'];
    }

    public function test_repeated_query_uses_first_value_and_brackets_are_not_parameter_aliases(): void
    {
        $this->assertSame(2, SpringParameters::queryLong(Request::create('/api/test?doctorId=2&doctorId=4'), 'doctorId'));
        $this->assertNull(SpringParameters::queryLong(Request::create('/api/test?doctorId[]=2'), 'doctorId'));
    }

    public function test_empty_path_variable_keeps_spring_missing_variable_message(): void
    {
        $this->expectExceptionMessage("Required URI template variable 'id' for method parameter type Long is present but converted to null");
        SpringParameters::pathLong(' ');
    }

    public function test_date_query_accepts_iso_offset_without_changing_the_date(): void
    {
        $this->assertSame('2026-11-17', SpringParameters::queryDate(Request::create('/api/test?date=2026-11-17%2B07%3A00'), 'date'));
        $this->assertNull(SpringParameters::queryDate(Request::create('/api/test?date='), 'date'));
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_date_keeps_annotation_in_spring_error(string $input): void
    {
        $this->expectExceptionMessage("Method parameter 'date': Failed to convert value of type 'java.lang.String' to required type 'java.time.LocalDate'; Failed to convert from type [java.lang.String] to type [@org.springframework.format.annotation.DateTimeFormat java.time.LocalDate] for value [$input]");
        SpringParameters::queryDate(Request::create('/api/test?date='.rawurlencode($input)), 'date');
    }

    public static function invalidDates(): iterable
    {
        yield ['2026-02-30'];
        yield ['17/11/2026'];
        yield [' 2026-11-17 '];
    }

    public function test_enum_conversion_trims_without_changing_case(): void
    {
        $javaType = 'com.healthcare.backend.entity.enums.AppointmentStatus';
        $this->assertSame('CONFIRMED', SpringParameters::queryEnum(Request::create('/api/test?status=%20CONFIRMED%20'), 'status', $javaType, ['CONFIRMED']));
        $this->expectExceptionMessage("Method parameter 'status': Failed to convert value of type 'java.lang.String' to required type '$javaType'; Failed to convert from type [java.lang.String] to type [$javaType] for value [ pending ]");
        SpringParameters::queryEnum(Request::create('/api/test?status=%20pending%20'), 'status', $javaType, ['PENDING']);
    }
}
