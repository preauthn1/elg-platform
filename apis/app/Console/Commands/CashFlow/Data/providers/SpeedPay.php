<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * SpeedPay (SE PayCenter / G.B.P PayCenter) 金流商配置
 *
 * 見 docs/integrations/payments/SpeedPay/api.md（三方規格 v2026.001）
 *
 * ! 工單 #4172。SpeedPay 與 PayMate 是同一份 SE PayCenter 協定的兩組商戶，
 * ! 共用 PayMate 實作（config/payment.php 的 SPEEDPAY 註冊），不另立 Provider 目錄。
 * ! 兩者的差異全部由本檔承載：端點對照、憑證、通道費率、代付銀行清單。
 *
 * ! 端點差異（關鍵）：PayMate 商戶 PM134126 的 P2PDEPOSIT 走 /cashier，
 * ! 本商戶（SPMVW）兩個通道皆走 /qrh5——依 VWorld 舊系統 4 筆代收配置實證
 * ! （agent_cash_flows 280/281/282/283，info.APIUrl 皆為 https://example.invalid/qrh5）。
 * ! 故必須以 credentials.ENDPOINT_BY_PAYMENT_CODE 覆寫 Deposit 的類別常數預設值。
 *
 * ! 目前僅開通馬來西亞 MYR（DUITNOWP2P / P2PDEPOSIT）。三方 §5.3 MY 另列
 * ! DUITNOWQR / FPX / REFQR，屬廠商全局清單、本商戶未開通，切勿自行加入。
 *
 * ! 憑證刻意留空：本商戶為正式營運商戶，MERCHANT_NO / MD5_KEY 不入版控，
 * ! 由 cash-flow:sync 建立後於代理後台填入實值（工單 #3557 商戶資訊）。
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        // base host 不含 /qrh5：端點由 PayMateTrait::sendRequestRaw 以 API_URL . $path 拼接，
        // 舊系統存的是含 /qrh5 的完整 URL，遷入時必須去掉尾綴否則會打成 /qrh5/qrh5
        'API_URL' => 'https://example.invalid', // hardcode-ok: 本檔即金流商配置來源（seed 模板），三方 host 依規範寫於此
        'MERCHANT_NO' => '',
        'MD5_KEY' => '',
        // 共用 PayMate 實作 ⇒ getDriverName() 由目錄名推得會標成 "PayMate"，
        // 使 log／例外訊息／Http::logAction／代付查表灰度閘門全部指向另一組商戶。
        // 以此欄位覆寫為實際商戶名（見 Payment\Traits\DriverTrait::DRIVER_LABEL_FIELD）
        'DRIVER_LABEL' => 'SpeedPay',
        // 本商戶專屬「paymentCode → 代收端點」對照，覆寫 PayMate\Deposit 的類別常數
        'ENDPOINT_BY_PAYMENT_CODE' => [
            'DUITNOWP2P' => '/qrh5',
            'P2PDEPOSIT' => '/qrh5',
        ],
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 127,
        'channels' => [
            [
                'currency' => 'MYR',
                // ! cost／sorts／image_path 必須與舊 SpeedPay 通道逐字相同——本案是「替代」
                // ! 而非新增入口，會員端不應察覺差異。
                // ! name 例外：舊值為 `SpeedPay - Duitnow`（n 小寫），已於 2026-08-24 連同各站
                // ! 既有資料統一改為品牌正確拼法 DuitNow，故此處直接採正確拼法，新建站即一致。
                'name' => 'SpeedPay - DuitNow',
                // cost 取舊系統實值（agent_cash_flows 280/281 cost=0.0200）
                'cost' => 0.02,
                'key' => 'DUITNOWP2P',
                'type' => DepositTypeEnum::QRCODE->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/TPower/logo/payment_duitnow.png', // hardcode-ok: 沿用舊 order_type 513 的 icon（兩通道 icon 不同，且 bucket 亦不同）
                // 舊系統此通道成交極少（7 筆），min 取實際最小成交 5；max 沿用平台層上限
                'min_amount' => 5,
                'max_amount' => 20000,
            ],
            [
                'currency' => 'MYR',
                // ! 主力通道：name／cost／sorts／image_path 與舊 order_type 514 逐字相同
                'name' => 'SpeedPay - P2P',
                // cost 取舊系統實值（agent_cash_flows 282/283 cost=0.0100）
                'cost' => 0.01,
                'key' => 'P2PDEPOSIT',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/cashflow/SpeedPay.png', // hardcode-ok: 沿用舊 order_type 514 的 icon（bucket 與 DuitNow 那支不同，非筆誤）
                // 主力通道：舊系統 acf 282 自 2026-02 起 29,196 筆，實際成交區間 10 ~ 20000
                'min_amount' => 10,
                'max_amount' => 20000,
            ],
        ],
    ],

    // 代付配置
    //
    // ! 本商戶（SPMVW）在舊系統沒有任何代付配置、亦無代付實績；工單 #3557 僅涵蓋代收。
    // ! 以下銀行清單取三方 api.md §5.4 MY 區與 PayMate 已驗證通道的交集（19 家）：
    // ! v2026.001 的 MY 區共 35 家，PayMate（v2026.002）用的 20 家中僅 BOOB（Boost Bank）
    // ! 未收錄於 v2026.001，故排除。上線前須向 SpeedPay 確認代付開通狀態與實際可用銀行。
    'withdrawal' => [
        'cash_flow_id' => 128,
        'channels' => [
            [
                'currency' => 'MYR',
                'type' => WithdrawalTypeEnum::BANK,
                // name 一律採 Data/banks/MYR.php 的 standard_name（非別名、非 banks 表現值），
                // 由 matcher 經 aliases 對應到各站 banks row，避免建立重複銀行。
                // ! Touch n GO 尤須注意：PayMate 配置沿用的舊名 '(MYR) - Touch N Go eWallet'
                // ! 已降為 MYR.php 的 alias，寫舊名會匹配到各站已停用的舊 row。
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
                ],
            ],
        ],
    ],
];
