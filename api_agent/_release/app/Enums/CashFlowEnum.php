<?php

namespace App\Enums;

class CashFlowEnum 
{
    //代收
    Const DEPOSIT_NCPAY = 2;  //NCPay
    Const DEPOSIT_ALLPAY = 3;  //ALLPay
    Const DEPOSIT_USDT = 4;  //USDT
    Const DEPOSIT_AUTO_DEPOSIT = 5;     //自助開分
    Const DEPOSIT_MARS_CARD = 7;  //Mars Card
    Const DEPOSIT_FAST_PAY = 8;  //Fast Pay
    Const DEPOSIT_TOUCHN_GO = 13;  //Touch'n GO
    Const DEPOSIT_XPAY = 16;  //X-Pay
    Const DEPOSIT_BAYAR_NOW = 18;  //BayarNow
    Const DEPOSIT_TELCO = 20;  //TELCO
    
    //代付/
    Const WITHDRAW_AUTO_WITHDRAWAL = 6;     //自助洗分
    Const WITHDRAW_AT_PAY = 11;  //AT Pay
    Const WITHDRAW_GOLD_PAY = 14;  //GoldPay
    Const WITHDRAW_BAYAR_NOW = 18;  //代繳水電費
    Const WITHDRAW_ALL_PAY = 23;  //AllPay

    //類型
    Const TYPE_DEPOSIT = 1;   //線上支付
    Const TYPE_AUTO_DEPOSIT = 2;   //2線下支付
    Const TYPE_WITHDRAW = 3;   //代付
    Const TYPE_AUTO_WITHDRAW = 4;   //自助洗分
}