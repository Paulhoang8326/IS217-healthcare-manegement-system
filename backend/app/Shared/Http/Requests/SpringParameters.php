<?php

namespace App\Shared\Http\Requests;

use Illuminate\Http\Request;
use InvalidArgumentException;

final class SpringParameters
{
    private const WHITESPACE = '[\x09-\x0d\x1c-\x20\x{1680}\x{2000}-\x{2006}\x{2008}-\x{200a}\x{2028}\x{2029}\x{205f}\x{3000}]';

    public static function queryValue(Request $request, string $name): ?string
    {
        foreach (explode('&', (string) $request->server->get('QUERY_STRING', '')) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($key) === $name) {
                return urldecode($value);
            }
        }

        return null;
    }

    public static function queryLong(Request $request, string $name): ?int
    {
        return self::longValue(self::queryValue($request, $name), $name);
    }

    public static function pathLong(string $value, string $name = 'id'): int
    {
        return self::longValue($value, $name) ?? throw new InvalidArgumentException(
            "Required URI template variable '$name' for method parameter type Long is present but converted to null",
        );
    }

    private static function longValue(?string $value, string $name): ?int
    {
        if ($value === null) {
            return null;
        }
        $text = preg_replace('/'.self::WHITESPACE.'/u', '', $value) ?? $value;
        if ($text === '') {
            return null;
        }
        $negative = str_starts_with($text, '-');
        $unsigned = ltrim($text, '+-');
        $hex = str_starts_with($unsigned, '0x') || str_starts_with($unsigned, '0X') || str_starts_with($unsigned, '#');
        $digits = $hex ? substr($unsigned, str_starts_with($unsigned, '#') ? 1 : 2) : $unsigned;
        if ($hex && ($digits !== '' && ($digits[0] === '-' || $digits[0] === '+'))) {
            throw self::mismatch($name, 'java.lang.Long', 'Sign character in wrong position');
        }
        $ascii = preg_replace_callback('/\p{Nd}/u', static function (array $match): string {
            $code = mb_ord($match[0]);
            if ($code > 65535) {
                return $match[0];
            }
            $zero = $code;
            while ($zero > 0 && preg_match('/^\p{Nd}$/u', mb_chr($zero - 1))) {
                $zero--;
            }

            return (string) (($code - $zero) % 10);
        }, $digits) ?? $digits;
        $bound = $hex ? ($negative ? '8000000000000000' : '7fffffffffffffff')
            : ($negative ? '9223372036854775808' : '9223372036854775807');
        $significant = ltrim(strtolower($ascii), '0');
        $valid = preg_match($hex ? '/^[0-9a-fA-F]+$/D' : '/^[0-9]+$/D', $ascii)
            && ! preg_match('/^[+-]{2}/', $text)
            && (strlen($significant) < strlen($bound) || (strlen($significant) === strlen($bound) && strcmp($significant, $bound) <= 0));
        if (! $valid) {
            $bad = $hex ? ($negative ? '-' : '').$digits : $text;
            throw self::mismatch($name, 'java.lang.Long', 'For input string: "'.$bad.'"'.($hex ? ' under radix 16' : ''));
        }
        if ($hex) {
            return $negative && $significant === '8000000000000000' ? PHP_INT_MIN : ($negative ? -1 : 1) * intval($ascii, 16);
        }

        return (int) (($negative ? '-' : '').$ascii);
    }

    public static function queryDate(Request $request, string $name): ?string
    {
        $value = self::queryValue($request, $name);
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^([0-9]{4}|-[0-9]{4,9}|\+[0-9]{5,9})-([0-9]{2})-([0-9]{2})(?:[Zz]|([+-])([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?)?$/D', $value, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
            $days = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            $hours = (int) ($m[5] ?? 0);
            $minutes = (int) ($m[6] ?? 0);
            $seconds = (int) ($m[7] ?? 0);
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= $days[$month - 1]
                && $hours <= 18 && $minutes <= 59 && $seconds <= 59 && ($hours < 18 || ($minutes === 0 && $seconds === 0))) {
                $formattedYear = abs($year) < 10000 ? ($year < 0 ? '-' : '').sprintf('%04d', abs($year)) : ($year < 0 ? '' : '+').$year;

                return $formattedYear.sprintf('-%02d-%02d', $month, $day);
            }
        }
        throw self::mismatch($name, 'java.time.LocalDate', 'Failed to convert from type [java.lang.String] to type [@org.springframework.format.annotation.DateTimeFormat java.time.LocalDate] for value ['.$value.']');
    }

    public static function queryEnum(Request $request, string $name, string $javaType, array $values): ?string
    {
        $value = self::queryValue($request, $name);
        if ($value === null || $value === '') {
            return null;
        }
        $trimmed = trim($value, "\x00..\x20");
        if (! in_array($trimmed, $values, true)) {
            throw self::mismatch($name, $javaType, 'Failed to convert from type [java.lang.String] to type ['.$javaType.'] for value ['.$value.']');
        }

        return $trimmed;
    }

    private static function mismatch(string $name, string $type, string $cause): InvalidArgumentException
    {
        return new InvalidArgumentException("Method parameter '$name': Failed to convert value of type 'java.lang.String' to required type '$type'; ".$cause);
    }
}
