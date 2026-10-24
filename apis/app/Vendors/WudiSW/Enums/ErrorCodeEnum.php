<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW\Enums;

enum ErrorCodeEnum: int
{
    case SUCCESS = 1000; // 請求執行成功
    case AUTHORIZATION_INVALID = 5001; // 錯誤的驗證
    case BAD_FORMAT_PARAMETERS = 5002; // 特定引數(arg)錯誤
    case INSUFFICIENT_BALANCE = 5101; // 該玩家餘額不足
    case UNFINISHED_TRANSACTIONS = 5102; // 尚有交易處理中, 當收到重複請求但事務尚未處理時使用.
    case PLAYER_ACCOUNT_SUSPENDED = 5103; // 玩家帳號被停權 (當 transfer_type 為 end, refund 時不可使用)
    case GAME_CLOSED = 5104; // 遊戲已被關閉 (當 transfer_type 為 end, refund 時不可使用)
    case TRANSACTION_NOT_EXIST = 5105; // 交易不存在 (當 transfer_type 為 start 時不可使用)
    case TRANSACTION_SETTLED = 5106; // 交易已處理，可用於收到重複的加扣款行為
    case DUPLICATE_TRAN_ID = 5107; // 重複的 tran_id
    case SOMETHING_WRONG = 5201; // 發生了以下錯誤 (message需顯示詳細失敗原因)
}
