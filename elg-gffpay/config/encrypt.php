<?php

return [
    'aes' => [
        'cipher' => env('AES_CIPHER', 'aes-256-cbc'),
        'key' => env('AES_KEY', ''),
        'iv' => env('AES_IV', ''),
        'md5' => env('AES_MD5', ''),
    ],

    'third_party' => [
        'cipher' => env('THIRD_PARTY_CIPHER', 'aes-256-cbc'),
        'key' => env('THIRD_PARTY_AES_KEY', ''),
        'iv' => env('THIRD_PARTY_IV', ''),
        'md5' => env('THIRD_PARTY_MD5_KEY', ''),
    ],
];
