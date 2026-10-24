<?php

declare(strict_types=1);

use App\Enums\DepositTypeEnum;
use App\Enums\WithdrawalTypeEnum;

/**
 * B3 Wallet 金流商配置
 *
 * 來源：docs/integrations/payments/B3Wallet/api.md
 * 無簽名機制，鑑權僅靠 Authorization: PaymentKey:PaymentPass header + IP 白名單（見 B3WalletTrait）。
 *
 * 收款方式（工單 target/tickets#4300）：三方 2026-09 版對接文件把 DepositInstruction 從
 * 「固定 PAYNOW」開放成 PAYNOW / BANK_TRANSFER / CRYPTO / AUTO，並改為多幣種錢包
 * （SGD/AUD/HKD/MYR/PHP/INR/THB，USDT 專給 CRYPTO）。本檔列出的通道即我方開給會員的方式，
 * 一個通道一筆 AgentCashFlow（各自 cost），key 就是送三方的 DepositInstruction。
 * AUTO 刻意不開：三方不保證實際走哪條通道，對帳時看不出會員實際用什麼付款。
 *
 * 🚫 USDT（鏈上收付）不列通道：2026-10-01 實打與客服確認，三方現階段未為我方商戶開通 USDT，
 * 代收回 422「No active deposit members for currency USDT」、代付回 422「No active payout
 * members available」。Driver 的鏈上收付代碼保留（見 Deposit::buildDepositParams 的 Chain 後綴
 * 與 Withdrawal::buildPayoutParams 的 CRYPTO 分支），待三方開通後在這裡加回通道與銀行即可，代碼不動。
 *
 * 代付支援銀行轉帳與 PayNow 兩種方式（PayNow 子分組未開，原因見下方 withdrawal 區塊）。
 */
return [
    // 金流商憑證配置（將寫入 agent_cash_flows.info）
    'credentials' => [
        // 測試環境 Base URL（api.md 第 5 行）；正式環境須另外跟 B3 Wallet 確認
        'API_URL' => 'https://www.judy88.xin',
        'PAYMENT_KEY' => '',  // TODO: 待 B3 Wallet 提供商戶 PaymentKey
        'PAYMENT_PASS' => '', // TODO: 待 B3 Wallet 提供商戶 PaymentPass
    ],

    // 代收配置（cash_flow 123 = D_B3WALLET）
    //
    // key 即送三方的 DepositInstruction（大小寫敏感），由 Deposit::buildDepositParams 取
    // order_types.key 帶出；鏈上收款以 `.` 後綴帶鏈別（CRYPTO.TRON），該後綴會拆成 Chain 參數。
    // 加一種方式或一條鏈＝三方後台開通後在這裡加一個 channel，代碼不動。
    //
    // ⚠️ 三方文件明載「上述方式須先由平台開通」，未開通的方式建單回 422
    // NO_AVAILABLE_PAYMENT_ACCOUNT。新增 channel 前先跟 B3 Wallet 確認該商戶已開通該方式與幣別。
    'deposit' => [
        'cash_flow_id' => 123,
        'channels' => [
            [
                'currency' => 'SGD',
                'name' => 'B3 Wallet - Paynow',
                'key' => 'PAYNOW',
                'type' => DepositTypeEnum::QRCODE->value,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
            // 付款頁顯示收款銀行資訊、會員自行轉帳，部分通道要求上傳憑證（以付款頁指引為準）
            [
                'currency' => 'SGD',
                'name' => 'B3 Wallet - Online Banking',
                'key' => 'BANK_TRANSFER',
                'type' => DepositTypeEnum::BANK_TRANSFER->value,
                'min_amount' => 10,
                'max_amount' => 5000,
            ],
        ],
    ],

    // 代付配置（cash_flow 124 = W_B3WALLET）
    //
    // 銀行清單＝三方 GET /api/v1/payout-recipient-banks 回傳的代碼中，能對應到我方 SGD 標準銀行檔
    // （app/Console/Commands/CashFlow/Data/banks/SGD.php）的 23 家。name 一律用該檔的 standard_name
    // 逐字照抄，sync 才會命中既有銀行列、不另建重複列。清單與待確認項見工單 target/tickets#4300。
    //
    // 🚫 不列企業帳戶版本（OCBCCORP／DBSCORP／MARICORP／GXSCORP／CIMBBIZ）：會員提款是個人戶，
    // 而且它們與一般戶比對到同一家標準銀行，同時列會讓後者覆蓋前者——實測 dev 的「OCBC Bank」
    // 被綁成三方代碼 OCBCCORP，提款會送錯銀行類型。
    //
    // 🚫 不列 ASPIRE／YOUBIZ／SINGFIN 與澳洲、香港那批代碼：我方標準銀行檔沒有對應條目，
    // 廠商也未提供代碼與銀行名稱的對照表，補了等於把猜的名稱綁到三方代碼上。
    //
    // PAYNOW 不列入：PayNow 走獨立的 PayoutMethod=PAYNOW（見 Withdrawal::buildPayoutParams），
    // 當成一般銀行代碼傳給 BANK_TRANSFER，三方回 400。
    //
    'withdrawal' => [
        'cash_flow_id' => 124,
        'channels' => [
            [
                'currency' => 'SGD',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['name' => '(SGD) - DBS Bank Limited', 'code' => 'DBS'],
                    ['name' => '(SGD) - POSB Bank', 'code' => 'POSB'],
                    ['name' => '(SGD) - United Overseas Bank', 'code' => 'UOB'],
                    ['name' => '(SGD) - OCBC Bank', 'code' => 'OCBC'],
                    ['name' => '(SGD) - Citibank Singapore', 'code' => 'CITI'],
                    ['name' => '(SGD) - HSBC Bank', 'code' => 'HSBC'],
                    ['name' => '(SGD) - Standard Chartered Bank', 'code' => 'SCB'],
                    ['name' => '(SGD) - Maybank Singapore', 'code' => 'MBB'],
                    ['name' => '(SGD) - CIMB Bank', 'code' => 'CIMB'],
                    ['name' => '(SGD) - Bank of China Limited', 'code' => 'BOC'],
                    ['name' => '(SGD) - Hong Leong Bank', 'code' => 'HLB'],
                    ['name' => '(SGD) - RHB Bank', 'code' => 'RHB'],
                    ['name' => '(SGD) - Trust Bank', 'code' => 'TBL'],
                    ['name' => '(SGD) - Maribank', 'code' => 'MARI'],
                    ['name' => '(SGD) - GXS Bank', 'code' => 'GXS'],
                    ['name' => '(SGD) - ANEXT Bank', 'code' => 'ANEXT'],
                    ['name' => '(SGD) - Australia and New Zealand Bank', 'code' => 'ANZ'],
                    ['name' => '(SGD) - ING Bank', 'code' => 'ING'],
                    ['name' => '(SGD) - Bank of East Asia', 'code' => 'BEA'],
                    ['name' => '(SGD) - ICBC Bank', 'code' => 'ICBC'],
                    ['name' => '(SGD) - Wise', 'code' => 'WISE'],
                    ['name' => '(SGD) - MatchMove', 'code' => 'MATCHMOVE'],
                    ['name' => '(SGD) - LiquidPay', 'code' => 'LIQUIDPAY'],
                ],
            ],
        ],
    ],
];
