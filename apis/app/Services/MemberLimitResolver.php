<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlowOrderType;
use App\Models\Bank;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\VipContent;

/**
 * 會員群組限額解析。
 *
 * 合併模型：
 *   - 單筆（金額）：銀行(渠道，bank 0 fallback 代理值＝技術能力) ∩ 群組 ∩ VIP 上限 **全取嚴**（下限取大、上限取小；VIP 只有上限、無下限）。「不能超過銀行」自然內含。
 *   - 每日金額（無銀行層）：群組 ∩ VIP **取小**。
 *   - 每日**次數**（無銀行層）：base = 代理(WITHDRAWAL_LIMIT>0) ∩ 群組 **取低**；VIP 次數為「額外加總」（業務語意：VIP 多給幾次）→ **base + VIP**，非取嚴（與金額層不同）。
 *   - 存款：群組 ∩ 渠道（無 VIP）；伺服器端沿用「僅擋群組」（渠道單筆本就無伺服器擋單，顯示已取嚴）。
 *   - 會員轉帳：**僅群組**（無 VIP、無銀行/渠道層），限額綁轉出方；單筆上下限 + 每日金額 + 每日次數。
 *   - 各層該界未設定（-1/null/0-fallback）即不貢獻、由其餘層決定；全未設 = 不限制。
 *
 * 逐層「不限制」慣例不同，接管時逐層保留原慣例：
 *   - 群組 / VIP：-1(任何負值)=不限制，>= 0 為實際限額（migration 明定 -1=不限制）。
 *   - 銀行 / 代理金額：欄位 0 = fallback（bank 0 取代理值），最終值 <= 0 視為不限制。
 *   - 提款次數：代理 WITHDRAWAL_LIMIT > 0 才視為限制（沿用原 checkWithdrawalTimes）。
 *
 * 提款接管：WithdrawalService 三隻限額方法皆採「換值、沿用原本比較/檢查」的最小侵入寫法（body 不重寫，僅前置換值）：
 *   - checkWithdrawalTimes：基礎次數改抓 withdrawCountBaseLimit（代理 ∩ 群組 取低）後**沿用原本檢查與 VIP 額外加總**（群組設 0=禁止直接擋）。
 *   - checkDailyWithdrawalAmountLimit：有群組時上限改抓 withdrawDailyAmountLimit（群組 ∩ VIP 取小），跳過原本「未開 VIP 即 return」
 *     的閘門後併入下方共用的 0=禁止／超過即擋判斷；無群組時走原本純 VIP 判斷。
 *   - checkWithdrawalAmountLimit：有 $member 時上下限直接改用顯示端同款 withdrawFloorAmount／withdrawCeilingAmount
 *     （amount 單位，內部已含「無群組回原值」判斷），沿用下方原本 9997 比較——與顯示端共用同一函式，數字必然一致。
 * 純 VIP／無群組會員一律走原本邏輯，不受 member-group 影響。
 *
 * 金額一律以「點數(point)」比較，只走 Common::num*（scale 4），禁止裸比較 / bc* / DB::raw。
 * 對外錯誤訊息的 :limit 一律換算回 amount 單位（會員輸入單位），非 point。
 */
class MemberLimitResolver
{
    /** @var array<int, MemberGroup|null> 單一 request 內的群組快取 */
    private array $groupCache = [];

    /** @var array<int, VipContent|null> 單一 request 內的 VIP 內容快取（null=未啟用或無 VIP；防顯示迴圈 per-row 重複查 DB） */
    private array $vipCache = [];

    public function __construct(
        private MemberService $memberService,
        private AgentService $agentService,
    ) {}

    /**
     * 取會員所屬群組（member_group_id 且啟用中）；未指派或該群組停用則回 null（不貢獻任何限制）。
     */
    public function resolveGroup(Member $member): ?MemberGroup
    {
        if (array_key_exists($member->id, $this->groupCache)) {
            return $this->groupCache[$member->id];
        }

        $group = null;
        if ($member->member_group_id) {
            $group = MemberGroup::query()
                ->where('id', $member->member_group_id)
                ->where('status', 1)
                ->first();
        }

        return $this->groupCache[$member->id] = $group;
    }

    /**
     * 存款單筆限額（手動存款 / 自助存款共用）。$point 為換算後點數字串。
     * 存款無 VIP、銀行單筆無伺服器擋單，故僅套群組（與渠道的取嚴在顯示端呈現）。
     */
    public function checkDeposit(Member $member, string $point): void
    {
        $group = $this->resolveGroup($member);
        if (!$group) {
            return;
        }

        $this->assertRange($point, $group->deposit_min, $group->deposit_max, 'error_message.2070', 'error_message.2071');
    }

