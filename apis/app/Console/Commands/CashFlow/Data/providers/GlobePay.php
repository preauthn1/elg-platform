<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * GlobePay 金流商配置
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        'API_URL' => '',
        'MERCHANT_CODE' => '',
        'RSA_PUBLIC_KEY' => '', // 金流商提供的公鑰，驗證 Callback 簽名使用
        'RSA_PRIVATE_KEY' => '', // 商戶私鑰，請求代收代付時生成簽名
        'AES_KEY' => '', // 請求參數加解密
        'CURRENCY' => '',
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 85,
        'channels' => [
            // ! MYR
            [
                'currency' => 'MYR',
                'name' => 'Online banking',
                'key' => 'OB',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/bank/bigpay.png',
                'options' => [
                    ['name' => 'Affin Bank', 'key' => 'MYAFBB'],
                    ['name' => 'Alliance Bank', 'key' => 'MYABMB'],
                    ['name' => 'AmBank', 'key' => 'MYAMBB'],
                    ['name' => 'Argo Bank', 'key' => 'MYAGRO'],
                    ['name' => 'Bank Islam Malaysia', 'key' => 'MYBIMB'],
                    ['name' => 'Bank Rakyat', 'key' => 'MYBKRM'],
                    // 2026-09-07 修正：原值 MYBKRM 是 02-05 建檔時複製 Bank Rakyat 的碼（代收、代付兩段皆錯），
                    // 02-24 只改了代付段。代收 key 與代付 code 同一碼系（其餘 20 碼完全一致），故對齊代付段。
                    // GlobePay 文件未列銀行碼表，此值未經三方文件驗證。生產 order_types 尚無 MYBMMB（兩碼撞成
                    // 一個 order_type 493），須重跑 cash-flow:sync --ids=85 --currency=MYR 並替既有 ACF 補 order type。
                    ['name' => 'Bank Muamalat', 'key' => 'MYBMMB'],
                    ['name' => 'Bank of America', 'key' => 'MYBOFA'],
                    ['name' => 'Bank of China', 'key' => 'MYBOCM'],
                    ['name' => 'Bank Simpanan National', 'key' => 'MYBSNB'],
                    ['name' => 'CIMB Bank', 'key' => 'MYCIMB'],
                    ['name' => 'GX Bank', 'key' => 'MYGXSP'],
                    ['name' => 'Hong Leong Bank', 'key' => 'MYHLBB'],
                    ['name' => 'HSBC Bank', 'key' => 'MYHSBC'],
                    ['name' => 'Maybank', 'key' => 'MYMB2U'],
                    ['name' => 'OCBC Bank', 'key' => 'MYOCBC'],
                    ['name' => 'Public Bank', 'key' => 'MYPUBB'],
                    ['name' => 'RHB Bank', 'key' => 'MYRHBB'],
                    ['name' => 'Standard Chartered Bank', 'key' => 'MYSTCB'],
                    ['name' => 'Touch n Go', 'key' => 'MYTNGO'],
                    ['name' => 'United Overseas Bank', 'key' => 'MYUOBB'],
                ],
            ],
            [
                'currency' => 'MYR',
                'name' => 'FPX',
                'key' => 'FPX',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/bank/bigpay.png',
            ],
            [
                'currency' => 'MYR',
                'name' => 'DuitNow',
                'key' => 'DN',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/bank/bigpay.png',
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 86,
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                'banks' => [
                    ['name' => '(MYR) - Affin Bank', 'code' => 'MYAFBB'],
                    ['name' => '(MYR) - Alliance Bank', 'code' => 'MYABMB'],
                    ['name' => '(MYR) - AmBank', 'code' => 'MYAMBB'],
                    ['name' => '(MYR) - Argo Bank', 'code' => 'MYAGRO'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'code' => 'MYBIMB'],
                    ['name' => '(MYR) - Bank Rakyat', 'code' => 'MYBKRM'],
                    ['name' => '(MYR) - Bank Muamalat', 'code' => 'MYBMMB'],
                    ['name' => '(MYR) - Bank of America', 'code' => 'MYBOFA'],
                    ['name' => '(MYR) - Bank of China', 'code' => 'MYBOCM'],
                    ['name' => '(MYR) - Bank Simpanan National', 'code' => 'MYBSNB'],
                    ['name' => '(MYR) - CIMB Bank', 'code' => 'MYCIMB'],
                    ['name' => '(MYR) - GX Bank', 'code' => 'MYGXSP'],
                    ['name' => '(MYR) - Hong Leong Bank', 'code' => 'MYHLBB'],
                    ['name' => '(MYR) - HSBC Bank', 'code' => 'MYHSBC'],
                    ['name' => '(MYR) - Maybank', 'code' => 'MYMB2U'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => 'MYOCBC'],
                    ['name' => '(MYR) - Public Bank', 'code' => 'MYPUBB'],
                    ['name' => '(MYR) - RHB Bank', 'code' => 'MYRHBB'],
                    ['name' => '(MYR) - Alrajhi Bank', 'code' => 'MYRJHI'],
                    ['name' => '(MYR) - Standard Chartered Bank', 'code' => 'MYSTCB'],
                    ['name' => '(MYR) - Touch n Go', 'code' => 'MYTNGO'],
                    ['name' => '(MYR) - United Overseas Bank', 'code' => 'MYUOBB'],
                ],
            ],
        ],
    ],
];
