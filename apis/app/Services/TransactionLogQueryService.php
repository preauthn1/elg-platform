<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use Illuminate\Support\Facades\DB;

class TransactionLogQueryService
{
    /**
     * 根據請求的類型返回對應的交易日誌類型陣列。
     *
     * @param  int|string  $type  請求的交易類型。
     * @return array 返回對應的交易類型陣列，若類型未定義則返回預設類型。
     */
    public function getTransactionLogType(int|string $type): array
    {
        $types = [
            // 進分：包含代收、自助上分、代理後台開分、客服後台開分所有充值方式都要記錄在這
            1 => [
                TransactionEnum::ORDER_DEPOSIT->value, // 訂單儲值
                TransactionEnum::DEPOSIT->value, // 開分
            ],
            // 洗分：包含代付、自助洗分、代理後台洗分、客服後台洗分所有洗分方式都要記錄在這
            2 => [
                TransactionEnum::WITHDRAWAL->value, // 洗分
                TransactionEnum::REFUSE_TO_WITHDRAWAL->value, // 拒絕洗分
                TransactionEnum::REFUND_OF_WITHDRAWAL->value, // 退還洗分
            ],
            // 進出遊戲：每次分數轉入轉出遊戲商都要記錄
            3 => [
                TransactionEnum::TO_VENDOR->value, // 平台轉入遊戲商
                TransactionEnum::FROM_VENDOR->value, // 遊戲商轉入平台
            ],
            // 活動獎勵：所有活動發放的獎勵都要記錄
            4 => [
                TransactionEnum::ACTIVITY_AWARD->value,  // 活動獎勵
                TransactionEnum::CHECKIN_REWARD->value,  // 打卡獎勵
                TransactionEnum::LOGIN_EVENT_REWARDS->value,  // 登入活動獎勵
                TransactionEnum::RECYCLE_LOGIN_ACTIVITY_REWARDS->value,  // 回收登入活動獎勵
                TransactionEnum::V_JACKPOT_REWARDS->value,  // v-jackpot
                TransactionEnum::FIRST_DEPOSIT_REWARDS->value,  // 首充活動返利
                TransactionEnum::REGISTRATION_GIFT->value,  // 註冊送
                TransactionEnum::BIND_MOBILE_PHONE_GIFT->value,  // 綁定手機送
                TransactionEnum::DEPOSIT_REWARDS->value,  // 充值活動返利
                TransactionEnum::PICK_UP_ONCE_A_DAY->value,  // 每日充值活動返利
                TransactionEnum::INVITE_PLAYER_OFFERS->value,  // 邀請玩家獎勵活動返利
                TransactionEnum::W_JACKPOT_REWARD->value,  // w-jackpot
                TransactionEnum::W_JACKPOT_6D_REWARD->value,  // W-Jackpot 6d獎勵
                TransactionEnum::VIP_REWARD->value,  // VIP獎勵
                TransactionEnum::CYCLE_DEPOSIT_EVENT_REWARD->value,  // 周期充值活動返利
                TransactionEnum::DAILY_CONSECUTIVE_DEPOSIT_REWARD->value,  // 每日連續儲值活動
                TransactionEnum::WEEKLY_RESCUE_FUND->value,  // 每周救援金
                TransactionEnum::SLOT_BATTLE->value, // 老虎機爭霸戰
                TransactionEnum::LOSE_REBATE->value, // lose rebate
                TransactionEnum::JACKPOT->value, // jackpot
                TransactionEnum::MISSION_REWARD->value,
                TransactionEnum::COUPON_CODE->value,
            ],
            // 全民代理：只顯示全民代理派發紀錄
            5 => [
                TransactionEnum::V_REWARD->value, // 全民代理
            ],
            // 轉帳：所有轉帳紀錄 (原先的紅包功能)
            6 => [
                TransactionEnum::SEND_RED_ENVELOPE->value, // 發紅包
                TransactionEnum::RECEIVE_RED_ENVELOPE->value, // 領紅包
                TransactionEnum::RED_ENVELOPE_CANCELLATION->value, // 紅包取消(用戶手動)
                TransactionEnum::RED_ENVELOPE_REFUND->value, // 紅包退還(系統自動)
            ],
            // 補分：所有補點紀錄
            // type = 14:補帳
            7 => [
                TransactionEnum::MAKE_UP->value, // 補帳
            ],
            // 紅包：所有領紅包紀錄 (新的紅包功能)
            // 功能尚未實作
            8 => [
                TransactionEnum::MEMBER_TRANSFER_OUT->value, // 掃碼轉帳(轉出)
                TransactionEnum::MEMBER_TRANSFER_IN->value, // 掃碼轉帳(轉入)
            ],
            // 顯示全部類型
            'default' => [
                TransactionEnum::TO_VENDOR->value,   // 平台轉入遊戲商
                TransactionEnum::FROM_VENDOR->value,   // 遊戲商轉入平台
                TransactionEnum::ORDER_DEPOSIT->value,   // 訂單儲值
                TransactionEnum::DEPOSIT->value,   // 開分
                TransactionEnum::WITHDRAWAL->value,   // 洗分
                TransactionEnum::SEND_RED_ENVELOPE->value,   // 發紅包
                TransactionEnum::RECEIVE_RED_ENVELOPE->value,   // 領紅包
                TransactionEnum::RED_ENVELOPE_CANCELLATION->value,   // 紅包取消(用戶手動)
                TransactionEnum::RED_ENVELOPE_REFUND->value,   // 紅包退還(系統自動)
                TransactionEnum::REFUSE_TO_WITHDRAWAL->value,  // 拒絕洗分
                TransactionEnum::REFUND_OF_WITHDRAWAL->value,  // 退還洗分
                TransactionEnum::ACTIVITY_AWARD->value,  // 活動獎勵
                TransactionEnum::CHECKIN_REWARD->value,  // 打卡獎勵
                TransactionEnum::MAKE_UP->value,  // 補帳
                TransactionEnum::LOGIN_EVENT_REWARDS->value,  // 登入活動獎勵
                TransactionEnum::RECYCLE_LOGIN_ACTIVITY_REWARDS->value,  // 回收登入活動獎勵
                TransactionEnum::V_REWARD->value,  // 全民代理
                TransactionEnum::V_JACKPOT_REWARDS->value,  // v-jackpot
                TransactionEnum::FIRST_DEPOSIT_REWARDS->value,  // 首充活動返利
                TransactionEnum::REGISTRATION_GIFT->value,  // 註冊送
                TransactionEnum::BIND_MOBILE_PHONE_GIFT->value,  // 綁定手機送
                // TransactionEnum::OTA_DEDUCTION->value,  // 預付網扣款
                // TransactionEnum::OTA_REFUND->value,  // 預付網退款
                TransactionEnum::DEPOSIT_REWARDS->value,  // 充值活動返利
                TransactionEnum::PICK_UP_ONCE_A_DAY->value,  // 每日充值活動返利
                TransactionEnum::INVITE_PLAYER_OFFERS->value,  // 邀請玩家獎勵活動返利
                // TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value,  // 單錢包遊戲投注結算
                // TransactionEnum::SEAMLESS_WALLET_CANCEL->value,  // 單錢包遊戲取消注單補點
                // TransactionEnum::SEAMLESS_WALLET_CHANGE->value,  // 單錢包遊戲結算改單補點
                TransactionEnum::W_JACKPOT_REWARD->value,  // w-jackpot
                TransactionEnum::W_JACKPOT_6D_REWARD->value,  // W-Jackpot 6d獎勵
                TransactionEnum::VIP_REWARD->value,  // VIP獎勵
                // TransactionEnum::ADJUSTMENT->value, // 調帳
                // TransactionEnum::TO_GAME_WALLET->value, // 主錢包轉入遊戲錢包
                // TransactionEnum::FROM_GAME_WALLET->value, // 遊戲錢包轉入主錢包
                TransactionEnum::MEMBER_TRANSFER_OUT->value,  // 掃碼轉帳(轉出)
                TransactionEnum::MEMBER_TRANSFER_IN->value,  // 掃碼轉帳(轉入)
                TransactionEnum::CYCLE_DEPOSIT_EVENT_REWARD->value,  // 周期充值活動返利
                TransactionEnum::DAILY_CONSECUTIVE_DEPOSIT_REWARD->value,  // 每日連續儲值活動
                TransactionEnum::WEEKLY_RESCUE_FUND->value,  // 每周救援金
                TransactionEnum::SLOT_BATTLE->value, // 老虎機爭霸戰
                TransactionEnum::LOSE_REBATE->value, // lose rebate
                TransactionEnum::JACKPOT->value, // jackpot
                // TransactionEnum::PROMOTION_WALLET_REWARD->value, // promotion_wallet
                TransactionEnum::MISSION_REWARD->value,
                TransactionEnum::COUPON_CODE->value,
            ],
        ];

        return $types[$type] ?? $types['default'];
    }

