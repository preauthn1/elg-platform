<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * EpicPay 金流商配置
 */
return [
    // 代收配置
    'deposit' => [
        'cash_flow_id' => 77,
        'channels' => [
            [
                'currency' => 'MYR',
                'name' => 'EpicPay (Banking)',
                'key' => 'EpicPay.Banking',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => '',
                'options' => [
                    ['name' => 'MayBank', 'key' => 'MBB'],
                    ['name' => 'CIMB Bank', 'key' => 'CIMB'],
                    ['name' => 'RHB Bank', 'key' => 'RHB'],
                    ['name' => 'Public Bank', 'key' => 'PBB'],
                    ['name' => 'Hong Leong Bank', 'key' => 'HLB'],
                    ['name' => 'Alliance Bank', 'key' => 'ALLIANCE'],
                    ['name' => 'AmBank', 'key' => 'AMBANK'],
                    ['name' => 'Bank Simpanan Nasional', 'key' => 'BSN'],
                    ['name' => 'OCBC Bank', 'key' => 'OCBCM'],
                    ['name' => 'United Overseas Bank', 'key' => 'OCBCM'],
                    ['name' => 'AGRO Bank', 'key' => 'AGRO'],
                    ['name' => 'Bank Rakyat', 'key' => 'RAKYAT'],
                ],
            ],
            [
                'currency' => 'MYR',
                'name' => 'EpicPay (QR)',
                'key' => 'EpicPay.QR',
                'type' => DepositTypeEnum::QRCODE->value,
                'image_path' => '',
                'min_amount' => 10,
                'max_amount' => 30000,
                'options' => [
                    ['name' => 'TnG QR', 'key' => 'TNGQR'],
                    ['name' => 'Duitnow QR', 'key' => 'DUITNOW'],
                ],
            ],
            [
                'currency' => 'MYR',
                'name' => 'EpicPay (FPX Internet Banking)',
                'key' => 'FPX',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => '',
                'min_amount' => 50,
                'max_amount' => 30000,
            ],
            [
                'currency' => 'MYR',
                'name' => 'EpicPay (FPX Duitnow QR)',
                'key' => 'FPXDUITNOW',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => '',
                'min_amount' => 10,
                'max_amount' => 30000,
            ],
            [
                'currency' => 'MYR',
                'name' => 'EpicPay (PX Ewallet)',
                'key' => 'FPXEWALLET',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => '',
                'min_amount' => 10,
                'max_amount' => 30000,
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 78,
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(MYR) - Maybank', 'code' => 'MBB'],
                    ['name' => '(MYR) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(MYR) - RHB Bank', 'code' => 'RHB'],
                    ['name' => '(MYR) - Public Bank', 'code' => 'PBB'],
                    ['name' => '(MYR) - Hong Leong Bank', 'code' => 'HLB'],
                    ['name' => '(MYR) - Affin Bank', 'code' => 'AFFIN'],
                    ['name' => '(MYR) - Alliance Bank', 'code' => 'ALLIANCE'],
                    ['name' => '(MYR) - AmBank', 'code' => 'AMBANK'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'code' => 'ISLAM'],
                    ['name' => '(MYR) - Bank Simpanan Nasional', 'code' => 'BSN'],
                    ['name' => '(MYR) - Citibank', 'code' => 'CITIM'],
                    ['name' => '(MYR) - HSBC Bank', 'code' => 'HSBCM'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => 'OCBCM'],
                    ['name' => '(MYR) - United Overseas Bank', 'code' => 'UOBM'],
                    ['name' => '(MYR) - Standard Chartered Bank', 'code' => 'STCM'],
                    ['name' => '(MYR) - AGRO Bank', 'code' => 'AGRO'],
                    ['name' => '(MYR) - Bank Rakyat', 'code' => 'RAKYAT'],
                    ['name' => '(MYR) - AEON Bank', 'code' => 'AEON'],
                    ['name' => '(MYR) - Boost Bank', 'code' => 'BOOSTBANK'],
                    ['name' => '(MYR) - GX Bank', 'code' => 'GX'],
                    ['name' => '(MYR) - Touch n Go', 'code' => 'TNGQR'],
                    // ['name' => '(MYR) - Duitnow IC', 'code' => 'DUITNOWIC'],
                    // ['name' => '(MYR) - Duitnow QR', 'code' => 'DUITNOW'],
                ],
            ],
        ],
    ],
];