    /**
     * 會員的提款限額是否由「會員群組」接管（僅限有指派群組者）。
     * 純 VIP、未加入群組的會員回 false —— 維持原本 VIP/銀行邏輯，member-group 功能不外溢動到既有 VIP 行為。
     * WithdrawalService 三隻限額方法以此為 early-return guard：true → 交給下方 enforce*（內部套全取嚴合併模型）；false → 原本邏輯。
     */
    public function usesMemberGroupLimit(Member $member): bool
    {
        return $this->resolveGroup($member) !== null;
    }

    /**
     * 提款每日累計金額有效上限（point）：群組 ∩ VIP 取嚴（取小；銀行無每日概念）。回 null = 兩者皆不限。
     * 註：VIP withdrawal_amount_limit 同時作為單筆上限與每日累計上限（沿用既有語意）；未設（-1/null）不貢獻。
     */
    public function withdrawDailyAmountLimit(Member $member): ?string
    {
        $group = $this->resolveGroup($member);
        $dailyLimit = $this->mergeCeiling(null, $group?->withdraw_daily_amount);

        $vip = $this->vipContent($member);
        if ($vip !== null) {
            $dailyLimit = $this->mergeCeiling($dailyLimit, $vip->withdrawal_amount_limit);
        }

        return $dailyLimit;
    }

    /**
     * 存款每日累計「金額」。存款無 VIP 概念，直接套群組。呼叫端僅在已知金額時呼叫（部分渠道如點數卡建單不帶金額，
     * 實際金額由回調決定，無金額時不適用此檢查；次數檢查與金額無關，見 checkDepositDailyCount，應無論有無金額都呼叫）。
     * 累計基準＝已上分 + 今日未完成單（訂單口徑，防「連續建單」繞過；未付的失敗/取消單不佔額度，
     * 詳見 MemberService::getTodayDepositAmount）。
     */
    public function checkDepositDailyAmount(Member $member, string $point): void
    {
        $group = $this->resolveGroup($member);
        if (!$group || !$this->hasLimit($group->deposit_daily_amount)) {
            return;
        }

        $total = Common::numAdd($this->memberService->getTodayDepositAmount($member), $point, 4);
        if (Common::numCompare($total, (string) $group->deposit_daily_amount, 4) > 0) {
            throw new ApiException(__('error_message.2066', ['limit' => $group->deposit_daily_amount]));
        }
    }

    /**
     * 存款每日累計「次數」。與金額無關，呼叫端無論該次建單有無金額都應呼叫（防點數卡等無金額渠道繞過每日次數限制）。
     * 累計基準見 MemberService::getTodayDepositCount（訂單口徑，未付的失敗/取消單不佔額度）。
     */
    public function checkDepositDailyCount(Member $member): void
    {
        $group = $this->resolveGroup($member);
        if (!$group) {
            return;
        }

        if ($this->hasLimit($group->deposit_daily_count)
            && $this->memberService->getTodayDepositCount($member) >= (int) $group->deposit_daily_count) {
            throw new ApiException(__('error_message.2067'));
        }
    }

    /**
     * 會員轉帳單筆限額（群組層）。$point 為轉出方實際扣款的含稅毛額。
     *
     * 沿用舊版 API_Frontend `ThirdPartyController::enforceMemberGroupTransferLimit()`：限額一律綁**轉出方**，
     * 轉帳無 VIP、無銀行/渠道層，故只套群組；欄位 -1（含任何負值）＝不限制，無群組則整體 no-op。
     */
    public function checkMemberTransfer(Member $operator, string $point): void
    {
        $group = $this->resolveGroup($operator);
        if (!$group) {
            return;
        }

        $this->assertRange($point, $group->transfer_min, $group->transfer_max, 'error_message.2074', 'error_message.2075');
    }

    /**
     * 會員轉帳每日累計「金額」（依轉出方彙總，UTC+8 當日）。
     * 累計基準見 {@see MemberService::getTodayMemberTransferOutAmount()}（含稅毛額口徑，與單筆檢查、轉出方扣款一致）。
     */
    public function checkMemberTransferDailyAmount(Member $operator, string $point): void
    {
        $group = $this->resolveGroup($operator);
        if (!$group || !$this->hasLimit($group->transfer_daily_amount)) {
            return;
        }

        $total = Common::numAdd($this->memberService->getTodayMemberTransferOutAmount($operator), $point, 4);
        if (Common::numCompare($total, (string) $group->transfer_daily_amount, 4) > 0) {
            throw new ApiException(__('error_message.2076', ['limit' => $group->transfer_daily_amount]));
        }
    }

