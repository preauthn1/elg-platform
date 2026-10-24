<?php

declare(strict_types=1);

namespace App\Enums\Transaction;

enum TransactionEnum: int
{
    case TO_VENDOR = 1;    // 平台轉入遊戲商
    case FROM_VENDOR = 2;  // 遊戲商轉入平台
    case ORDER_DEPOSIT = 3;    // 訂單儲值
    case DEPOSIT = 4;  // 開分
    case WITHDRAWAL = 5;  // 洗分
    case SEND_RED_ENVELOPE = 6;  // 發紅包
    case RECEIVE_RED_ENVELOPE = 7;  // 領紅包
    case RED_ENVELOPE_CANCELLATION = 8;  // 紅包取消(用戶手動)
    case RED_ENVELOPE_REFUND = 9;  // 紅包退還(系統自動)
    case CHECKIN_REWARD = 10;  // 打卡獎勵
    case REFUSE_TO_WITHDRAWAL = 11;  // 拒絕洗分
    case REFUND_OF_WITHDRAWAL = 12;  // 退還洗分
    case ACTIVITY_AWARD = 13;  // 活動獎勵
    case MAKE_UP = 14;  // 補帳
    case LOGIN_EVENT_REWARDS = 15;  // 登入活動獎勵
    case RECYCLE_LOGIN_ACTIVITY_REWARDS = 16;  // 回收登入活動獎勵
    case V_REWARD = 17;  // 全民代理
    case V_JACKPOT_REWARDS = 18;  // V-JACKPOT
    case FIRST_DEPOSIT_REWARDS = 19;  // 首充活動返利
    case REGISTRATION_GIFT = 20;  // 註冊送
    case BIND_MOBILE_PHONE_GIFT = 21;  // 綁定手機送
    case OTA_DEDUCTION = 22;  // 預付網扣款
    case OTA_REFUND = 23;  // 預付網退款
    case DEPOSIT_REWARDS = 24;  // 充值活動返利
    case PICK_UP_ONCE_A_DAY = 25;  // 每日充值活動返利
    case INVITE_PLAYER_OFFERS = 26;  // 邀請玩家活動返利
    case SEAMLESS_WALLET_BET_SETTLE = 27;  // 單錢包遊戲進行中（session 標記；含投注 / 派彩 / 累進派彩 / 退款等所有 in-game 金額異動）
    case SEAMLESS_WALLET_CANCEL = 28;  // 單錢包遊戲取消注單補點（2026-05-19 起 changeBalance 不主動產生；僅 controller 顯式 setType）
    case SEAMLESS_WALLET_CHANGE = 29;  // 單錢包遊戲結算改單補點（2026-05-19 起 changeBalance 不主動產生；僅 controller 顯式 setType）
    case W_JACKPOT_REWARD = 30;  // W-Jackpot獎勵
    case W_JACKPOT_6D_REWARD = 31;  // W-Jackpot 6D獎勵
    case VIP_REWARD = 32;  // VIP獎勵
    case ADJUSTMENT = 33;  // 調帳
    case TO_GAME_WALLET = 34;  // 主錢包轉入遊戲錢包
    case FROM_GAME_WALLET = 35;  // 遊戲錢包轉入主錢包
    case DEDUCT = 36; // 扣分
    case MEMBER_TRANSFER_OUT = 37;  // 掃碼轉帳(轉出)
    case MEMBER_TRANSFER_IN = 38;  // 掃碼轉帳(轉入)
    case CYCLE_DEPOSIT_EVENT_REWARD = 39;  // 周期充值活動返利
    case DAILY_CONSECUTIVE_DEPOSIT_REWARD = 40;  // 每日連續儲值活動
    case WEEKLY_RESCUE_FUND = 41;  // 每周救援金
    case TO_VENDOR_FAILED_REFUND = 42;  // 轉入遊戲商失敗退回平台錢包

    /**
     * Event V2
     */
    case TO_EVENT_V2_WALLET = 70;  // 主錢包轉入活動錢包
    case EVENT_V2_BONUS = 71;  // 活動錢包轉入主錢包

    /**
     * Event
     */
    case SLOT_BATTLE = 101;  // 老虎機爭霸戰
    case LOSE_REBATE = 102;  // lose rebate
    case JACKPOT = 103;  // jackpot
    case PROMOTION_WALLET_REWARD = 104;  // 優惠錢包獎勵
    case SHAREHOLDER_REWARD = 105;  // 分桶獎勵
    case COUPON_CODE = 107;  // 優惠碼
    case PROFIT_SHARE_REWARD = 108;  // 佔成結算
    case MISSION_REWARD = 109;  // 每日任務獎勵

    /**
     * Newbie Challenge (新人專享挑戰活動)
     */
    case NEWBIE_CHALLENGE_CHALLENGE_FLOW = 111;  // 新人專享挑戰-活動金流水鎖(flow_limits.type)
    case NEWBIE_CHALLENGE_BONUS_FLOW = 112;  // 新人專享挑戰-彩金流水鎖(flow_limits.type)
    case NEWBIE_CHALLENGE_BONUS_PAYOUT = 114;  // 新人專享挑戰-彩金撥入真金錢包(主錢包)
}
