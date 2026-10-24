<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Models\MemberBindingLog;
use App\Models\Order;
use App\Models\RealnameValidationRequest;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Models\WithdrawalOrder;
use Illuminate\Support\Collection;

class SystemNotifyService
{
    /**
     * 訂單
     */
    public function getOrderLogs($startTime, $memberId, $languageId): Collection
    {
        // 查詢訂單資料
        $orders = Order::with([
            'orderType.currency',
            'orderTypeName' => function ($query) use ($languageId) {
                $query->where('language_id', $languageId);
            },
        ])
            ->where('member_id', $memberId)
            ->where('created_at', '>=', $startTime)
            ->get();

        // 格式化資料
        return $orders->map(fn ($order) => [
            'data_name' => 'orders',
            'data_id' => $order->id,
            'tag' => 'orders',
            'type' => 2,
            'content' => $order->orderTypeName?->name ?? $order->orderType->name,
            'content_1' => '',
            'content_2' => '',
            'currency_name' => $order->orderType->currency?->code ?? '',
            'amount' => $order->amount,
            'point' => $order->point,
            'status' => match ($order->status) {
                3 => 'success',
                2 => 'fail',
                default => 'pending',
            },
            'data_type' => 0,
            'time' => $order->updated_at->toFrontendTime()->toDateTimeString(),
        ]);
    }

    /**
     * 提款訂單
     */
    public function getWithdrawalOrderLogs($startTime, $memberId, $languageId): Collection
    {
        // 使用 Eloquent 查詢
        $withdrawalOrders = WithdrawalOrder::with([
            'bank',
            'bank.bankName' => function ($query) use ($languageId) {
                $query->where('language_id', $languageId);
            },
            'bank.currency',
            'transactionLog' => function ($query) use ($memberId) {
                $query->where('type', 5)->where('member_id', $memberId);
            },
        ])
            ->where('member_id', $memberId)
            ->where('created_at', '>=', $startTime)
            ->get();

        // 格式化資料
        return $withdrawalOrders->map(fn ($order) => [
            'data_name' => 'withdrawal_orders',
            'data_id' => $order->id,
            'tag' => 'orders',
            'type' => 1,
            'content' => $order->bank?->bankName?->name ?? $order->bank?->name ?? '',
            'content_1' => $order->account_no,
            'content_2' => $order->account_name,
            'currency_name' => $order->bank->currency->code ?? '',
            'amount' => $order->amount,
            'point' => $order->transactionLog->point ?? 0,
            'status' => match ($order->status) {
                4, 24 => 'success',
                2, 9, 29 => 'fail',
                default => 'pending',
            },
            'data_type' => 0,
            'time' => $order->updated_at->toFrontendTime()->toDateTimeString(),
        ]);
    }

