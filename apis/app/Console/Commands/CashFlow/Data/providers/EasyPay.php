<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * EasyPay 金流商配置
 */
return [
    // 代收配置
    'deposit' => [
        'cash_flow_id' => 75,
        'channels' => [
            [
                'currency' => 'SGD',
                'name' => 'EasyPay',
                'key' => 'EasyPay',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/EasyPay.png',
                'options' => [
                    ['name' => 'PayNow', 'key' => 'paynow'],
                    ['name' => 'P2P', 'key' => 'bank_order'],
                ],
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 76,
        'channels' => [
            [
                'currency' => 'SGD',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['name' => '(SGD) - PayNow', 'code' => 'PAYNOW'],
                    ['name' => '(SGD) - Australia And New Zealand Bank (Australia)', 'code' => '9742'],
                    ['name' => '(SGD) - CIMB Bank', 'code' => '7986'],
                    ['name' => '(SGD) - DBS Bank Limited', 'code' => '7171'],
                    ['name' => '(SGD) - OCBC Bank', 'code' => '7339'],
                    ['name' => '(SGD) - United Overseas Bank', 'code' => '7375'],
                    ['name' => '(SGD) - HSBC Bank', 'code' => '7232'],
                    ['name' => '(SGD) - Citibank', 'code' => '7214'],
                    ['name' => '(SGD) - Standard Chartered Bank', 'code' => '9496'],
                    ['name' => '(SGD) - Bank of China', 'code' => '7083'],
                    ['name' => '(SGD) - ICBC Bank', 'code' => '8712'],
                    ['name' => '(SGD) - Societe Generale', 'code' => '7852'],
                    ['name' => '(SGD) - ING Bank', 'code' => '7091'],
                    ['name' => '(SGD) - Trust Bank', 'code' => '9733'],
                    ['name' => '(SGD) - RHB Bank', 'code' => '7366'],
                    ['name' => '(SGD) - Hong Leong Bank', 'code' => '7287'],
                    ['name' => '(SGD) - BNP Paribas', 'code' => '7052'],
                    ['name' => '(SGD) - GXS Bank', 'code' => '9171'],
                    ['name' => '(SGD) - Maribank', 'code' => '9172'],
                    ['name' => '(SGD) - ANEXT Bank', 'code' => '9173'],
                    ['name' => '(SGD) - UniCredit Bank AG', 'code' => '8505'],
                    ['name' => '(SGD) - UBS AG', 'code' => '7685'],
                    ['name' => '(SGD) - Royal Bank of Scotland', 'code' => '7194'],
                    ['name' => '(SGD) - Svenska Handelsbanken AB', 'code' => '8502'],
                    ['name' => '(SGD) - Sumitomo Mitsui Banking Corporation', 'code' => '7472'],
                    ['name' => '(SGD) - State Bank of India', 'code' => '7791'],
                    ['name' => '(SGD) - Singapura Finance Ltd', 'code' => '7314'],
                    ['name' => '(SGD) - Singapore Automated Clearing House', 'code' => '9186'],
                    ['name' => '(SGD) - Sing Investments & Finance Limited', 'code' => '9187'],
                    ['name' => '(SGD) - Siam Commercial Bank', 'code' => '7155'],
                    ['name' => '(SGD) - Bangkok Bank Public Company Limited', 'code' => '7047'],
                    ['name' => '(SGD) - Qatar National Bank', 'code' => '9326'],
                    ['name' => '(SGD) - Bank Negara Indonesia', 'code' => '7056'],
                    ['name' => '(SGD) - Mizuho Bank', 'code' => '7621'],
                    ['name' => '(SGD) - Maybank', 'code' => '7302'],
                    ['name' => '(SGD) - Bank of America', 'code' => '7065'],
                    ['name' => '(SGD) - Bank of East Asia', 'code' => '7092'],
                    ['name' => '(SGD) - Bank of India', 'code' => '7108'],
                    ['name' => '(SGD) - CTBC Bank', 'code' => '9353'],
                    ['name' => '(SGD) - Commerzbank Aktiengesellschaft', 'code' => '8606'],
                    ['name' => '(SGD) - Credit Agricole CIB', 'code' => '7135'],
                    ['name' => '(SGD) - Deutsche Bank', 'code' => '7463'],
                    ['name' => '(SGD) - Far Eastern Int\'l Bank', 'code' => '7143'],
                    ['name' => '(SGD) - First Commercial Bank', 'code' => '7764'],
                    ['name' => '(SGD) - Indian Bank', 'code' => '7250'],
                    ['name' => '(SGD) - Indian Overseas Bank', 'code' => '7250'],
                    ['name' => '(SGD) - POSB Bank', 'code' => 'POSB'],
                ],
            ],
            [
                'currency' => 'HKD',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['name' => 'HSBC', 'code' => '004'],
                    ['name' => 'STANDARD CHARTERED BANK', 'code' => '003'],
                    ['name' => 'THE BANK OF EAST ASIA', 'code' => '015'],
                    ['name' => 'Hang Seng Bank', 'code' => '024'],
                    ['name' => 'FUSION BANK', 'code' => '391'],
                    ['name' => 'CITIBANK', 'code' => '250'],
                    ['name' => 'DAH SING BANK', 'code' => '040'],
                    ['name' => 'DBS BANK', 'code' => '016'],
                    ['name' => 'CMB WING LUNG BANK', 'code' => '020'],
                    ['name' => 'PUBLIC BANK', 'code' => '028'],
                    ['name' => 'OCBC WING HANG BANK', 'code' => '035'],
                    ['name' => 'WELAB BANK', 'code' => '390'],
                    ['name' => 'TAI YAU BANK', 'code' => '038'],
                    ['name' => 'CHINA CONSTRUCTION BANK', 'code' => '009'],
                    ['name' => 'CHINA CITIC BANK', 'code' => '018'],
                    ['name' => 'CHIYU BANKING CORPORATION', 'code' => '039'],
                    ['name' => 'NANYANG COMMERCIAL BANK', 'code' => '043'],
                    ['name' => 'CHONG HING BANK', 'code' => '041'],
                    // ['name' => 'FPS-轉數快', 'code' => 'FPS'],  // 不是銀行
                    ['name' => 'PING AN ONECONNECT BANK', 'code' => '392'],
                    ['name' => 'BANK OF CHINA', 'code' => '012'],
                    ['name' => 'INDUSTRIAL AND COMMERCIAL BANK OF CHINA', 'code' => '072'],
                    ['name' => 'BANK OF COMMUNICATIONS', 'code' => '027'],
                    ['name' => 'SHANGHAI COMMERCIAL', 'code' => '025'],
                    ['name' => 'FUBON BANK', 'code' => '128'],
                    ['name' => 'ZA BANK LIMITED', 'code' => '387'],
                    ['name' => 'LIVI BANK LIMITED', 'code' => '388'],
                    ['name' => 'MOX BANK', 'code' => '389'],
                    ['name' => 'AIRSTAR BANK', 'code' => '395'],
                    ['name' => 'ANT BANK', 'code' => '393'],
                    // ['name' => 'Global Cash (全球付)', 'code' => '930'],  // 不是銀行
                ],
            ],
        ],
    ],
];
