<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * A3Pay 金流商配置（SGD only）
 *
 * 來源：docs/integrations/payments/A3Pay/api.md。憑證鍵對應 A3PayTrait 的 FIELD_* 常數。
 * 工單 target/tickets#4394（VWorld-SG）。
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        'API_URL' => 'https://example.invalid',
        'MERCHANT_CODE' => '', // A3Pay 核發，如 SGD00001
        'RSA_PUBLIC_KEY' => '', // A3Pay 公鑰（base64，不含 PEM 頭尾），驗回調／Payout Verification 簽名
        'RSA_PRIVATE_KEY' => '', // 商戶私鑰（PKCS#8 base64，不含 PEM 頭尾），請求簽名；公鑰交 A3Pay 登錄 BO
        'AES_KEY' => '', // A3Pay 核發，Data 加解密
        'CURRENCY' => 'SGD',
    ],

    // 代收配置（cash_flow 131 = D_A3PAY）
    //
    // PaymentMethodId 直接取 channel key（見 A3Pay\Deposit::buildPayinParams），兩個 key 都是 api.md Payin Method 原碼。
    // OB 是否需要 FromCustomerBankCode 文件寫「僅部分支付方式需要」，未列明哪些（open-questions.md Q3），先不掛銀行子選項。
    // 單筆限額 10–5000、手續費 2.3%：A3Pay 於 TG 群口頭告知（2026-09-24），文件未載。
    // 限額寫在 channel 由 cash-flow:sync／agent:add-agent-cash-flow 帶到 OrderType 與 agent_cash_flow_order_types；
    // 手續費（agent_cash_flows.cost）依專案慣例各站 DB 自設、不寫死於此。
    'deposit' => [
        'cash_flow_id' => 131,
        'channels' => [
            [
                'currency' => 'SGD',
                'name' => 'A3Pay - Online banking',
                'key' => 'OB',
                'type' => DepositTypeEnum::ONLINE_PAYMENT->value,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
            [
                'currency' => 'SGD',
                'name' => 'A3Pay - QR code',
                'key' => 'BQR',
                'type' => DepositTypeEnum::QRCODE->value,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
        ],
    ],

    // 代付配置（cash_flow 132 = W_A3PAY）
    //
    // 單筆限額 50–5000、手續費 1.3%（TG 群口頭告知，2026-09-24）。代付的每商戶限額落在 agent_cash_flows.info
    // 的 min_amount／max_amount（WithdrawalPayoutService 選渠道時過濾），由 agent:add-agent-cash-flow 從
    // cash_flows.info[SGD] 帶入；本檔的 channel min/max 只在提款銀行列限額為 0 時補值。
    //
    // code 為 api.md Bank Appendix 的 A3Pay 銀行碼（2026-09-22 文件版；上線前以 GetSupportedBankList 再核一次）。
    // name 一律用 VWorld 正式庫 banks 表 agent_group_id=0 的既有 SGD 名稱（2026-09-22 查證 20 家皆存在且啟用），
    // 讓 cash-flow:sync 對到既有平台銀行、不新建重複列。
    'withdrawal' => [
        'cash_flow_id' => 132,
        'channels' => [
            [
                'currency' => 'SGD',
                'min_amount' => 50,
                'max_amount' => 5000,
                // SGD 在主幣 MYR 的站點是外幣銀行；主幣為 SGD 的站點由 cash-flow:sync 的主幣正規化翻成 BANK。
                // 不可寫 BANK：Dev 連線不在 site_environments.php、不做正規化，會把 base 層 20 家 SGD 銀行
                // 從 FOREIGN_BANK 改成 BANK，其他 SGD 金流商全掛在這些列上（2026-09-24 dev 乾跑抓到）。
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['name' => '(SGD) - DBS Bank Limited', 'code' => 'SDBS'],
                    ['name' => '(SGD) - POSB Bank', 'code' => 'POSB'],
                    ['name' => '(SGD) - United Overseas Bank', 'code' => 'UOB'],
                    ['name' => '(SGD) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(SGD) - Citibank Singapore', 'code' => 'CITIS'],
                    ['name' => '(SGD) - HSBC Bank', 'code' => 'HSBCS'],
                    ['name' => '(SGD) - Standard Chartered Bank', 'code' => 'SCBS'],
                    ['name' => '(SGD) - Maybank Singapore', 'code' => 'MBBS'],
                    ['name' => '(SGD) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(SGD) - Bank of China Limited', 'code' => 'BOC'],
                    ['name' => '(SGD) - Hong Leong Bank', 'code' => 'HLB'],
                    ['name' => '(SGD) - RHB Bank', 'code' => 'RHB'],
                    ['name' => '(SGD) - Alliance Bank', 'code' => 'ABMB'],
                    ['name' => '(SGD) - Trust Bank', 'code' => 'TBL'],
                    ['name' => '(SGD) - GXS Bank', 'code' => 'GXS'],
                    ['name' => '(SGD) - Maribank', 'code' => 'Maribank'],
                    ['name' => '(SGD) - Wise', 'code' => 'Wise'],
                    ['name' => '(SGD) - Dash', 'code' => 'DASH'],
                    ['name' => '(SGD) - LiquidPay', 'code' => 'LiquidPay'],
                    ['name' => '(SGD) - PayNow', 'code' => 'PayNow'],
                ],
            ],
        ],
    ],
];
