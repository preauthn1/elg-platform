<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * MaxPay（PayLoh）金流商配置
 *
 * 來源：docs/integrations/payments/MaxPay/api.md（官方 `PAYLOH_API_DOC-v1.9.pdf`，GitLab project 492 uploads）
 * 憑證欄位由實作決定（見 MaxPayTrait／Deposit／Withdrawal）：API_URL、API_KEY、SECRET_KEY。
 * 簽名 SHA-512、代收與代付回調皆不驗簽（僅靠 IP 白名單）；代付僅支援正式環境。
 *
 * 🔴 **本商碼系是「全名」，與同為新幣代付的 GMPay「短名」相反**——`Bank Of China`↔`BOC`、
 * `Liquid Pay`↔`LiquidPay`、`Citibank`↔`Citi`、`Maybank`↔`MBB`、`Hong Leong`↔`HLB`、
 * `TrustBank`↔`TBL`。兩家銀行覆蓋幾乎完全重疊（同為新幣代付），極易被誤判成同一碼系而「順手
 * 收斂成短碼」，那會直接斷付。任何收斂／稽核動作都必須回各金流商自己的 api.md 判斷。
 *
 * 🔴 **建立本檔 ≠ 生效**：`cash_flows` 114 目前在 VWorld 的 `cash_flow_banks` 是零列，跑
 * `cash-flow:sync --ids=114` 會依本檔建立平台 banks 與 cfb 關聯，屬高影響操作，須先評估：
 * ①名稱模糊比對可能配錯既有 banks（信心度門檻 85，曾有短名誤配案例）②群組層已在服務的碼
 * （例 g70 的 `MBBGBSG`／`ICBKSG`）與 base 層碼不同，sync 建出來的是 base 碼列，兩者不會自動
 * 對齊 ③既有 ACF 的 `available_banks` 不會被 sync 改寫。
 *
 * ⚠️ **代收段跑 sync 前另須確認**：生產 `order_types` 對 cash_flow 113 目前有**重複的 key**
 * （`3` 與 `4` 各兩筆，拆分模式下每個 ACF 各建一組），而 sync 以 key 找既有列，行為需先實測。
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        // 執行期一律讀 agent_cash_flows.info['API_URL']（見 MaxPayTrait），此處僅為寫入該欄的預設種子
        'API_URL' => 'https://example.invalid', // hardcode-ok: 金流商配置種子，官方正式環境 Base URL，可由 DB 覆蓋
        'API_KEY' => '',    // TODO: 待 PayLoh 提供商戶 API Key
        'SECRET_KEY' => '', // TODO: 待 PayLoh 提供商戶 Secret Key
    ],

    // 代收配置（cash_flow 113 = MaxPay - Deposit）
    //
    // key 即官方 `payment_method_id`（字串）。官方列 1=Manual Bank Transfer、2=Cryptocurrency、
    // 3=Online Banking、4=PayNow QR（新加坡）、6=DuitNow（馬來西亞）；此處只列生產實際啟用的
    // 兩種（3／4），其餘未開通不建。
    //
    // ⚠️ type 沿用生產現況 `BANK_TRANSFER`（含 PayNow QR 那筆）。語意上 PayNow QR 更接近
    // QRCODE，但改動會連帶影響前台呈現分類，屬另案，不在本檔逕改。
    'deposit' => [
        'cash_flow_id' => 113,
        'channels' => [
            [
                'currency' => 'SGD',
                'name' => 'MAXPAY - Online Banking',
                'key' => '3',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
            ],
            [
                'currency' => 'SGD',
                'name' => 'MAXPAY - PayNow QR',
                'key' => '4',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
            ],
        ],
    ],

    // 代付配置（cash_flow 114 = MaxPay - Withdrawal）
    //
    // code 即官方 Payout Bank List 的 `bank_name` 值（api.md 第 8 節，v1.9 共 23 個）。
    // 下列 21 個與生產 agent_cash_flows 319 的既有映射逐項相符（20 個）再加 v1.9 新增的 ICBC。
    //
    // 刻意未列入的兩個官方值：`paynow vpa` 與 `paynow uen`。兩者是 PayNow 的收款人識別方式
    // （虛擬支付地址／企業唯一編號）而非銀行，代付請求只有 `bank_account` 與 `bank_holder_name`
    // 兩個收款欄位，無處承載該識別資訊；一般 PayNow 已由 `PayNow` 這個 code 覆蓋。要開通須先
    // 與 PayLoh 確認 `bank_account` 如何填，屬另案（同類處置見 B3Wallet 對 PAYNOW 的說明）。
    'withdrawal' => [
        'cash_flow_id' => 114,
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
                    ['name' => '(SGD) - Citibank', 'code' => 'Citibank'],

                    // 馬來西亞銀行（新加坡分行）
                    ['name' => '(SGD) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(SGD) - Maybank', 'code' => 'Maybank'],
                    ['name' => '(SGD) - RHB Bank', 'code' => 'RHB'],
                    ['name' => '(SGD) - Hong Leong Bank', 'code' => 'Hong Leong'],
                    ['name' => '(SGD) - Alliance Bank', 'code' => 'ABMB'],

                    // 中資銀行（ICBC 為 v1.9 新增；GMPay 官方清單無此行，本商是新幣 ICBC 的出款路徑）
                    ['name' => '(SGD) - Bank of China', 'code' => 'Bank Of China'],
                    ['name' => '(SGD) - ICBC Bank', 'code' => 'ICBC'],

                    // 新加坡數位銀行
                    ['name' => '(SGD) - Trust Bank', 'code' => 'TrustBank'],
                    ['name' => '(SGD) - GXS Bank', 'code' => 'GXS'],
                    ['name' => '(SGD) - Maribank', 'code' => 'Maribank'],

                    // 電子錢包與支付平台
                    ['name' => '(SGD) - Wise', 'code' => 'Wise'],
                    ['name' => '(SGD) - LiquidPay', 'code' => 'Liquid Pay'],
                    ['name' => '(SGD) - Dash', 'code' => 'Dash'],
                    ['name' => '(SGD) - PayNow', 'code' => 'PayNow'],
                    ['name' => '(SGD) - POSB Bank', 'code' => 'POSB'],
                ],
            ],
        ],
    ],
];
