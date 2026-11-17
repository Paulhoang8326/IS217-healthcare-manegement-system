<?php

namespace App\Shared\Support;

use DateTimeInterface;

final class JavaDate
{
    public static function localDate(?DateTimeInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }

    public static function localDateTime(?DateTimeInterface $value): ?string
    {
        // QUIRK-NEW: Java can serialize fractional seconds; this API contract requires whole seconds.
        return $value?->format('Y-m-d\TH:i:s');
    }
}
