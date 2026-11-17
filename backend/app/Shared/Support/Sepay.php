<?php

namespace App\Shared\Support;

use App\Shared\Exceptions\BusinessException;

final class Sepay
{
    public static function resolveSecret(?string $secretKeyHeader, ?string $authorizationHeader): ?string
    {
        if (self::isBlank($secretKeyHeader) && ! self::isBlank($authorizationHeader)) {
            // QUIRK-NEW: Only the exact case-sensitive Apikey prefix is removed; Bearer is kept.
            return self::trim(str_starts_with($authorizationHeader, 'Apikey ')
                ? substr($authorizationHeader, 7) : $authorizationHeader);
        }

        return $secretKeyHeader;
    }

    public static function validateSepaySecret(?string $secretKey): void
    {
        $configured = config('sepay.webhook-secret');
        if (self::isBlank($configured)) {
            throw new BusinessException('SePay webhook secret is not configured');
        }
        if ($configured !== $secretKey) {
            throw new BusinessException('Invalid SePay webhook secret');
        }
    }

    public static function validateIncomingTransfer(?array $request, string $context = 'appointments'): void
    {
        if ($request === null) {
            throw new BusinessException('SePay webhook payload must not be null');
        }
        if (strcasecmp($request['transferType'] ?? '', 'in') !== 0) {
            throw new BusinessException('Only incoming transfers can confirm '.$context);
        }
        $account = config('sepay.account-number');
        if (! self::isBlank($account) && $account !== ($request['accountNumber'] ?? null)) {
            throw new BusinessException('Webhook account number does not match configured SePay account');
        }
        if (($request['transferAmount'] ?? 0) <= 0) {
            throw new BusinessException('Webhook transfer amount must be greater than zero');
        }
    }

    public static function isBlank(?string $value): bool
    {
        return $value === null || preg_match('/^[\x09-\x0d\x1c-\x20\x{1680}\x{2000}-\x{2006}\x{2008}-\x{200a}\x{2028}\x{2029}\x{205f}\x{3000}]*$/uD', $value) === 1;
    }

    public static function trim(string $value): string
    {
        return trim($value, "\x00..\x20");
    }
}