    /**
     * 現金交易紀錄
     */
    public function getTransactionLogs($startTime, $memberId): Collection
    {
        $rewardType = [
            TransactionEnum::ACTIVITY_AWARD->value, // 活動獎勵
            TransactionEnum::LOGIN_EVENT_REWARDS->value, // 登入活動獎勵
            TransactionEnum::V_REWARD->value, // 全民代理
            TransactionEnum::V_JACKPOT_REWARDS->value, // V-JACKPOT
            TransactionEnum::FIRST_DEPOSIT_REWARDS->value, // 首充活動返利
            TransactionEnum::REGISTRATION_GIFT->value, // 註冊送
            TransactionEnum::BIND_MOBILE_PHONE_GIFT->value, // 綁定手機送
            TransactionEnum::DEPOSIT_REWARDS->value, // 充值活動返利
            TransactionEnum::PICK_UP_ONCE_A_DAY->value, // 每日充值活動返利
            TransactionEnum::INVITE_PLAYER_OFFERS->value, // 邀請玩家活動返利
            TransactionEnum::W_JACKPOT_REWARD->value, // W-Jackpot獎勵
            TransactionEnum::W_JACKPOT_6D_REWARD->value, // W-Jackpot 6D獎勵
            TransactionEnum::VIP_REWARD->value, // VIP獎勵
            TransactionEnum::SLOT_BATTLE->value, // 老虎機爭霸戰
            TransactionEnum::LOSE_REBATE->value, // lose rebate
            TransactionEnum::JACKPOT->value, // jackpot
            TransactionEnum::PROMOTION_WALLET_REWARD->value, // 優惠錢包獎勵
            TransactionEnum::MISSION_REWARD->value, // 任務獎勵
            TransactionEnum::COUPON_CODE->value, // 優惠碼兌換
            TransactionEnum::CHECKIN_REWARD->value, // 打卡獎勵
        ];

        $transactionType = [
            TransactionEnum::SEND_RED_ENVELOPE->value, // 發紅包
            TransactionEnum::RECEIVE_RED_ENVELOPE->value, // 領紅包
            TransactionEnum::RED_ENVELOPE_CANCELLATION->value, // 紅包取消(用戶手動)
            TransactionEnum::RED_ENVELOPE_REFUND->value, // 紅包退還(系統自動)
            TransactionEnum::MEMBER_TRANSFER_OUT->value, // 掃碼轉帳(轉出)
            TransactionEnum::MEMBER_TRANSFER_IN->value, // 掃碼轉帳(轉入)
        ];

        $systemType = [
            TransactionEnum::DEPOSIT->value, // 開分
            // TransactionEnum::WITHDRAWAL->value, // 洗分, 前台洗分的通知在 withdrawal_order_logs
            TransactionEnum::MAKE_UP->value, // 補帳
            TransactionEnum::RECYCLE_LOGIN_ACTIVITY_REWARDS->value, // 回收登入活動獎勵
            TransactionEnum::ADJUSTMENT->value, // 調帳
            TransactionEnum::TO_GAME_WALLET->value, // 主錢包轉入遊戲錢包
            TransactionEnum::FROM_GAME_WALLET->value, // 遊戲錢包轉入主錢包
        ];

        $transactionLogTypes = array_merge($rewardType, $transactionType, $systemType);

        $transactionLogs = TransactionLog::query()
            ->where('member_id', $memberId)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->where(function ($query) use ($transactionLogTypes) {
                $query->whereIn('type', $transactionLogTypes);
                $query->orWhere(function ($query) {
                    $query->where('type', 5); // 後台洗分
                    $query->where('order_id', 0);
                });
            })
            ->where('created_at', '>=', $startTime)
            ->get();

        return $transactionLogs->map(fn ($log) => [
            'data_name' => 'transaction_logs',
            'data_id' => $log->id,
            'tag' => match (true) {
                in_array($log->type, $rewardType) => 'reward',
                in_array($log->type, $transactionType) => 'transaction',
                in_array($log->type, $systemType) => 'system',
                default => '',
            },
            'type' => $log->type,
            'content' => '',
            'content_1' => '',
            'content_2' => '',
            'currency_name' => '',
            'amount' => $log->point,
            'point' => $log->point,
            'status' => 'success',
            'data_type' => 0,
            'time' => $log->created_at->toFrontendTime()->toDateTimeString(),
        ]);
    }

