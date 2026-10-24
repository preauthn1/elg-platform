<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * BillionPay 金流商配置
 */
return [
    'credentials' => [
        'SECRET_KEY' => '',
        'USERNAME' => '',
        'PASSWORD' => '',
        'LOGIN_URL' => '',
        'DEPOSIT_URL' => '',
        'WITHDRAWAL_URL' => '',
    ],
    // 代收配置
    'deposit' => [
        'cash_flow_id' => 109,
        'channels' => [
            [
                'currency' => 'SGD',
                'name' => 'BillionPay (QR)',
                'key' => 'BillionPay',
                'type' => DepositTypeEnum::QRCODE->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/billionpay-logo.png',
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 110,
        'channels' => [
            [
                'currency' => 'SGD',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(SGD) - GXS Bank', 'code' => 'GXS'],
                    ['name' => '(SGD) - Mari Bank', 'code' => 'MARI'],
                    ['name' => '(SGD) - DBS Bank', 'code' => 'DBS'],
                    ['name' => '(SGD) - OCBC Bank', 'code' => 'OCBC'],
                ],
                'min_amount' => 50,
                'max_amount' => 3000,
            ],
        ],
    ],
];
