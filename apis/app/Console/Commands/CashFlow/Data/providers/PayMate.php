<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * PayMate (SE PayCenter / G.B.P PayCenter) 金流商配置
 *
 * 見 docs/integrations/payments/PayMate/api.md
 *
 * ! 目前僅開通馬來西亞 MYR 市場（DUITNOWP2P / P2PDEPOSIT，皆走 /cashier 端點）。
 * ! 代付銀行清單僅涵蓋主流銀行，其餘 api.md 5.4 列出但較冷門的銀行代碼待營運確認後再補。
 * ! 代收/代付限額已依營運提供的實際數值填入（見 cash_flows.php id 121/122）。
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        'API_URL' => 'https://publicapi.paymate.solutions',
        'MERCHANT_NO' => 'PM134126',
        'MD5_KEY' => '',
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 121,
        'channels' => [
            [
                'currency' => 'MYR',
                'name' => 'PayMate - DuitNow',
                'cost' => 0.015,
                'key' => 'DUITNOWP2P',
                'type' => DepositTypeEnum::QRCODE->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/1.png',
                // max 依客戶錢包上限，PayMate 無固定值；此通道明確指定 100000，不沿用 cash_flows.php 平台層 max（30000）
                'min_amount' => 10,
                'max_amount' => 100000,
            ],
            [
                'currency' => 'MYR',
                'name' => 'PayMate - P2P',
                'cost' => 0.013,
                'key' => 'P2PDEPOSIT',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/1.png',
                'min_amount' => 10,
                'max_amount' => 30000,
            ],
        ],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 122,
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                // name 沿用 banks 表既有名稱（見 2.2 配置前的標準動作查詢結果），避免建立重複銀行
                'banks' => [
                    ['name' => '(MYR) - Maybank', 'code' => 'MAYB'],
                    ['name' => '(MYR) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(MYR) - Public Bank', 'code' => 'PBBB'],
                    ['name' => '(MYR) - RHB Bank', 'code' => 'RHBB'],
                    ['name' => '(MYR) - Hong Leong Bank', 'code' => 'HOLB'],
                    ['name' => '(MYR) - Affin Bank', 'code' => 'AFIN'],
                    ['name' => '(MYR) - Bank Simpanan Nasional', 'code' => 'SINA'],
                    ['name' => '(MYR) - AGRO Bank', 'code' => 'AGRO'],
                    ['name' => '(MYR) - Alliance Bank', 'code' => 'ALLI'],
                    ['name' => '(MYR) - AmBank', 'code' => 'MYAB'],
                    ['name' => '(MYR) - Bank Islam Malaysia', 'code' => 'MYIS'],
                    ['name' => '(MYR) - Bank Muamalat', 'code' => 'MUAM'],
                    ['name' => '(MYR) - Bank Rakyat', 'code' => 'RAKY'],
                    ['name' => '(MYR) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(MYR) - Standard Chartered Bank', 'code' => 'STCB'],
                    ['name' => '(MYR) - United Overseas Bank', 'code' => 'UNOB'],
                    ['name' => '(MYR) - Touch n GO', 'code' => 'TNGP'],
                    ['name' => '(MYR) - GX Bank', 'code' => 'GXBK'],
                    ['name' => '(MYR) - Ryt Bank', 'code' => 'RYTB'],
                    ['name' => '(MYR) - Boost Bank', 'code' => 'BOOB'],
                ],
            ],
        ],
    ],
];
