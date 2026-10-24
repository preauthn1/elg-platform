<?php

namespace App\Enums;

class SmsEnum 
{
    //類型
    Const TYPE_VERIFICATION = 1;    //資料驗證
    Const TYPE_FORGET_PASSWORD = 2;  //忘記密碼
    Const TYPE_RETRIEVE_PASSWORD = 3;    //找回密碼
    Const TYPE_WITHDRAWAL = 4;  //洗分驗證
    Const TYPE_PAYMENT_PASSWORD = 5;  //支付密碼
}