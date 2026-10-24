<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * AkPay 金流商配置
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        'API_URL' => 'https://example.invalid',
        'MERCHANT_CODE' => 'TPOWERMYR',
        'MERCHANT_TOKEN' => '',
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 105,
        'channels' => [
            // ! MYR
            [
                'currency' => 'MYR',
                'name' => 'AkPay (Online Banking)',
                'key' => 'Bank',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/1.png',
                'options' => [
                    ['name' => 'Hong Leong Bank', 'key' => 'HLB'],
                    ['name' => 'Maybank Berhad', 'key' => 'MBB'],
                    ['name' => 'Public Bank', 'key' => 'PBB'],
                    ['name' => 'RHB Bank', 'key' => 'RHB'],
                    ['name' => 'CIMB Bank', 'key' => 'CIMB'],
                    ['name' => 'Bank Simpanan Nasional', 'key' => 'BSN'],
                    ['name' => 'Bank Muamalat', 'key' => 'BMMB'],
                    ['name' => 'Affin Bank', 'key' => 'AFB'],
                ],
            ],
            [
                'currency' => 'MYR',
                'name' => 'AkPay (QR Code)',
                'key' => 'QR',
                'type' => DepositTypeEnum::QRCODE->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/1.png',
                'options' => [
                    ['name' => 'Touch n Go', 'key' => 'TNGQR'],
                    ['name' => 'DuitNow', 'key' => 'DUITNOW'],  // ! 線上只開啟這個
                    ['name' => 'DuitNow Ewallet', 'key' => 'DUITNOWEWALLET'],
                ],
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 106,
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(MYR) - Hong Leong Bank', 'code' => 'HLB'],
                    ['name' => '(MYR) - Maybank', 'code' => 'MBB'],
                    ['name' => '(MYR) - Public Bank', 'code' => 'PBB'],
                    ['name' => '(MYR) - RHB Bank', 'code' => 'RHB'],
                    ['name' => '(MYR) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(MYR) - Affin Bank', 'code' => 'AFB'],
                    ['name' => '(MYR) - HSBC Bank', 'code' => 'HSBC'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(MYR) - Standard Chartered Bank', 'code' => 'SCBMY'],
                    ['name' => '(MYR) - Bank Simpanan Nasional', 'code' => 'BSN'],
                    ['name' => '(MYR) - AmBank', 'code' => 'AMBB'],
                    ['name' => '(MYR) - Bank Islam', 'code' => 'BIMB'],
                    ['name' => '(MYR) - Alliance Bank', 'code' => 'ABMB'],
                    ['name' => '(MYR) - Touch n Go', 'code' => 'TNG'],
                    ['name' => '(MYR) - GX Bank', 'code' => 'GX'],
                    ['name' => '(MYR) - AEON Bank', 'code' => 'AEON'],
                    ['name' => '(MYR) - Boost Bank', 'code' => 'BOOSTBANK'],
                    ['name' => '(MYR) - Merchantrade', 'code' => 'MERCHANTRADE'],
                    ['name' => '(MYR) - BigPay', 'code' => 'BIGPAY'],
                    ['name' => '(MYR) - Bank Muamalat', 'code' => 'BMMB'],
                    ['name' => '(MYR) - MBSB Bank', 'code' => 'MBSB'],
                    ['name' => '(MYR) - AGRO Bank', 'code' => 'AGRO'],
                    ['name' => '(MYR) - Ryt Bank', 'code' => 'RYT'],
                ],
            ],
        ],
    ],
];
