<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * FlashPay 金流商配置
 */
return [
    'credentials' => [
        'API_URL' => 'https://example.invalid/v3',
        'CLIENT_ID' => '8e363c65-6dec-11f1-8a6d-0200009ee8a2',
        // 三方測試商戶憑證，非 production 真值、經確認可入版控——勿比照其他 provider「SECRET_KEY 留空占位」的慣例
        // 判為憑證外洩。正式值由 ops 於資料 seed 進 DB 後覆蓋、不進版控；此處一旦改填正式商戶憑證就必須改回空占位。
        'SECRET_KEY' => '5c378d3e9e1fcacf83e4ad94df944a538102905a116af5ae6ed6f1bdff13ca97',
        'CURRENCY' => 'SGD',
    ],

    'deposit' => [
        'cash_flow_id' => 117,
        'channels' => [
            [
                'currency' => 'SGD',
                'name' => 'FlashPay (PayNow)',
                'key' => 'SGD_PAYNOW',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/2.png',
            ],
            [
                'currency' => 'SGD',
                'name' => 'FlashPay (Bank Transfer)',
                'key' => 'SGD_BANKTRANSFER',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/2.png',
            ],
            [
                'currency' => 'SGD',
                'name' => 'FlashPay (Online Banking)',
                'key' => 'SGD_ONLINEBANKING',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/2.png',
            ],
        ],
    ],

    'withdrawal' => [
        'cash_flow_id' => 118,
        'channels' => [
            [
                'currency' => 'SGD',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(SGD) - DBS Bank', 'code' => 'DBS'],
                    ['name' => '(SGD) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(SGD) - United Overseas Bank', 'code' => 'UOB'],
                    ['name' => '(SGD) - HSBC Bank', 'code' => 'HSBC'],
                    ['name' => '(SGD) - Standard Chartered Bank', 'code' => 'SCB'],
                    ['name' => '(SGD) - Citibank', 'code' => 'CITI'],
                    ['name' => '(SGD) - BNP Paribas', 'code' => 'BNP'],
                    ['name' => '(SGD) - Bank of China', 'code' => 'BOC'],
                    ['name' => '(SGD) - Maybank', 'code' => 'MAYBANK'],
                    ['name' => '(SGD) - PayNow', 'code' => 'PAYNOW'],
                    ['name' => '(SGD) - POSB Bank', 'code' => 'POSB'],
                    ['name' => '(SGD) - Hong Leong Bank', 'code' => 'HLBBSG'],
                    ['name' => '(SGD) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(SGD) - Wise', 'code' => 'WISE'],
                    ['name' => '(SGD) - Trust Bank', 'code' => 'TRUST'],
                    ['name' => '(SGD) - GXS Bank', 'code' => 'GXS'],
                    ['name' => '(SGD) - Maribank', 'code' => 'MARIBANK'],
                    ['name' => '(SGD) - ANEXT Bank', 'code' => 'ANEXT'],
                    ['name' => '(SGD) - MatchMove', 'code' => 'MATCHMOVE'],
                    ['name' => '(SGD) - Bank of Singapore', 'code' => 'BOS'],
                    ['name' => '(SGD) - ABN AMRO Bank', 'code' => 'ABN'],
                ],
            ],
        ],
    ],
];
