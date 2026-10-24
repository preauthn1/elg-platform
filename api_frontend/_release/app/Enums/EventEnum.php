<?php

namespace App\Enums;

class EventEnum 
{
    //TYPE
    Const TYPE_MISSION = 1;     //任務(一般活動)
    Const TYPE_ACTIVITY = 2;     //活動(VIP活動)
    
    //ACTION
    Const ACTION_DEPOSIT = 1; //儲值滿額
    Const ACTION_BET = 2; //投注滿額
    Const ACTION_WIN = 3; //勝分
    Const ACTION_FIRST_DEPOSIT = 4; //首儲滿額
    Const ACTION_LOTTERY = 5; //V-Jackpot抽獎券
    Const ACTION_INVITE_PLAYER_OFFERS = 31; //邀請玩家優惠
    Const ACTION_FIRST_DEPOSIT_AUTO = 51; //首儲獎勵(自動上分)
    Const ACTION_DEPOSIT_AUTO = 52; //每次充(自動上分)
    Const ACTION_FIRST_DEPOSIT_MANUAL = 53; //首充(手動領取)
    Const ACTION_DEPOSIT_MANUAL = 54; //次次充(手動領取)
    Const ACTION_PICK_UP_ONCE_A_DAY = 55; //每日充
}