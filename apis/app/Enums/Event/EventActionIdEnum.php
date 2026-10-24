<?php

declare(strict_types=1);

namespace App\Enums\Event;

enum EventActionIdEnum: int
{
    // 存款
    case DEPOSIT = 1;
    // 投注
    case BET = 2;
    // 中奖
    case WIN = 3;
    // 首次存款
    case FIRST_DEPOSIT = 4;
    // 彩票
    case LOTTERY = 5;
    // 大奖（W Jackpot）
    case W_JACKPOT = 7;
    // 老虎机竞赛
    case SLOT_MACHINE_COMPETITION = 8;
    // 老虎机亏损返利
    case SLOT_LOSE_REBATE = 9;
    // 累积奖池
    case JACKPOT = 10;
    // 注册奖励
    case REG_BONUS = 11;
    // 登录
    case LOGIN = 12;
    // 刷新排行榜
    case REFRESH_RANKING = 13;
    // 每日投注一次
    case BET_ONCE_A_DAY = 14;
    // 新邀请玩家优惠
    case NEW_INVITE_PLAYER_OFFERS = 15;
    // 邀请玩家存款
    case INVITE_PLAYER_DEPOSIT = 16;
    // 每周救援金（對應 TransactionEnum::WEEKLY_RESCUE_FUND）
    case WEEKLY_RESCUE_FUND = 17;
    // 邀请玩家优惠
    case INVITE_PLAYER_OFFERS = 31;
    // 首存自动
    case FIRST_DEPOSIT_AUTO = 51;
    // 存款自动
    case DEPOSIT_AUTO = 52;
    // 首存手动
    case FIRST_DEPOSIT_MANUAL = 53;
    // 存款手动
    case DEPOSIT_MANUAL = 54;
    // 每日领取一次
    case PICK_UP_ONCE_A_DAY = 55;
    // 每日领取一次自动
    case PICK_UP_ONCE_A_DAY_AUTO = 56;
    // 存款周期自动
    case DEPOSIT_CYCLE_TIME_AUTO = 57;
    // 存款周期
    case DEPOSIT_CYCLE_TIME = 58;
    // 每日連續儲值（對應 TransactionEnum::DAILY_CONSECUTIVE_DEPOSIT_REWARD）
    case DAILY_CONSECUTIVE_DEPOSIT = 59;
    // tpower 邀請玩家活動
    case TPOWER_INVITE_PLAYER = 60;
    // 週期性領取；週期由 example.invalid.period 決定（0:限定一次, 1:每日, 2:每週, 3:每月）
    case PERIODIC_CLAIM = 61;
}
