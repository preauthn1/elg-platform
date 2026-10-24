<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 錢包類型
 *
 * 08-18 會議決議⑬（08-19 定案）：未來僅保留三種——一般（1）、活動（3）、
 * 禮碼（1002），全部拆分幣別；**type id 不重編、沿用現值**（id 是跨系統契約：
 * 舊系統直寫、stored procedure 硬編、存量列約 70 萬，重編只換來編號連續、
 * 風險是漏改），重新規劃落在本 Enum 的語意層。
 *
 * CODE_GAME(2) 與 CODE_SHOP(1004) 已廢除——case 保留僅為讀取存量資料時
 * cast 不失敗（清除存量屬另案，Gate C 已裁決）；CODE_EVENT_RANKING(1003)
 * 屬非貨幣錢包，2026-09-03 會議已裁決廢除，但**尚未**列入 deprecatedTypes()——
 * 前置未完成（TPower 每日重置排程、EventPpswController 五處改走活動錢包、
 * Me77 兩筆餘額），詳見 multi-currency-wallet-decisions.md 決議⑮。
 *
 * CODE_SCORE(4) 積分（target/tickets#4233，2026-09-16 與 RB 對齊後新增）：非貨幣錢包，
 * 不在 currencyAwareTypes() 內、currency_id 固定 0。這一列只是 member_score_batches
 * 的總額投影——批次表才是真相，對不上以批次表為準重算。積分不可提現、不抵注，
 * **不參與** getMemberWallets()／getSingleWalletBalanceByMember() 的主錢包解析，
 * 加減一律經 MemberScoreService，禁止直接改這一列的 balance。
 *
 * @knowledge rules/currency-and-wallet-currency
 */
enum WalletEnum: int
{
    case CODE_PC = 1; // 一般錢包（平台幣）

    case CODE_GAME = 2; // 遊戲錢包（已廢除，存量待清）

    case CODE_EVENT_WALLET = 3; // 活動錢包

    case CODE_SCORE = 4; // 積分（非貨幣；批次真相在 member_score_batches，經 MemberScoreService 加減）

    case CODE_ROLLING_CHIP = 1002; // 禮碼（泥碼）——公司內部同義詞，非命名 drift，勿「統一」成其中一個

    case CODE_EVENT_RANKING = 1003; // 刷新排行榜（非貨幣，2026-09-03 已裁決廢除、前置未完成）

    case CODE_SHOP = 1004; // 商城幣（已廢除，存量待清）

    /**
     * 需要拆分幣別的錢包類型（決議⑬三分法：保留的三種全部多幣別）
     *
     * @return list<self>
     */
    public static function currencyAwareTypes(): array
    {
        return [
            self::CODE_PC,
            self::CODE_EVENT_WALLET,
            self::CODE_ROLLING_CHIP,
        ];
    }

    /**
     * 該類型是否需要拆分幣別
     *
     * 回 false 者為非貨幣錢包或已廢除類型，member_wallets.currency_id 固定填 0
     * （設計決策 17：sentinel 用 0 不用 NULL——NULL 進不了唯一鍵）。
     */
    public function isCurrencyAware(): bool
    {
        return in_array($this, self::currencyAwareTypes(), true);
    }

    /**
     * 已廢除的錢包類型（決議⑬）
     *
     * case 保留僅為讀取存量資料時不 cast 失敗；一律不再新建
     * （WalletService 對這些類型只查不建）。1003 已於 2026-09-03 裁決廢除但**尚未**列入：
     * 列入後 EventPpswController 五處會撞 getMemberEventWallet() 的守衛、
     * RefreshRankingService 直呼 getOrCreateWallet() 會拿到 null 而致命，且 TPower
     * 每日重置排程仍在寫——三項前置完成後再加（decisions.md 決議⑮）。
     *
     * @return list<self>
     */
    public static function deprecatedTypes(): array
    {
        return [
            self::CODE_GAME,
            self::CODE_SHOP,
        ];
    }

    /**
     * 本類型是否已廢除（不得新建）
     */
    public function isDeprecated(): bool
    {
        return in_array($this, self::deprecatedTypes(), true);
    }
}
