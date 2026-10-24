<?php

declare(strict_types=1);

use App\Services\Payment\Providers;

/** @noinspection SpellCheckingInspection */
return [
    'provider' => [
        // A3Pay：SGD 代收代付（VWorld-SG），AES-256-CBC＋RSA SHA-1。工單 target/tickets#4394
        'A3PAY' => Providers\A3Pay\Driver::class,
        'AKPAY' => Providers\AkPay\Driver::class,
        'ALL' => Providers\AllPay\Driver::class,
        // AntzPay ＝ Kira 的 Gateway v1 同源協定，薄繼承殼沿用 Kira 實作（差異僅代付的明確拒絕判定與交易號前綴）
        'ANTZPAY' => Providers\AntzPay\Driver::class,
        'AP8' => Providers\Ap8Pay\Driver::class,
        'B3WALLET' => Providers\B3Wallet\Driver::class,
        'BIGPAYZ' => Providers\BigPayz\Driver::class,
        'BILLIONPAY' => Providers\BillionPay\Driver::class,
        // CCPayment：加密貨幣支付閘道（USDT-TRC20），cash_flows 129/130、
        // third_party_code D_CCPAYMENT/W_CCPAYMENT。工單 target/tickets#4238
        'CCPAYMENT' => Providers\CCPayment\Driver::class,
        'DG' => Providers\DgPay\Driver::class,
        // 全新一組 DGPay（cash_flows 119/120、third_party_code D_DGPAY/W_DGPAY），共用同一 DgPay Driver
        'DGPAY' => Providers\DgPay\Driver::class,
        'EASYPAY' => Providers\EasyPay\Driver::class,
        'EPICPAY' => Providers\EpicPay\Driver::class,
        'FLASHPAY' => Providers\FlashPay\Driver::class,
        'FPAY' => Providers\FPay\Driver::class,
        'GFF' => Providers\GffPay\Driver::class,
        'GLOBEPAY' => Providers\GlobePay\Driver::class,
        'GMPAY' => Providers\GMPay\Driver::class,
        'KIRA' => Providers\Kira\Driver::class,
        'LEGEND' => Providers\LegendPay\Driver::class,
        'MAJESTIC' => Providers\MajesticPay\Driver::class,
        'MAXPAY' => Providers\MaxPay\Driver::class,
        'PAYESSENCE' => Providers\PayEssence\Driver::class,
        'PAYMATE' => Providers\PayMate\Driver::class,
        'PAYPRO' => Providers\PayPro\Driver::class,
        'QUICKPAY' => Providers\QuickPay\Driver::class,
        // SpeedPay ＝ SE PayCenter 同源協定的另一組商戶（cash_flows 127/128、third_party_code
        // D_SPEEDPAY/W_SPEEDPAY），共用 PayMate 實作；端點差異由 Data/providers/SpeedPay.php
        // 的 info[ENDPOINT_BY_PAYMENT_CODE] 承載，不另立 Provider 目錄
        'SPEEDPAY' => Providers\PayMate\Driver::class,
        'SURE' => Providers\Sure\Driver::class,
        'SUREPAY' => Providers\SurePay\Driver::class,
        'TAKAPAY' => Providers\TakaPay\Driver::class,
        'TGPAY' => Providers\TgPay\Driver::class,
        'TNGPIN' => Providers\TngPin\Driver::class,
        'TOPPAY' => Providers\TopPay\Driver::class,
        'TRUSTPAY' => Providers\TrustPay\Driver::class,
        // TTPay ＝ DGPay 換名白牌（cash_flows 125/126、third_party_code D_TTPAY/W_TTPAY），薄繼承殼沿用 DgPay 實作
        'TTPAY' => Providers\TtPay\Driver::class,
        'VADERPAY' => Providers\VaderPay\Driver::class,
        'VTPAY' => Providers\VtPay\Driver::class,
        'YEEPAY' => Providers\YeePay\Driver::class,
        'ZPAY' => Providers\ZPay\Driver::class,
    ],

    /*
     | 代付「本地 bank code → 三方 bank code」改查 cash_flow_banks 的 provider 灰度名單。
     | 值為 getDriverName() 回傳的 provider code，逗號分隔；空＝全部走舊 available_banks JSON。
     | 例：PAYOUT_BANK_LOOKUP_PROVIDERS=SurePay,EpicPay
     |
     | 🔴 灰度一家一家開，禁止一次全開。切之前先跑 cash-flow:verify-payout-bank-parity
     | 確認該 provider 新舊兩條路徑等值——不等值就是有銀行會被三方退。
     |
     | 🔴 名單放進任何一家之前，先確認下面兩處的銀行碼比對都已改成「逐字相同才算命中」：
     | 1. WithdrawalPayoutTrait 的兩條查碼路徑。JSON 路徑用 ==，兩邊都是數字字串時會轉型比對
     |    （'007' == '7' 為 true）；查表路徑用 SQL =，cash_flow_banks.bank_code 的 collation 不分大小寫。
     |    兩條路徑判定不一致，開灰度後同一張提款單可能拿到和現在不同的代付銀行碼。
     | 2. cash-flow:verify-payout-bank-parity 的查表比對。只改第 1 處時驗證器仍是寬鬆比對，
     |    會對只差大小寫的碼給出假的「等值」。
     | 2026-09-15 master 兩處都還沒改，修正在 refactor/payment-bank-convergence 分支；開灰度前，
     | 要嘛整條分支已合進 master，要嘛先把這兩個修正單獨搬進 master。
     */
    'payout_bank_lookup_providers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PAYOUT_BANK_LOOKUP_PROVIDERS', '')),
    ))),
];
