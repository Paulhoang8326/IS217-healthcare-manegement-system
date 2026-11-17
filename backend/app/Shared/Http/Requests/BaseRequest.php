<?php

namespace App\Shared\Http\Requests;

use App\Shared\Exceptions\HttpMessageNotReadableException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\ValidationException;
use JsonException;
use LogicException;
use stdClass;

abstract class BaseRequest extends FormRequest
{
    private array $bodyData = [];

    private bool $constraintsAttached = false;

    /** Field definitions copy Java types, constraints, and nested @Valid markers. */
    abstract protected function bodyFields(): array;

    /** Override only for controller parameters annotated with @Valid @RequestBody. */
    protected function validatesBody(): bool
    {
        return false;
    }

    final public function rules(): array
    {
        return [];
    }

    final protected function prepareForValidation(): void
    {
        $raw = (string) $this->getContent();
        try {
            $body = json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            // Preserve decimal lexemes separately from PHP's floating-point decoder.
            $numbers = preg_replace_callback('~"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"|(-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)~s',
                fn (array $match) => isset($match[1]) ? json_encode($match[1], JSON_THROW_ON_ERROR) : $match[0], $raw);
            if ($numbers === null) {
                throw new LogicException('JSON number tokenization failed: '.preg_last_error_msg());
            }
            $numberBody = json_decode($numbers, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HttpMessageNotReadableException(previous: $exception);
        }
        if (! $body instanceof stdClass || ! $numberBody instanceof stdClass) {
            throw new HttpMessageNotReadableException;
        }

        $this->bodyData = $this->parseObject($body, $numberBody, $this->bodyFields());
        $this->request->replace($this->bodyData);
        $this->json()->replace($this->bodyData);
    }

    public function validationData(): array
    {
        return $this->bodyData;
    }

    protected function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();
        if (! $this->constraintsAttached && $this->validatesBody()) {
            $this->constraintsAttached = true;
            $validator->after(function (Validator $validator): void {
                $this->validateObject($this->bodyData, $this->bodyFields(), '', $validator);
            });
        }

        return $validator;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw (new ValidationException($validator))->status(400);
    }

    public function validated($key = null, $default = null)
    {
        parent::validated();

        return data_get($this->bodyData, $key, $default);
    }

    public function safe(?array $keys = null)
    {
        $data = new ValidatedInput($this->validated());

        return $keys === null ? $data : $data->only($keys);
    }

    private function definition(array|string $field): array
    {
        return is_string($field) ? ['type' => $field] : $field;
    }

    private function parseObject(stdClass $body, stdClass $numbers, array $fields): array
    {
        $parsed = [];
        foreach ($fields as $name => $field) {
            $parsed[$name] = $this->parseValue($body->$name ?? null, $numbers->$name ?? null, $this->definition($field));
        }

        return $parsed;
    }