    /**
     * 禮碼紀錄
     */
    public function getRollingChipLogs($startTime, $memberId): Collection
    {
        $rewardType = [
            RollingChipLogTypeEnum::REWARD->value, // 活動獎勵
            RollingChipLogTypeEnum::AGENT_REWARD->value, // 代理獎勵
            RollingChipLogTypeEnum::BET_EVENT_REWARD->value, // 投注活動獎勵
            RollingChipLogTypeEnum::LOGIN_EVENT_REWARD->value, // 登入活動獎勵
            RollingChipLogTypeEnum::RECOVER_EVENT_REWARD->value, // 回收登入活動獎勵
            RollingChipLogTypeEnum::FIRST_DEPOSIT_REWARD->value, // 首充獎勵
            RollingChipLogTypeEnum::REGISTER_REWARD->value, // 註冊蹭送
            RollingChipLogTypeEnum::BIND_MOBILE_PHONE_REWARD->value, // 綁定手機贈送
            RollingChipLogTypeEnum::DEPOSIT_ONCE_A_DAY_REWARD->value, // 每日充獎勵
            RollingChipLogTypeEnum::INVITE_PLAYER_REWARD->value, // 邀請玩家優惠
            RollingChipLogTypeEnum::W_Jackpot->value, // W-Jackpot
            RollingChipLogTypeEnum::W_Jackpot_6D->value, // W-Jackpot 6D
            RollingChipLogTypeEnum::SLOT_MACHINE_COMPETITION->value, // 老虎積爭霸
            RollingChipLogTypeEnum::SLOT_LOSE_REBATE->value, // 輸分回扣
            RollingChipLogTypeEnum::Event_Jackpot->value, // 積寶活動
            RollingChipLogTypeEnum::VIP_REWARD->value, // VIP
            RollingChipLogTypeEnum::DEPOSIT_REWARD->value, // 充值活動獎勵
            RollingChipLogTypeEnum::MISSION_REWARD->value, // 任務獎勵
            RollingChipLogTypeEnum::COUPON_CODE->value, // 優惠碼兌換
        ];

        $systemType = [
            RollingChipLogTypeEnum::AGENT_BACKEND_SEND->value, // 代理後台贈送禮碼
        ];

        $transactionLogTypes = array_merge($rewardType, $systemType);

        $rollingChipLogs = RollingChipLog::query()
            ->where('member_id', $memberId)
            ->where('status', 1000)
            ->whereIn('type', $transactionLogTypes)
            ->where('created_at', '>=', $startTime)
            ->get();

        return $rollingChipLogs->map(fn ($log) => [
            'data_name' => 'rolling_chip_logs',
            'data_id' => $log->id,
            'tag' => match (true) {
                in_array($log->type, $rewardType) => 'reward',
                in_array($log->type, $systemType) => 'system',
                default => '',
            },
            'type' => $log->type,
            'content' => '',
            'content_1' => '',
            'content_2' => '',
            'currency_name' => '',
            'amount' => $log->amount,
            'point' => $log->amount,
            'status' => 'success',
            'data_type' => 1,
            'time' => $log->created_at->toFrontendTime()->toDateTimeString(),
        ]);
    }

    /**
     * 會員綁定紀錄
     */
    public function getMemberBindingLogs($startTime, $memberId): Collection
    {
        $bindingLogs = MemberBindingLog::query()
            ->where('member_id', $memberId)
            ->whereIn('status', [1, 2])
            ->where('created_at', '>=', $startTime)
            ->get();

        return $bindingLogs->map(fn ($log) => [
            'data_name' => 'member_binding_logs',
            'data_id' => $log->id,
            'tag' => 'system',
            'type' => $log->type,
            'content' => $log->value,
            'content_1' => '',
            'content_2' => '',
            'currency_name' => '',
            'amount' => 0,
            'point' => 0,
            'status' => match ($log->status) {
                1 => 'success',
                default => 'fail',
            },
            'data_type' => 2,
            'time' => $log->created_at->toFrontendTime()->toDateTimeString(),
        ]);
    }

    /**
     * 實名驗證審核
     */
    public function getRealnameValidationRequestLogs($startTime, $memberId): Collection
    {
        $realnameValidationRequestPassed = RealnameValidationRequest::where('member_id', $memberId)
            ->where('status', '<>', 0)
            ->where('updated_at', '>=', $startTime)
            ->get();

        return $realnameValidationRequestPassed->map(fn ($log) => [
            'data_name' => 'realname_validation_requests',
            'data_id' => $log->id,
            'tag' => 'system',
            'type' => '',
            'content' => match ($log->status) {
                1 => __('error_message.realname_validation.passed'),
                default => __('error_message.realname_validation.failed'),
            },
            'content_1' => '',
            'content_2' => '',
            'currency_name' => '',
            'amount' => 0,
            'point' => 0,
            'status' => match ($log->status) {
                1 => 'success',
                default => 'fail',
            },
            'data_type' => 2,
            'time' => $log->updated_at->toFrontendTime()->toDateTimeString(),
        ]);
    }
}
