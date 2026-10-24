<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * MajesticPay 金流商配置
 */
return [
    // 代收配置
    'deposit' => [
        'cash_flow_id' => 71,
        'channels' => [
            [
                'currency' => 'MYR',
                'name' => 'MajesticPay - Instant TopUp',
                'key' => '3',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/MajesticPay.png',
            ],
            [
                'currency' => 'MYR',
                'name' => 'MajesticPay - Ewallet',
                'key' => '6',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/MajesticPay.png',
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 72,
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(MYR) - AmBank', 'code' => 'AMMB'],
                    ['name' => '(MYR) - Alliance Bank', 'code' => 'ABMB'],
                    ['name' => '(MYR) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(MYR) - Hong Leong Bank', 'code' => 'HLB'],
                    ['name' => '(MYR) - Maybank', 'code' => 'MBB'],
                    ['name' => '(MYR) - Public Bank', 'code' => 'PBB'],
                    ['name' => '(MYR) - RHB Bank', 'code' => 'RHB'],
                    ['name' => '(MYR) - Affin Bank', 'code' => 'ABB'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'code' => 'BIMB'],
                    ['name' => '(MYR) - Bank Muamalat', 'code' => 'BMMB'],
                    ['name' => '(MYR) - HSBC Bank', 'code' => 'HSBC'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(MYR) - United Overseas Bank', 'code' => 'UOB'],
                ],
            ],
        ],
    ],
];