    private function parseValue(mixed $value, mixed $number, array $field): mixed
    {
        if ($value === null) {
            return null;
        }
        $type = $field['type'];
        if (! in_array($type, ['String', 'List', 'Object'], true) && is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }
        if (in_array($type, ['LocalDate', 'LocalDateTime'], true) && is_array($value)) {
            return $this->parseDate($value, $type);
        }
        if ($type === 'Object') {
            if (! $value instanceof stdClass || ! $number instanceof stdClass) {
                throw new HttpMessageNotReadableException;
            }

            return $this->parseObject($value, $number, $field['fields']);
        }
        if ($type === 'List') {
            if (! is_array($value)) {
                throw new HttpMessageNotReadableException;
            }
            $items = [];
            foreach ($value as $index => $item) {
                $items[] = $this->parseValue($item, $number[$index] ?? null, $this->definition($field['items']));
            }

            return $items;
        }
        if (! is_scalar($value) || is_bool($value)) {
            if ($type === 'String' && is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            throw new HttpMessageNotReadableException;
        }

        return match ($type) {
            'String' => is_string($number) && ! is_string($value) ? $number : (string) $value,
            'Long', 'Integer' => $this->parseInteger($value, $number, $type),
            'BigDecimal' => $this->parseDecimal(is_string($number) ? $number : (string) $value),
            'Double' => is_numeric($value) && is_finite((float) $value) ? (float) $value : throw new HttpMessageNotReadableException,
            'LocalDate', 'LocalDateTime' => $this->parseDate($value, $type),
            'Enum' => $this->parseEnum($value, $field['values']),
            default => throw new LogicException('Unsupported Java body type: '.$type),
        };
    }

    private function parseInteger(mixed $value, mixed $number, string $type): int
    {
        if (is_float($value)) {
            [$sign, $digits, $scale] = $this->decimalParts($number);
            $length = strlen($digits) - $scale;
            if ($length > 19) {
                throw new HttpMessageNotReadableException;
            }
            $value = ($sign < 0 ? '-' : '').($length <= 0 ? '0' : substr(str_pad($digits, $length, '0'), 0, $length));
        }
        $value = (string) $value;
        if (! preg_match('/^[+-]?[0-9]+$/D', $value)) {
            throw new HttpMessageNotReadableException;
        }
        $min = $type === 'Integer' ? '-2147483648' : '-9223372036854775808';
        $max = $type === 'Integer' ? '2147483647' : '9223372036854775807';
        if ($this->compareDecimal($value, $min) < 0 || $this->compareDecimal($value, $max) > 0) {
            throw new HttpMessageNotReadableException;
        }

        return (int) $value;
    }

    private function parseDecimal(string $value): string
    {
        $value = trim($value);
        $this->decimalParts($value);

        return $value;
    }

    private function decimalParts(string $number): array
    {
        if (! preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/D', $number, $match, PREG_UNMATCHED_AS_NULL)) {
            throw new HttpMessageNotReadableException;
        }
        $exponent = $match[5] ?? '0';
        if (strlen(ltrim($exponent, '+-0')) > 10 || (int) $exponent > 2147483647 || (int) $exponent < -2147483648) {
            throw new HttpMessageNotReadableException;
        }
        $fraction = $match[3] ?? $match[4] ?? '';
        $digits = ltrim(($match[2] ?? '0').$fraction, '0') ?: '0';
        $scale = strlen($fraction) - (int) $exponent;
        if ($scale > 2147483647) {
            throw new HttpMessageNotReadableException;
        }

        return [$digits === '0' ? 0 : ($match[1] === '-' ? -1 : 1), $digits, $scale];
    }

    private function compareDecimal(string $left, string $right): int
    {
        [$aSign, $a, $aScale] = $this->decimalParts($left);
        [$bSign, $b, $bScale] = $this->decimalParts($right);
        if ($aSign !== $bSign) {
            return $aSign <=> $bSign;
        }
        if ($aSign === 0) {
            return 0;
        }
        $magnitude = (strlen($a) - $aScale) <=> (strlen($b) - $bScale);
        $length = max(strlen($a), strlen($b));

        return $aSign * ($magnitude ?: (strcmp(str_pad($a, $length, '0'), str_pad($b, $length, '0')) <=> 0));
    }

    private function parseEnum(mixed $value, array $values): string
    {
        if (is_string($value) && in_array($value, $values, true)) {
            return $value;
        }
        if (is_string($value) && strlen($value) > 1 && str_starts_with($value, '0')) {
            throw new HttpMessageNotReadableException;
        }
        // QUIRK-NEW: Jackson accepts enum ordinals and numeric strings without leading zeroes.
        if ((is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value)))
            && strlen(ltrim((string) $value, '0')) <= 10
            && array_key_exists((int) $value, $values)) {
            return $values[(int) $value];
        }

