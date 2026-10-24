<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Helpers\Common;
use App\Models\Agent;

/**
 * 活躍玩家門檻：代理個性化設定的「判斷活躍玩家條件」兩鍵（target/tickets#4121 新增）。
 *
 * - 7006 `ACTIVE_MEMBER_DEPOSIT_AMOUNT`：期間內充值達此金額
 * - 7007 `ACTIVE_MEMBER_VALID_BET_AMOUNT`：期間內有效流水達此金額
 *
 * 規則（必須一致，所有用到這兩鍵的地方一律經過本類別，禁止各自重寫比較邏輯）：
 * - 有設定的門檻**全部**達成才算活躍；門檻為 0＝該項不檢查。
 * - 門檻掛在代理的個性化組別（`agents.customized_setting_group_id`）上，取值走
 *   `Agent::getSettingValue()`：組別有設定用組別的值，沒有則用 agent_setting_defines 的預設 0。
 * - 與代理模式（7001）無關，任何模式都套用。
 *
 * 「兩門檻皆 0 時誰算活躍」與「期間內的充值／有效流水取自哪張表」由呼叫端決定，本類別只做門檻比較：
 * - GGR 結算（`GgrSettlement\ConditionEvaluator`）：直屬會員全部參與判斷，皆 0＝全員活躍；
 *   有效流水取 total_bet_records.valid_bet。
 * - SR28 代理營運彙總表（`Report\AgentOperationSummaryService`）：只看期間內有充值或下注的會員；
 *   有效流水取 summary_bet_records，與同一列的「有效下注」同源（target/tickets#4362，使用者 2026-10-07 定案）。
 */
readonly class ActiveMemberCriteria
{
    public function __construct(
        public string $depositMin,
        public string $validBetMin,
    ) {}

    public static function forAgent(Agent $agent): self
    {
        return new self(
            depositMin: Common::numAdd('0', (string) $agent->getSettingValue(AgentCustomizedSettingKeyEnum::ACTIVE_MEMBER_DEPOSIT_AMOUNT), 4),
            validBetMin: Common::numAdd('0', (string) $agent->getSettingValue(AgentCustomizedSettingKeyEnum::ACTIVE_MEMBER_VALID_BET_AMOUNT), 4),
        );
    }

    /**
     * 依個性化組別取門檻（報表一次處理多個代理時用，不必逐一載入 Agent）。
     * 取值與快取同 `Agent::getSettingValue()`，只是不經由實際的代理資料列。
     */
    public static function forSettingGroup(?int $agentGroupId): self
    {
        $agent = new Agent;
        $agent->customized_setting_group_id = $agentGroupId;

        return self::forAgent($agent);
    }

    /** 期間內的充值與有效流水是否達成全部有設定的門檻（傳入值須已正規化成字串） */
    public function passes(string $deposit, string $validBet): bool
    {
        if (Common::numCompare($this->depositMin, '0') > 0 && Common::numCompare($deposit, $this->depositMin) < 0) {
            return false;
        }

        return !(Common::numCompare($this->validBetMin, '0') > 0 && Common::numCompare($validBet, $this->validBetMin) < 0);
    }
}
