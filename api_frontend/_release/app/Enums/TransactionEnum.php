<?php

namespace App\Enums;

class TransactionEnum 
{
    //類型
    Const TYPE_TO_VENDOR = 1;    //平台轉入遊戲商
    Const TYPE_FROM_VENDOR = 2;  //遊戲商轉入平台
    Const TYPE_ORDER_DEPOSIT = 3;    //訂單儲值
    Const TYPE_DEPOSIT = 4;  //開分
    Const TYPE_WITHDRAWAL = 5;  //洗分
    Const TYPE_SEND_RED_ENVELOPE = 6;  //發紅包
    Const TYPE_RECEIVE_RED_ENVELOPE = 7;  //領紅包
    Const TYPE_RED_ENVELOPE_CANCELLATION = 8;  //紅包取消(用戶手動)
    Const TYPE_RED_ENVELOPE_REFUND = 9;  //紅包退還(系統自動)
    Const TYPE_TRANSFER_IN_GIFT = 10;  //開分(贈點)
    Const TYPE_REFUSE_TO_WITHDRAWAL = 11;  //拒絕洗分
    Const TYPE_REFUND_OF_WITHDRAWAL = 12;  //退還洗分
    Const TYPE_ACTIVITY_AWARD = 13;  //活動獎勵
    Const TYPE_MAKE_UP = 14;  //補帳
    Const TYPE_LOGIN_EVENT_REWARDS = 15;  //登入活動獎勵
    Const TYPE_RECYCLE_LOGIN_ACTIVITY_REWARDS = 16;  //回收登入活動獎勵
    Const TYPE_V_REWARD = 17;  //全民代理
    Const TYPE_V_JACKPOT_REWARDS = 18;  //V-JACKPOT
    Const TYPE_FIRST_DEPOSIT_REWARDS = 19;  //首充活動返利
    Const TYPE_REGISTRATION_GIFT = 20;  //註冊送
    Const TYPE_BIND_MOBILE_PHONE_GIFT = 21;  //綁定手機送
    Const TYPE_OTA_DEDUCTION = 22;  //預付網扣款
    Const TYPE_OTA_REFUND = 23;  //預付網退款
    Const TYPE_DEPOSIT_REWARDS = 24;  //充值活動返利
    Const TYPE_PICK_UP_ONCE_A_DAY = 25;  //每日充值活動返利
    Const TYPE_INVITE_PLAYER_OFFERS = 26;  //邀請玩家活動返利
}