    /**
     * 會員轉帳每日累計「次數」（依轉出方彙總，UTC+8 當日）。與金額無關，無論該次金額多少都應呼叫。
     * 群組設 0 即當日不可轉帳（沿用群組層「0 為實際限額、非不限制」的慣例）。
     */
    public function checkMemberTransferDailyCount(Member $operator): void
    {
        $group = $this->resolveGroup($operator);
        if (!$group) {
            return;
        }

        if ($this->hasLimit($group->transfer_daily_count)
            && $this->memberService->getTodayMemberTransferOutCount($operator) >= (int) $group->transfer_daily_count) {
            throw new ApiException(__('error_message.2077'));
        }
    }

    /**
     * 自助存款可用帳號白名單（bank_accounts.id）。
     *
     * @return int[]|null null = 不過濾（無群組 / 開關關閉）；空陣列 = 開關已開但一筆都沒勾, 該群組不可用任何帳號
     */
    public function bankWhitelist(Member $member): ?array
    {
        $group = $this->resolveGroup($member);
        if (!$group || !$group->bank_whitelist_enabled) {
            return null;
        }

        return $group->bankAccountIds();
    }

    /**
     * 顯示/擋單共用：下限取嚴（取較大值）——業務層限額與技術層(銀行/渠道)合成時使用。
     * $existing 的「不限制」以 null 表示（呼叫端須自行把該層的不限制語意，如 AgentCashFlowOrderType 的 0=不限制，轉為 null 再傳入）。
     * $value 沿用各層 -1/null=不限制語意（內部 hasLimit 判斷），未設定即不貢獻。
     */
    public function mergeFloor(?string $existing, mixed $value): ?string
    {
        if (!$this->hasLimit($value)) {
            return $existing;
        }

        if ($existing === null) {
            return (string) $value;
        }

        return Common::numCompare((string) $value, $existing, 4) > 0 ? (string) $value : $existing;
    }

    /**
     * 顯示/擋單共用：上限取嚴（取較小值）。契約同 mergeFloor。
     */
    public function mergeCeiling(?string $existing, mixed $value): ?string
    {
        if (!$this->hasLimit($value)) {
            return $existing;
        }

        if ($existing === null) {
            return (string) $value;
        }

        return Common::numCompare((string) $value, $existing, 4) < 0 ? (string) $value : $existing;
    }

    /**
     * 顯示用：提款單筆顯示下限（amount）——base（銀行/代理） ∩ 群組 取嚴（取大）；VIP 無下限。
     * 群組 point 依匯率換回 amount（無條件進位，偏嚴）；無群組時回傳 $baseAmount 原值。
     */
    public function withdrawFloorAmount(Member $member, ?string $baseAmount, mixed $rate): ?string
    {
        $group = $this->resolveGroup($member);
        if ($group === null) {
            return $baseAmount; // 無群組不接管，維持原本顯示
        }

        return $this->mergeFloor($baseAmount, $this->pointFloorToAmount($group->withdraw_min, $rate));
    }

    /**
     * 顯示用：提款單筆顯示上限（amount）——base（銀行/代理） ∩ 群組 ∩ VIP 全取嚴（取小）。
     * 群組/VIP point 依匯率換回 amount（無條件捨去，偏嚴）；無群組時回傳 $baseAmount 原值（不接管，含原本 VIP 顯示）。
     */
    public function withdrawCeilingAmount(Member $member, ?string $baseAmount, mixed $rate): ?string
    {
        $group = $this->resolveGroup($member);
        if ($group === null) {
            return $baseAmount; // 無群組不接管，維持原本顯示（含原本 VIP 顯示）
        }

        $ceiling = $this->mergeCeiling($baseAmount, $this->pointCeilingToAmount($group->withdraw_max, $rate));
        $vip = $this->vipContent($member);
        if ($vip !== null) {
            $ceiling = $this->mergeCeiling($ceiling, $this->pointCeilingToAmount($vip->withdrawal_amount_limit, $rate));
        }

        return $ceiling;
    }

    /**
     * 提款每日次數「基礎上限」＝代理(WITHDRAWAL_LIMIT, >0 才算) ∩ 群組(withdraw_daily_count) 取低（不限制的層不參與）。
     * **不含 VIP**——VIP 次數為「額外加總」（業務語意：VIP 多給幾次），由呼叫端沿用原本邏輯在此基礎上加。
     * 回 null = 兩層皆不限；回 0 = 群組設 0（禁止）。
     */
    public function withdrawCountBaseLimit(Member $member, array $agentSettings): ?int
    {
        $agentLimit = (int) ($agentSettings[AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT] ?? 0);
        $base = $agentLimit > 0 ? (string) $agentLimit : null;
        $base = $this->mergeCeiling($base, $this->resolveGroup($member)?->withdraw_daily_count);

        return $base !== null ? (int) $base : null;
    }

