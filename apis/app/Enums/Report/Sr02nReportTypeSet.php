<?php

declare(strict_types=1);

namespace App\Enums\Report;

use App\Enums\CashFlowEnum;
use App\Enums\Transaction\TransactionEnum as T;
use App\Enums\Transaction\TransactionLogAgentTypeEnum as LA;
use App\Enums\Transaction\WalletTransactionEnum as W;
use App\Enums\Transaction\WalletTransactionLogAgentTypeEnum as WLA;
use App\Enums\WithdrawalOrderStatusEnum;

/**
 * SR02N 各段的型別集合（值域與 API_Agent SR02 一致，target/tickets#4356 D4 已核對 10 支正式分支無分站差異）。
 *
 * 純常數類：集合本身不是列舉值，無法做成 enum case，改以既有 Enum 的 value 組成。
 *
 * @knowledge rules/report-sr02n
 */
final class Sr02nReportTypeSet
{
    /** API_Agent `TransactionEnum::REPORT_EVENT_TYPES`（活動、獎勵類） */
    public const array REPORT_EVENT_TYPES = [
        T::CHECKIN_REWARD->value,
        T::ACTIVITY_AWARD->value,
        T::LOGIN_EVENT_REWARDS->value,
        T::FIRST_DEPOSIT_REWARDS->value,
        T::REGISTRATION_GIFT->value,
        T::BIND_MOBILE_PHONE_GIFT->value,
        T::DEPOSIT_REWARDS->value,
        T::PICK_UP_ONCE_A_DAY->value,
        T::INVITE_PLAYER_OFFERS->value,
        T::W_JACKPOT_REWARD->value,
        T::W_JACKPOT_6D_REWARD->value,
        T::SLOT_BATTLE->value,
        T::LOSE_REBATE->value,
        T::JACKPOT->value,
        T::SHAREHOLDER_REWARD->value,
        T::COUPON_CODE->value,
        T::MISSION_REWARD->value,
    ];

    /** 主錢包「全部」＝活動獎勵類 ∪ 開洗分類（SR02 不列退還洗分 12，issues_1245） */
    public const array MAIN_ALL = [
        ...self::REPORT_EVENT_TYPES,
        T::ORDER_DEPOSIT->value,
        T::DEPOSIT->value,
        T::WITHDRAWAL->value,
        T::REFUSE_TO_WITHDRAWAL->value,
        T::MAKE_UP->value,
        T::VIP_REWARD->value,
        T::DEDUCT->value,
    ];

    /** 主錢包「客服系統」來源（且無 orders／withdrawal_orders） */
    public const array MAIN_CS = [
        T::DEPOSIT->value,
        T::WITHDRAWAL->value,
        T::REFUSE_TO_WITHDRAWAL->value,
        T::MAKE_UP->value,
    ];

    /** 主錢包「存款支付商」來源（且 orders 存在） */
    public const array MAIN_DEPOSIT = [
        T::ORDER_DEPOSIT->value,
        T::FIRST_DEPOSIT_REWARDS->value,
        T::DEPOSIT_REWARDS->value,
        T::PICK_UP_ONCE_A_DAY->value,
    ];

    /** 主錢包合計轉入 */
    public const array MAIN_TOTAL_IN = [
        T::ORDER_DEPOSIT->value,
        T::DEPOSIT->value,
        T::CHECKIN_REWARD->value,
        T::REFUSE_TO_WITHDRAWAL->value,
        T::MAKE_UP->value,
    ];

    /** 主錢包合計轉出 */
    public const array MAIN_TOTAL_OUT = [
        T::WITHDRAWAL->value,
        T::OTA_DEDUCTION->value,
        T::DEDUCT->value,
    ];

    /** 主錢包來源顯示為「系統」的型別 */
    public const array MAIN_SYSTEM_SOURCE = [
        T::FIRST_DEPOSIT_REWARDS->value,
        T::DEPOSIT_REWARDS->value,
    ];

    /** 遊戲錢包「全部」 */
    public const array GAME_ALL = [
        W::GAME_WALLET_ORDER_DEPOSIT->value,
        W::GAME_WALLET_BACKEND_DEPOSIT->value,
        W::GAME_WALLET_BACKEND_MAKE_UP->value,
        W::GAME_WALLET_BACKEND_DEDUCT->value,
    ];