    /**
     * 根據請求的類型返回對應的滾動籌碼日誌類型陣列。
     *
     * @param  int|string  $type  請求的滾動籌碼類型。
     * @return array 返回對應的滾動籌碼類型陣列，若類型未定義則返回預設類型。
     */
    public function getRollingChipLogType(int|string $type): array
    {
        $types = [
            // 禮碼的類別
            4 => [
                1,  // 活動獎勵
                5,  // 代理後台贈送禮碼
                6,  // 投注活動獎勵
                7,  // 登入活動獎勵
                8,  // 回收登入活動獎勵
                9,  // 首充獎勵
                10, // 註冊蹭送
                11, // 綁定手機贈送
                12, // 每日充獎勵
                13, // 邀請玩家優惠
                14, // W-Jackpot
                15, // W-Jackpot 6D
                16, // 老虎機爭霸
                17, // 輸分回扣
                18, // 積寶活動
                19, // VIP
                20, // 充值活動獎勵
                23, // 優惠碼
                25, // 任務獎勵
            ],
            // 禮碼的類別
            5 => [
                4, // 代理獎勵
            ],
            // 禮碼的類別
            'default' => [
                1,  // 活動獎勵
                4,  // 代理獎勵
                5,  // 代理後台贈送禮碼
                6,  // 投注活動獎勵
                7,  // 登入活動獎勵
                8,  // 回收登入活動獎勵
                9,  // 首充獎勵
                10, // 註冊蹭送
                11, // 綁定手機贈送
                12, // 每日充獎勵
                13, // 邀請玩家優惠
                14, // W-Jackpot
                15, // W-Jackpot 6D
                16, // 老虎機爭霸
                17, // 輸分回扣
                18, // 積寶活動
                19, // VIP
                20, // 充值活動獎勵
            ],
        ];

        return $types[$type] ?? $types['default'];
    }

