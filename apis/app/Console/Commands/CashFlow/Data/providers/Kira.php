<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * Kira 金流商配置
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        'API_URL' => 'https://api.kira.asia',
        'CLIENT_ID' => '',
        'CLIENT_SECRET' => '',
        'CLIENT_SYMMETRIC_KEY' => '',
        'CLIENT_PRIVATE_KEY' => '',
        'SERVER_PUBLIC_KEY' => '',
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 91,
        'channels' => [
            [
                'currency' => 'MYR',
                'name' => 'FPX Online Banking',
                'key' => 'FPX',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/kira_wtt.png',
                'min_amount' => 30,
                'max_amount' => 30000,
                // 多語系渠道名稱，key 對應 languages.code（不分大小寫）
                'names' => [
                    'en' => 'FPX Online Banking',
                    'ms-my' => 'FPX Online Banking',
                    'zh-tw' => 'FPX 網路銀行',
                    'zh-cn' => 'FPX 网上银行',
                ],
            ],
            [
                'currency' => 'MYR',
                'name' => 'FPX Corporate',
                'key' => 'FPX_C',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/kira_wtt.png',
                'min_amount' => 30,
                'max_amount' => 30000,
                'names' => [
                    'en' => 'FPX Corporate',
                    'ms-my' => 'FPX Corporate',
                    'zh-tw' => 'FPX 企業銀行',
                    'zh-cn' => 'FPX 企业银行',
                ],
            ],
            [
                'currency' => 'MYR',
                'name' => 'FPX E-Wallet',
                'key' => 'E_WALLET_MY',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/kira_wtt.png',
                'min_amount' => 2,
                'max_amount' => 9999,
                'names' => [
                    'en' => 'E-Wallet',
                    'ms-my' => 'E-Wallet',
                    'zh-tw' => '電子錢包',
                    'zh-cn' => '电子钱包',
                ],
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 92,
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(MYR) - Affin Bank', 'code' => 'PHBMMYKL'],
                    ['name' => '(MYR) - AGRO Bank', 'code' => 'AGOBMYKL'],
                    ['name' => '(MYR) - Alliance Bank', 'code' => 'MFBBMYKL'],
                    ['name' => '(MYR) - Alrajhi Bank', 'code' => 'RJHIMYKL'],
                    ['name' => '(MYR) - AmBank', 'code' => 'ARBKMYKL'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'code' => 'BIMBMYKL'],
                    ['name' => '(MYR) - Bank Muamalat', 'code' => 'BMMBMYKL'],
                    ['name' => '(MYR) - Bank Rakyat', 'code' => 'BKRMMYKL'],
                    ['name' => '(MYR) - Bank Simpanan Nasional', 'code' => 'BSNAMYK1'],
                    ['name' => '(MYR) - CIMB Bank', 'code' => 'CIBBMYKL'],
                    ['name' => '(MYR) - Citibank', 'code' => 'CITIMYKL'],
                    ['name' => '(MYR) - Hong Leong Bank', 'code' => 'HLBBMYKL'],
                    ['name' => '(MYR) - HSBC Bank', 'code' => 'HBMBMYKL'],
                    ['name' => '(MYR) - Kuwait Finance House', 'code' => 'KFHOMYKL'],
                    ['name' => '(MYR) - Maybank', 'code' => 'MBBEMYKL'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => 'OCBCMYKL'],
                    ['name' => '(MYR) - Public Bank', 'code' => 'PBBEMYKL'],
                    ['name' => '(MYR) - RHB Bank', 'code' => 'RHBBMYKL'],
                    ['name' => '(MYR) - Standard Chartered Bank', 'code' => 'SCBLMYKX'],
                    ['name' => '(MYR) - United Overseas Bank', 'code' => 'UOVBMYKL'],
                ],
            ],
        ],
    ],
];
