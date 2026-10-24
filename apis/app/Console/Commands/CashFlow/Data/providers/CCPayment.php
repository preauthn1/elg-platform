<?php

declare(strict_types=1);

use App\Console\Commands\CashFlow\Support\DatabaseEnvironment;
use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * CCPayment 金流商配置（工單 target/tickets#4238）
 *
 * 加密貨幣支付閘道，本次接入範圍為 TPower 站台的 USDT-TRC20 代收與代付。
 *
 * 整合模式：Permanent Address Mode（每位會員一個固定收款地址），
 * 選型歷程見 docs/integrations/payments/CCPayment/integration.md 第 6 節。
 *
 * 幣與鏈怎麼放：
 * - 代收：幣與鏈一起跟著支付通道走。order_types.key 的格式是 CCPAYMENT.{幣別代號}_{三方鏈代號}
 *   （ChannelKey），幣別代號是三方 getCoinList 回的 symbol、鏈代號是同一筆 networks 的鍵；
 *   coinId 不寫在代碼或設定裡，執行期依幣別代號向 getCoinList 查（CCPaymentTrait::channelSpec()），
 *   所以哪些幣、哪些鏈可用由三方後台控制。加一條鏈或一種幣＝後台啟用、這裡加一個 deposit channel，代碼不動。
 * - 代付：幣與鏈一起跟著會員所選的提款銀行走。下方 banks 的 code 就是該銀行的三方代碼，
 *   格式 {幣別代號}_{三方鏈代號}（ChannelKey::coinChain()，與支付通道 key 的後半段同一條規則）；
 *   coinId 同樣執行期向 getCoinList 查（CCPaymentTrait::payoutSpec()）。
 * - 商戶 info 只放憑證，不放幣別也不放鏈別。
 * 結構沿用 PayEssence 以父層通道區分（EUSDT／TUSDT）的做法。
 *
 * ! 三方的 chain 值是 TRX 不是 TRC20（實打 getCoinList 確認，
 * ! USDT 的 coinId 為 1280）。我方 banks 名稱用 base 層標準名「USDT - TRC20」，
 * ! 送三方前經銀行的三方代碼 USDT_TRX 拆出 TRX，詳見 integration.md 第 3 節。
 *
 * 金流商戶 info 需要的鍵：API_URL、APP_ID、APP_SECRET、
 * MERCHANT_PAY_NETWORK_FEE（選填，預設 false 由會員負擔網路費）；四個鍵由下方 credentials 模板
 * 隨 agent:add-agent-cash-flow 一次寫進 agent_cash_flows.info，代付的 available_banks 亦由該命令產生。
 * 代收支付通道不走建單流程：會員開存款方式清單時就自動取固定收款地址，
 * 存款單由回調建立（CashFlowEnum::BIND_CRYPTO_WALLET 已含 129），設計索引見 integration.md 第 9 節。
 */
// 永久地址模式的通道不帶金額欄位（amount_field => false）：會員自行轉帳、金額以回調為準，儲值頁只顯示地址。
// 這是 CCPayment 自己的宣告，不是加密貨幣通道的通則（PayEssence 仍要會員輸入金額；
// 理由記在 integration.md 第 9 節「儲值頁的金額欄位」）。
$depositChannels = [
    [
        'currency' => 'USDT',
        'name' => 'CCPayment USDT (TRC20)',
        'key' => 'CCPAYMENT.USDT_TRX', // 幣別代號 USDT＋三方鏈代號 TRX（TRC20 在三方叫 TRX）
        'type' => DepositTypeEnum::CRYPTO->value,
        'amount_field' => false,
        'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/gff/payment/usdt.png', // hardcode-ok: 配置資料檔的 CDN 靜態資源，沿用 PayEssence 既有 USDT 圖示以維持前台一致
    ],
];

// 三方的測試網（ETH Sepolia，測試幣 TETH）只在非正式庫連線宣告：判準看 DB 連線名、不看 APP_ENV
// （正式庫操作是注入 DB_CONNECTION、APP_ENV 不變，理由見 DatabaseEnvironment）。Dev 的 QA 驗收用它跑完整流程、
// 不花真錢，正式庫不會有這條支付通道；怎麼用見 runbook.md 第 8 節。
if (!DatabaseEnvironment::isProductionConnection()) {
    $depositChannels[] = [
        'currency' => 'USDT',
        'name' => 'CCPayment TETH (Sepolia Testnet)',
        'key' => 'CCPAYMENT.TETH_ETH_SEPOLIA', // 幣別代號 TETH＋三方鏈代號 ETH_SEPOLIA
        'type' => DepositTypeEnum::CRYPTO->value,
        'amount_field' => false,
        'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/gff/payment/usdt.png', // hardcode-ok: 同上
    ];
}

return [
    // 金流商戶憑證模板（agent:add-agent-cash-flow 寫入 agent_cash_flows.info）：
    // 非機密值直接給定；APP_ID 與 APP_SECRET 是機密，一律留空，由上線人員在後台填入，
    // 或以 cash-flow:copy-credentials 從已填好的商戶複製（值不離資料庫）。
    'credentials' => [
        'API_URL' => 'https://example.invalid/ccpayment/v2', // hardcode-ok: 三方 API 的 Base URL，出處 api.md「Base URL」一節
        'APP_ID' => '',
        'APP_SECRET' => '',
        'MERCHANT_PAY_NETWORK_FEE' => 'false', // 網路費由誰負擔：false 會員負擔（三方預設）、true 商戶負擔；PM 定案後改此值不改代碼
    ],

    // 代收配置
    'deposit' => [
        'cash_flow_id' => 129,
        'channels' => $depositChannels,
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 130,
        'channels' => [
            [
                'currency' => 'USDT',
                'type' => WithdrawalTypeEnum::CRYPTO,
                'banks' => [
                    // name 必須與 base 層（agent_group_id 0）的標準名逐字相同，sync 才會逐字命中既有列、
                    // 不另建一列讓前台出現重複銀行；與 PayEssence 同名。base 層改名走 bank:set-name。
                    // code 為 {幣別代號}_{三方鏈代號}：代付時拆成幣別代號（查 coinId）與
                    // applyAppWithdrawToNetwork 的 chain 參數值。
                    ['name' => 'USDT - TRC20', 'code' => 'USDT_TRX'],
                ],
            ],
        ],
    ],
];
