<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * GMPay 金流商配置
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        'API_URL' => 'https://example.invalid',
        'API_KEY' => '',
        'SECRET_KEY' => '',
        'CURRENCY' => '',
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 73,
        'channels' => [
            [
                'currency' => 'SGD',
                'name' => 'GMPay',
                'key' => 'GMPay',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/GMPay.png',
                'options' => [
                    ['name' => 'P2P', 'key' => '1'],
                    ['name' => 'PayNow', 'key' => '2'],
                ],
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 74,
        'channels' => [
            [
                'currency' => 'SGD',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    // 國際大型銀行（新加坡）
                    ['name' => '(SGD) - DBS Bank', 'code' => 'DBS'],
                    ['name' => '(SGD) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(SGD) - United Overseas Bank', 'code' => 'UOB'],
                    ['name' => '(SGD) - HSBC Bank', 'code' => 'HSBC'],
                    ['name' => '(SGD) - Standard Chartered Bank', 'code' => 'SCB'],
                    ['name' => '(SGD) - Citibank', 'code' => 'Citi'],

                    // 馬來西亞銀行（新加坡分行）
                    ['name' => '(SGD) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(SGD) - Maybank', 'code' => 'MBB'],
                    ['name' => '(SGD) - RHB Bank', 'code' => 'RHB'],
                    ['name' => '(SGD) - Hong Leong Bank', 'code' => 'HLB'],
                    ['name' => '(SGD) - Alliance Bank', 'code' => 'ABMB'],

                    // 中資銀行
                    ['name' => '(SGD) - Bank of China', 'code' => 'BOC'],

                    // 新加坡數位銀行
                    ['name' => '(SGD) - Trust Bank', 'code' => 'TBL'],
                    ['name' => '(SGD) - GXS Bank', 'code' => 'GXS'],
                    ['name' => '(SGD) - Maribank', 'code' => 'Maribank'],

                    // 電子錢包與支付平台
                    ['name' => '(SGD) - Wise', 'code' => 'Wise'],
                    ['name' => '(SGD) - LiquidPay', 'code' => 'LiquidPay'],
                    ['name' => '(SGD) - Dash', 'code' => 'Dash'],
                    ['name' => '(SGD) - PayNow', 'code' => 'PayNow'],
                    ['name' => '(SGD) - POSB Bank', 'code' => 'POSB'],
                ],
            ],
        ],
    ],
];
