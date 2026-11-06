<?php

return [
    'secret' => env('JWT_SECRET'),
    'access-token-expiration-ms' => env('JWT_ACCESS_TOKEN_EXPIRATION_MS'),
    'verification-token-expiration-ms' => env('JWT_VERIFICATION_TOKEN_EXPIRATION_MS'),
];
