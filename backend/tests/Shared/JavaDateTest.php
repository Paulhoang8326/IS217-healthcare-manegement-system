<?php

namespace Tests\Shared;

use App\Shared\Support\JavaDate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JavaDateTest extends TestCase
{
    public function test_null_dates_remain_json_null(): void
    {
        $response = new JsonResponse([
            'scheduleDate' => JavaDate::localDate(null),
            'paidAt' => JavaDate::localDateTime(null),
        ]);

        $this->assertSame('{"scheduleDate":null,"paidAt":null}', $response->getContent());
    }

    #[DataProvider('dates')]
    public function test_local_fields_are_formatted_without_changing_the_input(
        DateTimeInterface $value, string $date, string $dateTime,
    ): void {
        $before = $value->format('Y-m-d\TH:i:s.uP e');

        $this->assertSame($date, JavaDate::localDate($value));
        $this->assertSame($dateTime, JavaDate::localDateTime($value));
        $this->assertSame($before, $value->format('Y-m-d\TH:i:s.uP e'));
    }

    public static function dates(): iterable
    {
        yield 'leap day' => [new DateTimeImmutable('2000-02-29T12:34:56'), '2000-02-29', '2000-02-29T12:34:56'];
        yield 'midnight includes seconds' => [new DateTime('2026-11-15T00:00:00'), '2026-11-15', '2026-11-15T00:00:00'];
        yield 'fractions do not round into next year' => [new DateTime('2026-12-31T23:59:59.999999'), '2026-12-31', '2026-12-31T23:59:59'];
        yield 'positive offset keeps local day' => [new DateTimeImmutable('2026-01-01T00:30:00+14:00'), '2026-01-01', '2026-01-01T00:30:00'];
        yield 'negative offset keeps local day' => [new DateTime('2026-12-31T23:30:00-12:00'), '2026-12-31', '2026-12-31T23:30:00'];
        yield 'UTC has no suffix' => [new DateTimeImmutable('2026-11-15T12:34:56Z'), '2026-11-15', '2026-11-15T12:34:56'];
        yield 'mutable Carbon' => [new Carbon('2026-11-15T12:34:56.123456', 'Asia/Ho_Chi_Minh'), '2026-11-15', '2026-11-15T12:34:56'];
        yield 'immutable Carbon' => [new CarbonImmutable('2026-11-15T12:34:56.123456', 'Asia/Ho_Chi_Minh'), '2026-11-15', '2026-11-15T12:34:56'];
    }

    public function test_resource_mapping_formats_eloquent_casts_before_json_serialization(): void
    {
        $model = new class extends Model
        {
            protected function casts(): array
            {
                return ['schedule_date' => 'date', 'created_at' => 'datetime', 'paid_at' => 'datetime'];
            }
        };
        $model->setRawAttributes([
            'schedule_date' => '2024-02-29',
            'created_at' => '2026-11-15 12:34:56',
            'paid_at' => null,
        ]);

        $response = new JsonResponse([
            'scheduleDate' => JavaDate::localDate($model->getAttribute('schedule_date')),
            'createdAt' => JavaDate::localDateTime($model->getAttribute('created_at')),
            'paidAt' => JavaDate::localDateTime($model->getAttribute('paid_at')),
        ]);

        $this->assertSame(
            '{"scheduleDate":"2024-02-29","createdAt":"2026-11-15T12:34:56","paidAt":null}',
            $response->getContent(),
        );
    }
}
