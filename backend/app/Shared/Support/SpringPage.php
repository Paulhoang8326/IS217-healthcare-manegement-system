<?php

namespace App\Shared\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;

final class SpringPage
{
    private function __construct(
        private readonly int $page,
        private readonly int $size,
        private readonly array $orders,
    ) {}

    public static function fromRequest(Request $request): self
    {
        // Read the original query: PHP's query bag discards repeated sort parameters.
        return self::fromQueryString((string) $request->server->get('QUERY_STRING', ''));
    }

    public static function fromQueryString(string $query): self
    {
        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parameters[urldecode($key)][] = urldecode($value);
        }
        $page = self::integer($parameters['page'][0] ?? '0');
        $size = self::integer($parameters['size'][0] ?? '20');
        $orders = [];
        foreach ($parameters['sort'] ?? [] as $sort) {
            // Spring's Sort resolver URI-decodes the servlet parameter a second time.
            if (preg_match('/%(?![0-9a-fA-F]{2})/', $sort, $match, PREG_OFFSET_CAPTURE)) {
                $offset = $match[0][1];
                $message = strlen($sort) - $offset < 3
                    ? 'Incomplete trailing escape (%) pattern'
                    : 'Invalid encoded sequence "'.substr($sort, $offset, 3).'"';
                throw new InvalidArgumentException($message);
            }
            $parts = array_values(array_filter(explode(',', rawurldecode($sort)),
                fn (string $part) => self::hasText(str_replace('.', '', $part))));
            $ignoreCase = $parts !== [] && strcasecmp($parts[array_key_last($parts)], 'ignorecase') === 0;
            if ($ignoreCase) {
                array_pop($parts);
            }
            $direction = 'ASC';
            if ($parts !== [] && in_array(strtoupper($parts[array_key_last($parts)]), ['ASC', 'DESC'], true)) {
                $direction = strtoupper(array_pop($parts));
            }
            // QUIRK-NEW: An unrecognized direction remains a property with default ASC order.
            foreach ($parts as $property) {
                $orders[] = ['property' => $property, 'direction' => $direction, 'ignoreCase' => $ignoreCase];
            }
        }

        return new self(max(0, $page), $size < 1 ? 20 : min(2000, $size), $orders);
    }

    private static function hasText(string $value): bool
    {
        return preg_match('/[^\x{0009}-\x{000D}\x{001C}-\x{0020}\x{1680}\x{2000}-\x{2006}\x{2008}-\x{200A}\x{2028}\x{2029}\x{205F}\x{3000}]/u', $value) === 1;
    }

    private static function integer(string $value): int
    {
        // Integer.parseInt accepts BMP decimal digits via Character.digit(char, 10).
        $value = preg_replace_callback('/\p{Nd}/u', function (array $match): string {
            $code = mb_ord($match[0]);
            if ($code > 65535) {
                return $match[0];
            }
            $zero = $code;
            while ($zero > 0 && preg_match('/^\p{Nd}$/u', mb_chr($zero - 1))) {
                $zero--;
            }

            return (string) (($code - $zero) % 10);
        }, $value) ?? $value;
        if (! preg_match('/^[+-]?[0-9]+$/D', $value)) {
            return 0;
        }
        $digits = ltrim($value, '+-0');
        if (strlen($digits) > 10 || (int) $value > 2147483647 || (int) $value < -2147483648) {
            return 0;
        }

        return (int) $value;
    }

    public function getPageNumber(): int
    {
        return $this->page;
    }

    public function getPageSize(): int
    {
        return $this->size;
    }

    public function getOffset(): int
    {
        return $this->page * $this->size;
    }

    /** Ordered Java property names; the module repository resolves these to mapped columns/joins. */
    public function getSort(): array
    {
        return $this->orders;
    }

    /** Content must already be fetched in repository order and mapped to response DTOs. */
    public function toArray(array $content, int $total): array
    {
        $content = array_values($content);
        $count = count($content);
        // QUIRK-NEW: PageImpl adapts a stale total on a nonempty last page.
        if ($count > 0 && $this->getOffset() + $this->size > $total) {
            $total = $this->getOffset() + $count;
        }
        $pages = (int) max(-2147483648, min(2147483647, ceil($total / $this->size)));
        // QUIRK-NEW: PageImpl.hasNext adds one using Java's signed 32-bit integer.
        $next = $this->page === 2147483647 ? -2147483648 : $this->page + 1;
        $sort = ['empty' => $this->orders === [], 'sorted' => $this->orders !== [], 'unsorted' => $this->orders === []];

        return [
            'content' => $content,
            'empty' => $count === 0,
            'first' => $this->page === 0,
            'last' => $next >= $pages,
            'number' => $this->page,
            'numberOfElements' => $count,
            'pageable' => [
                'offset' => $this->getOffset(), 'pageNumber' => $this->page, 'pageSize' => $this->size,
                'paged' => true, 'sort' => $sort, 'unpaged' => false,
            ],
            'size' => $this->size,
            'sort' => $sort,
            'totalElements' => $total,
            'totalPages' => $pages,
        ];
    }
}