    /** 遊戲錢包「客服系統」來源（且無 orders） */
    public const array GAME_CS = [
        W::GAME_WALLET_BACKEND_DEPOSIT->value,
        W::GAME_WALLET_BACKEND_MAKE_UP->value,
        W::GAME_WALLET_BACKEND_DEDUCT->value,
    ];

    /** 遊戲錢包合計轉入 */
    public const array GAME_TOTAL_IN = [
        W::GAME_WALLET_ORDER_DEPOSIT->value,
        W::GAME_WALLET_BACKEND_DEPOSIT->value,
        W::GAME_WALLET_BACKEND_MAKE_UP->value,
    ];

    /** 遊戲錢包合計轉出 */
    public const array GAME_TOTAL_OUT = [
        W::GAME_WALLET_BACKEND_DEDUCT->value,
    ];

    /**
     * 遊戲錢包型別 → 前端顯示用的主錢包型別（SR02 CASE：6→4 開分、8→14 補帳、9→36 扣分）
     */
    public const array GAME_TYPE_DISPLAY = [
        W::GAME_WALLET_BACKEND_DEPOSIT->value => T::DEPOSIT->value,
        W::GAME_WALLET_BACKEND_MAKE_UP->value => T::MAKE_UP->value,
        W::GAME_WALLET_BACKEND_DEDUCT->value => T::DEDUCT->value,
    ];

    /** 代理額度合計轉入 */
    public const array AGENT_CREDIT_TOTAL_IN = [
        LA::DEPOSIT->value,
        LA::AGENT_WALLET_TO_MAIN_WALLET->value,
    ];

    /** 代理額度合計轉出 */
    public const array AGENT_CREDIT_TOTAL_OUT = [
        LA::WITHDRAWAL->value,
        LA::COMMISSION_REWARD->value,
    ];

    /** 來源＝客服系統時代理額度段排除的型別（issues_1825：派發類不算客服操作） */
    public const array AGENT_CREDIT_CS_EXCLUDE = [
        LA::AGENT_COMMISSION->value,
        LA::SHAREHOLDER_REWARD->value,
        LA::COMMISSION_REWARD->value,
    ];

    /** 代理佔成錢包段列入的型別 */
    public const array AGENT_WALLET_ALL = [
        WLA::TRANSFER_IN->value,
        WLA::TRANSFER_OUT->value,
        WLA::PROFIT_SHARE_SETTLE->value,
    ];

    /**
     * 代理佔成錢包段的 wallet_type_id（SR02 原碼 `whereIn('la.wallet_type_id', [1, 3]) //佔成錢包`）。
     *
     * 代理錢包的 wallet_type_id 語意與會員 WalletEnum 不同，不可借用 WalletEnum。
     */
    public const array AGENT_WALLET_WALLET_TYPES = [1, 3];

    /**
     * 代理佔成錢包型別 → 前端顯示用的主錢包型別（SR02 CASE：1→4 開分、2→36 扣分）
     */
    public const array AGENT_WALLET_TYPE_DISPLAY = [
        WLA::TRANSFER_IN->value => T::DEPOSIT->value,
        WLA::TRANSFER_OUT->value => T::DEDUCT->value,
    ];

    /**
     * 取款訂單最終被拒絕的狀態：洗分列對應的訂單落在這些狀態時，手續費／實際金額不計（target/tickets#4493）。
     *
     * 只收最終狀態 2 拒絕、9 退款——會員或代理確定拿不到錢；審核中、出款中等非最終狀態（1／3／21／22／29）
     * 結果未定，照常計入。
     */
    public const array REJECTED_WITHDRAWAL_ORDER_STATUSES = [
        WithdrawalOrderStatusEnum::FAILED->value,
        WithdrawalOrderStatusEnum::REFUND->value,
    ];

    /** 金額精度：手續費換算後截到 4 位（列表 Common::numMul(..., 4) 與合計 SQL TRUNCATE(..., 4) 同為捨去） */
    public const int MONEY_SCALE = 4;

    /** 取款支付商中「預付網（OTA，代繳水電費）」的 cash_flow_id：走 ota_orders、型別 22 */
    public const int OTA_CASH_FLOW_ID = CashFlowEnum::WITHDRAW_BAYAR_NOW;

    /** 取款支付商中「自助洗分」的 cash_flow_id：不比對 withdrawal_payout_records.cash_flow_id */
    public const int SELF_WITHDRAWAL_CASH_FLOW_ID = CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL;
}