        throw new HttpMessageNotReadableException;
    }

    private function parseDate(mixed $value, string $type): ?string
    {
        if (is_array($value)) {
            if ($value === []) {
                return null;
            }
            $count = count($value);
            if (($type === 'LocalDate' && $count !== 3)
                || ($type === 'LocalDateTime' && ($count < 5 || $count > 7))) {
                throw new HttpMessageNotReadableException;
            }
            foreach ($value as $part) {
                if (! is_int($part)) {
                    throw new HttpMessageNotReadableException;
                }
            }
            $date = sprintf('%04d-%02d-%02d', $value[0], $value[1], $value[2]);
            if ($type === 'LocalDateTime') {
                $date .= sprintf('T%02d:%02d:%02d', $value[3], $value[4], $value[5] ?? 0);
                $nanos = $value[6] ?? 0;
                if ($nanos < 0 || $nanos > 999999999) {
                    throw new HttpMessageNotReadableException;
                }
                if ($nanos > 0) {
                    $date .= '.'.rtrim(str_pad((string) $nanos, 9, '0', STR_PAD_LEFT), '0');
                }
            }
            $value = $date;
        }
        if ($type === 'LocalDate' && is_string($value) && str_contains($value, 'T')) {
            $datetime = $this->parseDate($value, 'LocalDateTime');
            if ($datetime === null) {
                throw new HttpMessageNotReadableException;
            }
            $value = substr($datetime, 0, 10);
        } elseif ($type === 'LocalDateTime' && is_string($value) && str_ends_with($value, 'Z')) {
            $value = substr($value, 0, -1);
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})'.($type === 'LocalDate' ? '$' : 'T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,9}))?)?$').'/D', $value, $match)) {
            throw new HttpMessageNotReadableException;
        }
        $year = (int) $match[1];
        $month = (int) $match[2];
        $day = (int) $match[3];
        $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
        $days = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if ($month < 1 || $month > 12 || $day < 1 || $day > $days[$month - 1]
            || ($type === 'LocalDateTime' && ((int) $match[4] > 23 || (int) $match[5] > 59 || (int) ($match[6] ?? 0) > 59))) {
            throw new HttpMessageNotReadableException;
        }

        return $value;
    }

    private function validateObject(array $data, array $fields, string $prefix, Validator $validator): void
    {
        foreach ($fields as $name => $field) {
            $field = $this->definition($field);
            $value = $data[$name];
            $path = $prefix.$name;
            foreach ($field['constraints'] ?? [] as $constraint) {
                $constraint = is_string($constraint) ? ['name' => $constraint] : $constraint;
                if (! $this->satisfies($value, $constraint)) {
                    $validator->errors()->add($path, $constraint['message'] ?? $this->constraintMessage($constraint));
                }
            }
            if (($field['valid'] ?? false) && $value !== null) {
                $this->cascade($value, $field, $path, $validator);
            }
        }
    }

    private function cascade(mixed $value, array $field, string $path, Validator $validator): void
    {
        if ($value === null) {
            return;
        }
        if ($field['type'] === 'Object') {
            $this->validateObject($value, $field['fields'], $path.'.', $validator);
        } elseif ($field['type'] === 'List') {
            foreach ($value as $index => $item) {
                $this->cascade($item, $this->definition($field['items']), $path.'.'.$index, $validator);
            }
        }
    }

    private function satisfies(mixed $value, array $constraint): bool
    {
        $name = $constraint['name'];
        if ($value === null) {
            return ! in_array($name, ['NotNull', 'NotBlank', 'NotEmpty'], true);
        }
        $length = fn () => is_array($value) ? count($value) : intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
        $bound = (string) ($constraint['value'] ?? '0');

        return match ($name) {
            'NotNull' => true,
            'NotBlank' => preg_match('/^[\x09-\x0d\x1c-\x20\x{1680}\x{2000}-\x{2006}\x{2008}-\x{200a}\x{2028}\x{2029}\x{205f}\x{3000}]*$/uD', $value) !== 1,
            'NotEmpty' => $length() > 0,
            'Size' => $length() >= ($constraint['min'] ?? 0) && $length() <= ($constraint['max'] ?? 2147483647),
            'Min', 'DecimalMin' => $this->compareDecimal((string) $value, $bound) >= (($constraint['inclusive'] ?? true) ? 0 : 1),
            'Max', 'DecimalMax' => $this->compareDecimal((string) $value, $bound) <= (($constraint['inclusive'] ?? true) ? 0 : -1),
            'Positive' => $this->compareDecimal((string) $value, '0') > 0,
            'Past' => $value < today()->toDateString(),
            'PastOrPresent' => $value <= today()->toDateString(),
            'FutureOrPresent' => $value >= today()->toDateString(),
            'Email' => $this->validEmail($value),
            'Pattern' => $this->matches($constraint['regexp'], $value),
            default => throw new LogicException('Unsupported Java constraint: '.$name),
        };
    }

    private function matches(string $regexp, string $value): bool
    {
        $result = preg_match('~\A(?:'.str_replace('~', '\\~', $regexp).')\z~u', $value);
        if ($result === false) {
            throw new LogicException('Unsupported Java pattern: '.$regexp);
        }

        return $result === 1;
    }

    private function constraintMessage(array $constraint): string
    {
        $name = $constraint['name'];
        $value = $constraint['value'] ?? '';
        $inclusive = ($constraint['inclusive'] ?? true) ? 'or equal to ' : '';

        return match ($name) {
            'NotNull' => 'must not be null',
            'NotBlank' => 'must not be blank',
            'NotEmpty' => 'must not be empty',
            'Size' => 'size must be between '.($constraint['min'] ?? 0).' and '.($constraint['max'] ?? 2147483647),
            'Min' => 'must be greater than or equal to '.$value,
            'Max' => 'must be less than or equal to '.$value,
            'DecimalMin' => 'must be greater than '.$inclusive.$value,
            'DecimalMax' => 'must be less than '.$inclusive.$value,
            'Positive' => 'must be greater than 0',
            'Past' => 'must be a past date',
            'PastOrPresent' => 'must be a date in the past or in the present',
            'FutureOrPresent' => 'must be a date in the present or in the future',
            'Email' => 'must be a well-formed email address',
            'Pattern' => 'must match "'.$constraint['regexp'].'"',
            default => throw new LogicException('Unsupported Java constraint: '.$name),
        };
    }

    private function validEmail(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        $split = strrpos($value, '@');
        if ($split === false) {
            return false;
        }
        $local = substr($value, 0, $split);
        $domain = substr($value, $split + 1);
        $atom = "[a-z0-9!#$%&'*+/=?^_`{|}~\\x{0080}-\\x{FFFF}-]";
        $quoted = <<<'REGEXP'
(?:[a-z0-9!#$%&'*.(),<>\[\]:;  @+/=?^_`{|}~\x{0080}-\x{FFFF}-]|\\\\|\\")
REGEXP;
        $part = '(?:'.$atom.'+|"'.$quoted.'+")';
        if (strlen(mb_convert_encoding($local, 'UTF-16LE', 'UTF-8')) > 128
            || preg_match('~\A'.str_replace('~', '\\~', $part.'(?:\.'.$part.')*').'\z~iu', $local) !== 1) {
            return false;
        }
        if (preg_match('/^\[([0-9]{1,3}\.){3}[0-9]{1,3}\]$/D', $domain)) {
            return strlen($domain) <= 255;
        }
        if (preg_match('/^\[IPv6:([^\]]+)\]$/iD', $domain, $ip)) {
            return filter_var($ip[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        $chars = "[a-z0-9!#$%&'*+/=?^_`{|}~\\x{0080}-\\x{FFFF}]";
        $label = $chars.'+(?:-+'.$chars.'+)*';
        if (preg_match('~\A'.str_replace('~', '\\~', $label.'(?:\.'.$label.')*').'\z~iu', $domain) !== 1) {
            return false;
        }
        $ascii = preg_match('/[^\x00-\x7f]/', $domain) ? idn_to_ascii($domain) : $domain;

        return $ascii !== false && strlen($ascii) <= 255
            && max(array_map('strlen', explode('.', $ascii))) <= 63;
    }
}
