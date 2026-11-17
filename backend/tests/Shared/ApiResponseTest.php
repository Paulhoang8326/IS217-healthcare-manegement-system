<?php

namespace Tests\Shared;

use App\Shared\Support\ApiResponse;
use App\Shared\Support\JavaDate;
use App\Shared\Support\SpringPage;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    #[DataProvider('payloads')]
    public function test_success_preserves_payload_types_and_default_message(mixed $data): void
    {
        $body = ApiResponse::success($data);

        $this->assertSame(['success' => true, 'message' => 'Success', 'data' => $data], $body);
        $this->assertSame($data, $body['data']);
    }

    public static function payloads(): iterable
    {
        yield 'null' => [null];
        yield 'empty list' => [[]];
        yield 'empty object' => [new stdClass];
        yield 'DTO list' => [[['labTestRequestId' => 1, 'paidAt' => null]]];
        yield 'upload PDF string is data' => ['Upload PDF thành công'];
        yield 'false is not null' => [false];
        yield 'zero is not null' => [0];
        yield 'decimal stays numeric' => [125000.5];
        yield 'empty string is not null' => [''];
    }

    #[DataProvider('messages')]
    public function test_custom_success_message_including_null_is_preserved(?string $message): void
    {
        $this->assertSame(
            ['success' => true, 'message' => $message, 'data' => ['medServiceRequestId' => 7]],
            ApiResponse::success($message, ['medServiceRequestId' => 7]),
        );
        $this->assertSame(
            ['success' => true, 'message' => $message, 'data' => null],
            ApiResponse::success($message, null),
        );
    }

    #[DataProvider('messages')]
    public function test_error_keeps_null_data_and_exact_message(?string $message): void
    {
        $this->assertSame(
            ['success' => false, 'message' => $message, 'data' => null],
            ApiResponse::error($message),
        );
    }

    public static function messages(): iterable
    {
        yield 'custom' => ['Result already exists for this request'];
        yield 'empty' => [''];
        yield 'null' => [null];
    }

    public function test_result_dto_keeps_null_fields_and_formatted_datetime_in_json(): void
    {
        $data = [
            'labTestResultId' => 2,
            'labTestRequestId' => 1,
            'resultData' => null,
            'resultDate' => JavaDate::localDateTime(new DateTimeImmutable('2026-11-15T12:34:56.123456+07:00')),
        ];
        $response = new JsonResponse(ApiResponse::success($data));

        $this->assertSame([
            'success' => true, 'message' => 'Success', 'data' => [
                'labTestResultId' => 2, 'labTestRequestId' => 1, 'resultData' => null,
                'resultDate' => '2026-11-15T12:34:56',
            ],
        ], $response->getData(true));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_page_is_nested_under_data_without_rewriting_metadata(): void
    {
        $page = SpringPage::fromQueryString('size=1')->toArray([
            ['labTestRequestId' => 1, 'status' => 'PENDING', 'totalPrice' => 125000.5, 'paidAt' => null],
        ], 2);
        $response = new JsonResponse(ApiResponse::success($page));

        $this->assertSame(['success' => true, 'message' => 'Success', 'data' => $page], $response->getData(true));
    }

    public function test_wrapper_leaves_http_status_to_the_controller(): void
    {
        $error = new JsonResponse(ApiResponse::error('Failed'));
        $customStatus = new JsonResponse(ApiResponse::success(null), 201);

        $this->assertSame(200, $error->getStatusCode());
        $this->assertSame(['success' => false, 'message' => 'Failed', 'data' => null], $error->getData(true));
        $this->assertSame(201, $customStatus->getStatusCode());
        $this->assertSame(['success' => true, 'message' => 'Success', 'data' => null], $customStatus->getData(true));
    }
}
