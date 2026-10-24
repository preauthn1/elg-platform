<?php

declare(strict_types=1);

namespace App\Enums;

enum RollingChipLogTypeEnum: int
{
    /**
     * 活動獎勵
     */
    case REWARD = 1;

    /**
     * 單錢包投注
     */
    case BET_SETTLE = 2;

    /**
     * 單錢包取消投注
     */
    case BET_CANCEL = 3;

    /**
     * 代理獎勵
     */
    case AGENT_REWARD = 4;

    /**
     * 代理後台贈送禮碼
     */
    case AGENT_BACKEND_SEND = 5;

    /**
     * 投注活動獎勵
     */
    case BET_EVENT_REWARD = 6;

    /**
     * 登入活動獎勵
     */
    case LOGIN_EVENT_REWARD = 7;

    /**
     * 回收登入活動獎勵
     */
    case RECOVER_EVENT_REWARD = 8;

    /**
     * 首充獎勵
     */
    case FIRST_DEPOSIT_REWARD = 9;

    /**
     * 註冊蹭送
     */
    case REGISTER_REWARD = 10;

    /**
     * 綁定手機贈送
     */
    case BIND_MOBILE_PHONE_REWARD = 11;

    /**
     * 每日充獎勵
     */
    case DEPOSIT_ONCE_A_DAY_REWARD = 12;

    /**
     * 邀請玩家優惠
     */
    case INVITE_PLAYER_REWARD = 13;

    /**
     * W-Jackpot
     */
    case W_Jackpot = 14;

    /**
     * W-Jackpot 6D
     */
    case W_Jackpot_6D = 15;

    /**
     * 老虎積爭霸
     */
    case SLOT_MACHINE_COMPETITION = 16;

    /**
     * 輸分回扣
     */
    case SLOT_LOSE_REBATE = 17;

    /**
     * 積寶活動
     */
    case Event_Jackpot = 18;

    /**
     * VIP
     */
    case VIP_REWARD = 19;

    /**
     * 充值活動獎勵
     */
    case DEPOSIT_REWARD = 20;

    /**
     * 刷新排行榜活動
     */
    case REFRESH_RANKING = 21;

    /**
     * 每日投注
     */
    case DAILY_BET = 22;

    /**
     * 優惠碼
     */
    case COUPON_CODE = 23;

    /**
     * 周期充值活動返利
     */
    case DEPOSIT_CYCLE_TIME_REWARD = 24;

    /**
     * 任務獎勵
     */
    case MISSION_REWARD = 25;

    /**
     * 代理後台扣除禮碼
     */
    case AGENT_BACKEND_DEDUCT = 26;

    /**
     * 老虎機排行榜
     */
    case SLOT_COMPETITION = 101;
}
