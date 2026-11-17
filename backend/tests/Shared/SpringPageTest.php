<?php

namespace Tests\Shared;

use App\Shared\Support\SpringPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpringPageTest extends TestCase
{
    #[DataProvider('parameters')]
    public function test_parameters_match_spring_resolver(string $query, int $page, int $size): void
    {
        $actual = SpringPage::fromQueryString($query);
        $this->assertSame($page, $actual->getPageNumber());
        $this->assertSame($size, $actual->getPageSize());
        $this->assertSame($page * $size, $actual->getOffset());
    }

    public static function parameters(): iterable
    {
        yield ['', 0, 20];
        yield ['page=2&size=10', 2, 10];
        yield ['page=-1&size=-1', 0, 20];
        yield ['page=abc&size=abc', 0, 20];
        yield ['page=&size=', 0, 20];
        yield ['page=1.5&size=1e2', 0, 20];
        yield ['page=0&size=0', 0, 20];
        yield ['size=2000', 0, 2000];
        yield ['size=2001', 0, 2000];
        yield ['page=2147483647&size=2147483647', 2147483647, 2000];
        yield ['page=2147483648&size=2147483648', 0, 20];
        yield ['page=-2147483649&size=-2147483649', 0, 20];
        yield ['page=%2B2&size=%2B5', 2, 5];
        yield ['page=+2&size=+5', 0, 20];
        yield ['page=02&size=005', 2, 5];
        yield ['page=２&size=٣', 2, 3];
        yield ['page=𝟚&size=𝟛', 0, 20];
        yield ['page=2&page=4&size=5&size=10', 2, 5];
        yield ['page[]=2&size[]=5', 0, 20];
        yield ['page=%202%20&size=%205%20', 0, 20];
        yield ['page=99999999999999999999&size=99999999999999999999', 0, 20];
    }

    #[DataProvider('sorts')]
    public function test_sort_keeps_spring_order_and_properties(string $query, array $expected): void
    {
        $this->assertSame($expected, SpringPage::fromQueryString($query)->getSort());
    }

    public static function sorts(): iterable
    {
        $order = fn ($p, $d = 'ASC', $i = false) => ['property' => $p, 'direction' => $d, 'ignoreCase' => $i];
        yield ['', []];
        yield ['sort=', []];
        yield ['sort=...,,asc', []];
        yield ['sort=fullName,desc&sort=accountId,asc', [$order('fullName', 'DESC'), $order('accountId')]];
        yield ['sort=fullName,email,DeSc', [$order('fullName', 'DESC'), $order('email', 'DESC')]];
        yield ['sort=name,bad', [$order('name'), $order('bad')]];
        yield ['sort=name,desc,ignorecase', [$order('name', 'DESC', true)]];
        yield ['sort=name,ignorecase', [$order('name', 'ASC', true)]];
        yield ['sort=role.roleName,desc', [$order('role.roleName', 'DESC')]];
        yield ['sort=%20name%20,%20desc', [$order(' name '), $order(' desc')]];
        yield ['sort=name,asc&sort=name,desc', [$order('name'), $order('name', 'DESC')]];
        yield ['sort=name%252Cdesc', [$order('name', 'DESC')]];
        yield ['sort[]=name,desc', []];
    }

    public function test_request_uses_raw_query_to_preserve_repeated_sort(): void
    {
        $request = Request::create('/api/accounts?sort=fullName,desc&sort=accountId,asc&page=2');
        $page = SpringPage::fromRequest($request);
        $this->assertSame(['fullName', 'accountId'], array_column($page->getSort(), 'property'));
        $this->assertSame(2, $page->getPageNumber());
    }

    public function test_json_matches_jackson_page_impl_and_preserves_dto_content(): void
    {
        $content = [['accountId' => 2, 'email' => null], ['accountId' => 1, 'email' => 'x']];
        $page = SpringPage::fromQueryString('page=2&size=10&sort=fullName,desc');
        $sort = ['empty' => false, 'sorted' => true, 'unsorted' => false];
        $expected = [
            'content' => $content, 'empty' => false, 'first' => false, 'last' => false,
            'number' => 2, 'numberOfElements' => 2,
            'pageable' => ['offset' => 20, 'pageNumber' => 2, 'pageSize' => 10, 'paged' => true, 'sort' => $sort, 'unpaged' => false],
            'size' => 10, 'sort' => $sort, 'totalElements' => 41, 'totalPages' => 5,
        ];
        $this->assertSame($expected, $page->toArray($content, 41));
    }

    #[DataProvider('pages')]
    public function test_page_metadata_matches_page_impl(string $query, array $content, int $total, int $expectedTotal, int $pages, bool $last): void
    {
        $result = SpringPage::fromQueryString($query)->toArray($content, $total);
        $this->assertSame($expectedTotal, $result['totalElements']);
        $this->assertSame($pages, $result['totalPages']);
        $this->assertSame($last, $result['last']);
        $this->assertSame($content === [], $result['empty']);
        $this->assertSame(array_values($content), $result['content']);
    }

    public static function pages(): iterable
    {
        yield ['', [], 0, 0, 0, true];
        yield ['page=9&size=10', [], 41, 41, 5, true];
        yield ['page=4&size=10', [['id' => 41]], 41, 41, 5, true];
        yield ['page=4&size=10', [['id' => 41]], 42, 41, 5, true];
        yield ['page=4&size=10', [['id' => 41]], 39, 41, 5, true];
        yield ['size=10', array_fill(0, 10, ['id' => 1]), 20, 20, 2, false];
        yield ['size=1', [], PHP_INT_MAX, PHP_INT_MAX, 2147483647, false];
        yield ['page=2147483647&size=1', [], 0, 0, 0, false];
    }

    public function test_invalid_numeric_parameters_still_return_200_json(): void
    {
        Route::get('/api/auth/page-test', fn (Request $request) => response()->json(SpringPage::fromRequest($request)->toArray([], 0)));
        $this->get('/api/auth/page-test?page=bad&size=-2')->assertOk()
            ->assertHeader('Content-Type', 'application/json')->assertJsonPath('size', 20)->assertJsonPath('number', 0);
    }

    #[DataProvider('invalidEscapes')]
    public function test_invalid_sort_escape_uses_existing_java_error_renderer(string $encoded, string $message): void
    {
        Route::get('/api/auth/page-test', fn (Request $request) => response()->json(SpringPage::fromRequest($request)->toArray([], 0)));
        $this->get('/api/auth/page-test?sort=name'.$encoded)->assertStatus(500)
            ->assertHeader('Content-Type', 'text/plain;charset=UTF-8')
            ->assertContent('An unexpected error occurred: '.$message);
    }

    public static function invalidEscapes(): iterable
    {
        yield ['%25xx', 'Invalid encoded sequence "%xx"'];
        yield ['%25', 'Incomplete trailing escape (%) pattern'];
        yield ['%25a', 'Incomplete trailing escape (%) pattern'];
    }
}