    /**
     * 判斷是否需要顯示滾動籌碼。
     */
    public function shouldShowRollingChip(int|string $type): bool
    {
        return in_array($type, [4, 5, 'default']);
    }

    public function queryTransactionLog($member, $types, $startTime, $endTime)
    {
        $memberId = $member->id;
        $agentGroupId = $member->agent->vendor_customize_group_id;
        // $currencyId = $member->agent->currency_id;

        $vendorQuery = Vendor::query()
            ->select(['id', 'name as vendor_name', 'image as vendor_image']);

        $vendorCustomQuery = VendorCustomizedItem::query()
            ->where('agent_group_id', $agentGroupId)
            ->select(['vendor_id', 'agent_group_id', 'name as vendor_custom_name', 'image as vendor_custom_image'])
            ->groupBy('vendor_id');

        return TransactionLog::query()
            ->leftJoinSub($vendorQuery, 'vendors', function ($join) {
                $join->on('transaction_logs.vendor_id', '=', 'vendors.id');
            })
            ->leftJoinSub($vendorCustomQuery, 'vendor_custom', function ($join) {
                $join->on('transaction_logs.vendor_id', '=', 'vendor_custom.vendor_id');
            })
            ->where('member_id', $memberId)
            ->whereIn('type', $types)
            ->whereIn('status', [
                TransactionStatusEnum::STATUS_SUCCESS->value, // 成功
                TransactionStatusEnum::STATUS_REVIEW->value,  // 審核
                TransactionStatusEnum::STATUS_REJECT->value,  // 拒絕
            ])
            ->whereBetween('created_at', [$startTime, $endTime])
            ->orderByDesc('created_at')
            ->select([
                DB::raw('0 as is_rolling_chip'),
                'created_at',
                'point',
                'tax',
                'vendor_image',
                'vendor_name',
                'vendor_custom_image',
                'vendor_custom_name',
                'type',
            ]);
    }

    public function queryRollingChipLog($member, $types, $startTime, $endTime)
    {
        $memberId = $member->id;
        // $currencyId = $member->agent->currency_id;

        return RollingChipLog::query()
            ->where('member_id', $memberId)
            ->whereIn('type', $types)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->select([
                DB::raw('1 as is_rolling_chip'),
                'created_at',
                'amount AS point',
                DB::raw('0 AS tax'),
                DB::raw('null as vendor_image'),
                DB::raw('null as vendor_name'),
                DB::raw('null as vendor_custom_image'),
                DB::raw('null as vendor_custom_name'),
                'type',
            ]);
    }
}
