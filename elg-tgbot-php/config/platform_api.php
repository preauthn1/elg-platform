<?php

return [
    'url' => env('API_URL', 'https://example.invalid/api'),
    'md5_key' => env('API_MD5_KEY'),
    'aes_key' => env('API_AES_KEY'),
    'iv' => env('API_IV'),
    'token_expired' => env('TOKEN_EXPIRED', 1440),

    'secret_key' => env('PLATFORM_API_SEC_KEY'),
];
