<?php

namespace target\support\Enums;

class CashFlowEnum
{
    //代收
    const DEPOSIT_NCPAY = 2;  //NCPay
    const DEPOSIT_ALLPAY = 3;  //ALLPay
    const DEPOSIT_USDT = 4;  //USDT
    const DEPOSIT_AUTO_DEPOSIT = 5;     //自助開分
    const DEPOSIT_MARS_CARD = 7;  //Mars Card
    const DEPOSIT_FAST_PAY = 8;  //Fast Pay
    const DEPOSIT_ASIA_LIFE_PAY = 12;  //AsiaLifePay
    const DEPOSIT_TOUCHN_GO = 13;  //Touch'n GO
    const DEPOSIT_XPAY = 16;  //X-Pay
    const DEPOSIT_BAYAR_NOW = 18;  //BayarNow
    const DEPOSIT_TELCO = 20;  //TELCO
    const DEPOSIT_AUTO_BANK_PAY = 21;  //AutoBankPay
    const DEPOSIT_QR_RELOAD = 22;  //QRReload
    const DEPOSIT_BIG_PAY = 24;  //BigPay
    const DEPOSIT_MB88_PAY = 25;  //Mb88Pay - PMGateway
    const DEPOSIT_SURE_PAY = 26;  //SurePay - E-Wallet
    const DEPOSIT_ALLPAY_SGD = 29;  //AllPay(SGD)
    const DEPOSIT_SURE_PAY_IDR = 30;  //SurePayIDR
    const DEPOSIT_DG_PAY = 33;  //DGPay
    const DEPOSIT_FEI_BAO_PAY = 34;  //FeiBaoPay
    const DEPOSIT_FON_PAY = 35;  //FonPay
    const DEPOSIT_DG_PAY_SGD = 38;  //DGPay(SGD)
    const DEPOSIT_SE_PAY_CENTER = 42;  //SE PayCenter自動開分(卡對卡)
    const DEPOSIT_DG_PAY_THB = 44;  //DGPay(THB)
    const DEPOSIT_DG_PAY_IDR = 45;  //DGPay(IDR)
    const DEPOSIT_VG_PAY = 55;  // VGPay
    const DEPOSIT_TNGPIN_PAY = 58;  // TNGPinPay
    const DEPOSIT_DG_PAY_THB_V2 = 59;  //DGPay(THB)
    const DEPOSIT_GFF_PAY = 61;  // GFFPay
    const DEPOSIT_LEGEND_PAY = 63;  // LegendPay 代收 (INR 印度盧比)
    
    //代付/
    const WITHDRAW_AUTO_WITHDRAWAL = 6;     //自助洗分
    const WITHDRAW_AT_PAY = 11;  //AT Pay
    const WITHDRAW_GOLD_PAY = 14;  //GoldPay
    const WITHDRAW_BAYAR_NOW = 18;  //代繳水電費
    const WITHDRAW_ALLPAY = 23;  //AllPay
    const WITHDRAW_FAST_PAY = 27;  //FastPay
    const WITHDRAW_ALLPAY_SGD = 28;  //AllPay(SGD)
    const WITHDRAW_FEI_BAO_PAY = 36;  //FeiBaoPay
    const WITHDRAW_FON_PAY = 37;  //FonPay
    const WITHDRAW_VG_PAY = 56;  // VGPay
    const WITHDRAW_LEGEND_PAY = 64;  // LegendPay 代付 (INR 印度盧比)

    //類型
    const TYPE_DEPOSIT = 1;   //線上支付
    const TYPE_AUTO_DEPOSIT = 2;   //2線下支付
    const TYPE_WITHDRAW = 3;   //代付
    const TYPE_AUTO_WITHDRAW = 4;   //自助洗分
}
