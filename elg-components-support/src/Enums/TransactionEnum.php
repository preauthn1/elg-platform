<?php

namespace target\support\Enums;

class TransactionEnum
{
    //類型
    const TYPE_TO_VENDOR = 1;    //平台轉入遊戲商
    const TYPE_FROM_VENDOR = 2;  //遊戲商轉入平台
    const TYPE_ORDER_DEPOSIT = 3;    //訂單儲值
    const TYPE_DEPOSIT = 4;  //開分
    const TYPE_WITHDRAWAL = 5;  //洗分
    const TYPE_SEND_RED_ENVELOPE = 6;  //發紅包
    const TYPE_RECEIVE_RED_ENVELOPE = 7;  //領紅包
    const TYPE_RED_ENVELOPE_CANCELLATION = 8;  //紅包取消(用戶手動)
    const TYPE_RED_ENVELOPE_REFUND = 9;  //紅包退還(系統自動)
    const TYPE_TRANSFER_IN_GIFT = 10;  //開分(贈點)
    const TYPE_REFUSE_TO_WITHDRAWAL = 11;  //拒絕洗分
    const TYPE_REFUND_OF_WITHDRAWAL = 12;  //退還洗分
    const TYPE_ACTIVITY_AWARD = 13;  //活動獎勵
    const TYPE_MAKE_UP = 14;  //補帳
    const TYPE_LOGIN_EVENT_REWARDS = 15;  //登入活動獎勵
    const TYPE_RECYCLE_LOGIN_ACTIVITY_REWARDS = 16;  //回收登入活動獎勵
    const TYPE_V_REWARD = 17;  //全民代理
    const TYPE_V_JACKPOT_REWARDS = 18;  //V-JACKPOT
    const TYPE_FIRST_DEPOSIT_REWARDS = 19;  //首充活動返利
    const TYPE_REGISTRATION_GIFT = 20;  //註冊送
    const TYPE_BIND_MOBILE_PHONE_GIFT = 21;  //綁定手機送
    const TYPE_OTA_DEDUCTION = 22;  //預付網扣款
    const TYPE_OTA_REFUND = 23;  //預付網退款
    const TYPE_DEPOSIT_REWARDS = 24;  //充值活動返利
    const TYPE_PICK_UP_ONCE_A_DAY = 25;  //每日充值活動返利
    const TYPE_INVITE_PLAYER_OFFERS = 26;  //邀請玩家活動返利
    const TYPE_SINGLE_WALLET_BET = 27;  //單錢包遊戲投注
    const TYPE_SINGLE_WALLET_CANCEL_MAKE_UP = 28;  //單錢包遊戲取消注單補點
    const TYPE_CHANGE_ORDER_MAKE_UP = 29;  //單錢包遊戲改單補點
    const TYPE_W_JACKPOT_REWARD = 30;  //W-Jackpot獎勵
    const TYPE_W_JACKPOT_6D_REWARD = 31;  //W-Jackpot 6D獎勵
    const TYPE_VIP_REWARD = 32;  //VIP獎勵
    const TYPE_TO_GAME_WALLET = 33;  //主錢包轉入遊戲錢包
    const TYPE_FROM_GAME_WALLET = 34;  //遊戲錢包轉入主錢包
    const TYPE_PROMOTION_WALLET_REWARD = 104;  //優惠錢包獎勵

    //狀態編號; 其他可參考error_message代碼
    const STATUS_SUCCESS = 1000;
    const STATUS_FAIL = 0;//遊戲商轉入或轉出成功前的狀態或失敗
    const STATUS_REVIEW = 9;  //未審核前 狀態先保持在9

    // 禮碼type;
    const TYPE_ROLLING_CHIP_REWARD = 1; //活動獎勵
    const TYPE_ROLLING_CHIP_BET_SETTLE = 2; //單錢包投注
    const TYPE_ROLLING_CHIP_BET_CANCEL = 3; //單錢包取消投注
    const TYPE_AGENT_REWARD = 4; //代理獎勵
    const TYPE_ROLLING_CHIP_VIP_REWARD = 5; //VIP獎勵

    const TYPE_VIP_FLOW_LIMIT_CS = 1; //客服
    const TYPE_VIP_FLOW_LIMIT_BACKEND = 2; //代理後台
    const TYPE_VIP_FLOW_LIMIT_ORDER = 3; //訂單儲值

    const TYPE_PROMOTION_WALLET_TO_VENDOR = 1;    //平台轉入遊戲商
    const TYPE_PROMOTION_WALLET_FROM_VENDOR = 2;  //遊戲商轉入平台
    const TYPE_PROMOTION_WALLET_EVENT_REWARD = 3;  //活動獎勵
    const TYPE_PROMOTION_WALLET_SUCCESS_UNLOCK = 4;  //優惠錢包達成解綁
    const TYPE_PROMOTION_WALLET_FAILURE_UNLOCK = 5;  //優惠錢包未達成解綁

    // 多錢包type;
    const TYPE_TO_MAIN_WALLET = 1;  //主錢包轉入遊戲錢包
    const TYPE_FROM_MAIN_WALLET = 2;  //遊戲錢包轉入主錢包
    const TYPE_GAME_WALLET_TO_VENDOR = 3;  //遊戲錢包轉入遊戲商
    const TYPE_GAME_WALLET_FROM_VENDOR = 4;  //遊戲商轉入遊戲錢包
    const TYPE_GAME_WALLET_ORDER_DEPOSIT = 5;    //遊戲錢包訂單儲值
    const TYPE_GAME_WALLET_BACKEND_DEPOSIT = 6; //代理後台開分
}
