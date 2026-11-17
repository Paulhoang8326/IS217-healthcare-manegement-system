<?php

namespace App\Shared\Support;

final class ApiResponse
{
    public static function success(mixed $messageOrData, mixed $data = null): array
    {
        if (func_num_args() === 1) {
            return self::body(true, 'Success', $messageOrData);
        }

        return self::body(true, $messageOrData, $data);
    }

    public static function error(?string $message): array
    {
        return self::body(false, $message, null);
    }

    private static function body(bool $success, ?string $message, mixed $data): array
    {
        return ['success' => $success, 'message' => $message, 'data' => $data];
    }
}
