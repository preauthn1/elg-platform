<?php

declare(strict_types=1);

namespace App\Enums\Transaction;

enum WalletTransactionEnum: int
{
    case FROM_MAIN_WALLET = 1;  // 主錢包轉入遊戲錢包
    case TO_MAIN_WALLET = 2;  // 遊戲錢包轉入主錢包
    case GAME_WALLET_TO_VENDOR = 3;  // 遊戲錢包轉入遊戲商
    case GAME_WALLET_FROM_VENDOR = 4;  // 遊戲商轉入遊戲錢包
    case GAME_WALLET_ORDER_DEPOSIT = 5;    // 遊戲錢包訂單儲值
    case GAME_WALLET_BACKEND_DEPOSIT = 6; // 代理後台開分
    case GAME_WALLET_EVENT_REWARD = 7; // 活動派彩
    case GAME_WALLET_BACKEND_MAKE_UP = 8; // 代理後台補分
    case GAME_WALLET_BACKEND_DEDUCT = 9; // 代理後台扣分
    case GAME_WALLET_CHECK_IN = 10; // 打卡活動
    case EVENT_WALLET_JOIN = 11; // 活動錢包增加獎金
    case EVENT_WALLET_TO_VENDOR = 12; // 活動錢包轉入遊戲商
    case EVENT_WALLET_FROM_VENDOR = 13; // 遊戲商轉入活動錢包
    case EVENT_WALLET_INIT = 14; // 活動錢包重置歸零
    case EVENT_V2_OPERATOR_CHANGE = 15; // 後台活動錢包操作異動
    case NEWBIE_CHALLENGE_OPERATOR_CHANGE = 43; // 後台活動錢包操作異動(新人挑戰)
    case SEAMLESS_WALLET_BET_SETTLE = 27;  // 單錢包遊戲進行中（session 標記；含投注 / 派彩 / 累進派彩 / 退款等所有 in-game 金額異動）
    case SEAMLESS_WALLET_CANCEL = 28;  // 單錢包遊戲取消注單補點（2026-05-19 起 changeBalance 不主動產生；僅 controller 顯式 setType）
    case SEAMLESS_WALLET_CHANGE = 29;  // 單錢包遊戲結算改單補點（2026-05-19 起 changeBalance 不主動產生；僅 controller 顯式 setType）
    case SEAMLESS_WALLET_DEPOSIT = 32;  // 單錢包遊戲-钱包充值 (平台钱包转至特殊游戏钱包)
    case SEAMLESS_WALLET_WITHDRAW = 33;  // 單錢包遊戲-钱包提款 (游戏钱包转回平台钱包)
    case TO_VENDOR_FAILED_REFUND = 42;  // 轉入遊戲商失敗退回平台錢包
}
