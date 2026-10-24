<?php

declare(strict_types=1);

/** @noinspection SpellCheckingInspection */

namespace App\Enums;

class CashFlowEnum
{
    // 代收
    public const int DEPOSIT_NCPAY = 2;  // NCPay

    public const int DEPOSIT_ALLPAY = 3;  // ALLPay

    public const int DEPOSIT_USDT = 4;  // USDT

    public const int DEPOSIT_AUTO_DEPOSIT = 5;     // 自助開分

    public const int DEPOSIT_MARS_CARD = 7;  // Mars Card

    public const int DEPOSIT_FAST_PAY = 8;  // Fast Pay

    public const int DEPOSIT_ASIA_LIFE_PAY = 12;  // AsiaLifePay

    public const int DEPOSIT_TOUCHN_GO = 13;  // Touch'n GO

    public const int DEPOSIT_XPAY = 16;  // X-Pay

    public const int DEPOSIT_BAYAR_NOW = 18;  // BayarNow

    public const int DEPOSIT_TELCO = 20;  // TELCO

    public const int DEPOSIT_AUTO_BANK_PAY = 21;  // AutoBankPay

    public const int DEPOSIT_QR_RELOAD = 22;  // QRReload

    public const int DEPOSIT_BIG_PAY = 24;  // BigPay

    public const int DEPOSIT_MB88_PAY = 25;  // Mb88Pay - PMGateway

    public const int DEPOSIT_SURE_PAY = 26;  // SurePay - E-Wallet

    public const int DEPOSIT_ALLPAY_SGD = 29;  // AllPay(SGD)

    public const int DEPOSIT_SURE_PAY_IDR = 30;  // SurePayIDR

    public const int DEPOSIT_DG_PAY = 33;  // DGPay

    public const int DEPOSIT_FEI_BAO_PAY = 34;  // FeiBaoPay

    public const int DEPOSIT_FON_PAY = 35;  // FonPay

    public const int DEPOSIT_DG_PAY_SGD = 38;  // DGPay(SGD)

    public const int DEPOSIT_SE_PAY_CENTER = 42;  // SE PayCenter自動開分(卡對卡)

    public const int DEPOSIT_DG_PAY_THB = 44;  // DGPay(THB)

    public const int DEPOSIT_DG_PAY_IDR = 45;  // DGPay(IDR)

    public const int DEPOSIT_VG_PAY = 55;  // VGPay

    public const int DEPOSIT_TAKA_PAY = 89;  // TakaPay

    public const int DEPOSIT_CCPAYMENT = 129;  // CCPayment（USDT-TRC20 永久地址模式）

    // 代付/
    public const int WITHDRAW_AUTO_WITHDRAWAL = 6;     // 自助洗分

    public const int WITHDRAW_AT_PAY = 11;  // AT Pay

    public const int WITHDRAW_GOLD_PAY = 14;  // GoldPay

    public const int WITHDRAW_BAYAR_NOW = 18;  // 代繳水電費

    public const int WITHDRAW_ALLPAY = 23;  // AllPay

    public const int WITHDRAW_FAST_PAY = 27;  // FastPay

    public const int WITHDRAW_ALLPAY_SGD = 28;  // AllPay(SGD)

    public const int WITHDRAW_FEI_BAO_PAY = 36;  // FeiBaoPay

    public const int WITHDRAW_FON_PAY = 37;  // FonPay

    public const int WITHDRAW_VG_PAY = 56;  // VGPay

    public const int WITHDRAW_TAKA_PAY = 90;  // TakaPay

    public const int WITHDRAW_CCPAYMENT = 130;  // CCPayment（USDT-TRC20）

    // 綁定加密貨幣錢包：存款方式清單會替這些金流商的支付通道帶出 crypto_wallet_id／crypto_wallet_address
    public const array BIND_CRYPTO_WALLET = [
        self::DEPOSIT_FON_PAY, // 地址池模式：營運預建地址再撥給會員，清單只帶已撥出的列
        self::DEPOSIT_CCPAYMENT, // 永久地址模式：見 PERMANENT_CRYPTO_ADDRESS
    ];

    // 永久地址模式（BIND_CRYPTO_WALLET 的子集）：存款方式清單每次都向目前商戶確認地址並存表，商戶換了就換發
    // （開儲值頁就取址，不必等 App 點選，金流商的地址配額可免費擴容；端點 deposit/crypto-address
    // 保留給 App 主動取址或重取）。列在這裡的金流商 Driver 必須實作 SupportsPermanentDepositAddress，
    // MemberCryptoWalletService::walletsForChannels() 取址時會再驗。
    public const array PERMANENT_CRYPTO_ADDRESS = [
        self::DEPOSIT_CCPAYMENT,
    ];
}