    /**
     * 顯示用：群組「point 單位」下限依渠道匯率換算成「amount 單位」（enforcement 是 amount×rate 換 point 後比較，
     * 顯示欄位卻是 amount 單位，必須反向換算才能參與合成取嚴）。
     * 無限制（-1/null）或 rate 無效時回 null（不貢獻限制）。
     * 結果無條件進位到整數：顯示下限寧可偏嚴，避免會員照畫面輸入仍被建單擋下。
     */
    public function pointFloorToAmount(mixed $pointLimit, mixed $rate): ?string
    {
        if (!$this->hasLimit($pointLimit)) {
            return null;
        }

        $rate = (string) $rate;
        if ($rate === '' || Common::numCompare($rate, '0', 10) <= 0) {
            return null;
        }

        $amount = Common::numDiv((string) $pointLimit, $rate, 10);
        $result = Common::numFloor($amount, 0);
        if (Common::numCompare($amount, $result, 10) > 0) {
            $result = Common::numAdd($result, '1', 0);
        }

        return $result;
    }

    /**
     * 顯示用：群組「point 單位」上限換算成「amount 單位」。契約同 pointFloorToAmount。
     * 結果無條件捨去到整數：顯示上限寧可偏嚴。
     */
    public function pointCeilingToAmount(mixed $pointLimit, mixed $rate): ?string
    {
        if (!$this->hasLimit($pointLimit)) {
            return null;
        }

        $rate = (string) $rate;
        if ($rate === '' || Common::numCompare($rate, '0', 10) <= 0) {
            return null;
        }

        return Common::numFloor(Common::numDiv((string) $pointLimit, $rate, 10), 0);
    }

    /**
     * 顯示用：回傳一份 clone 的渠道設定（AgentCashFlowOrderType），其 min/max
     * 已與群組存款限額取嚴（渠道＝技術層，群組不得超過；依該渠道匯率換算回 amount 單位）。
     * 傳入的 model 可能來自跨會員共用的快取集合，一律 clone、不改動原件。
     */
    public function cloneChannelWithDepositLimits(AgentCashFlowOrderType $channel, MemberGroup $group): AgentCashFlowOrderType
    {
        $rate = $channel->agentCashFlow->rate ?? 1;

        $cloned = clone $channel;
        // AgentCashFlowOrderType.min/max 慣例 0=不限制，先轉 null 再取嚴
        $cloned->min_amount = (int) ($this->mergeFloor(
            Common::numCompare((string) $channel->min_amount, '0', 4) > 0 ? (string) $channel->min_amount : null,
            $this->pointFloorToAmount($group->deposit_min, $rate),
        ) ?? '0');
        $cloned->max_amount = (int) ($this->mergeCeiling(
            Common::numCompare((string) $channel->max_amount, '0', 4) > 0 ? (string) $channel->max_amount : null,
            $this->pointCeilingToAmount($group->deposit_max, $rate),
        ) ?? '0');

        return $cloned;
    }

    /**
     * 單筆金額區間檢查（群組層，存款用）；-1 / null(不貢獻限制) 先以 hasLimit 濾掉，不進金額比較。
     * 低於下限拋 $minMessageKey、高於上限拋 $maxMessageKey（皆帶入 :limit 限額值）。
     */
    private function assertRange(string $point, mixed $min, mixed $max, string $minMessageKey, string $maxMessageKey): void
    {
        if ($this->hasLimit($min) && Common::numCompare($point, (string) $min, 4) < 0) {
            throw new ApiException(__($minMessageKey, ['limit' => $min]));
        }

        if ($this->hasLimit($max) && Common::numCompare($point, (string) $max, 4) > 0) {
            throw new ApiException(__($maxMessageKey, ['limit' => $max]));
        }
    }

    /**
     * 是否為有效限額：null 與 -1(含任何負值) 視為不限制。
     */
    private function hasLimit(mixed $value): bool
    {
        return $value !== null && Common::numCompare((string) $value, '0', 4) >= 0;
    }

    /**
     * 會員是否適用 VIP（代理開啟 VIP 開關且解析得到 VIP 內容）。
     */
    public function hasVip(Member $member): bool
    {
        return $this->vipContent($member) !== null;
    }

    /**
     * 取會員適用的 VIP 內容（代理未開 VIP 開關或無 VIP 設定回 null）；
     * 以 request 生命週期快取，getVipContent 本身無快取且每次 1-2 條查詢，
     * 顯示迴圈（每張銀行卡一列）與單次建單多維度檢查不得重複查 DB。
     */
    private function vipContent(Member $member): ?VipContent
    {
        if (array_key_exists($member->id, $this->vipCache)) {
            return $this->vipCache[$member->id];
        }

        $vip = null;
        $agentSettings = $this->agentService->getAgentSettings($member->agent);
        if (($agentSettings[AgentCustomizedSettingKeyEnum::VIP_VISIBLE] ?? 0) == 1) {
            $vip = $this->memberService->getVipContent($member);
        }

        return $this->vipCache[$member->id] = $vip;
    }
}
