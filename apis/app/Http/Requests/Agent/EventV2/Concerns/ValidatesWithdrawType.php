<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2\Concerns;

use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Models\EventV2;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 活動 V2 領取去向（withdraw_type）的驗證，Create／Update 共用，規則只寫這一份。
 *
 * 必須：
 * - 舊後台（WEB_AgentBackend）永遠不送 withdraw_type，Create 落預設、Update 沿用資料庫現值，
 *   因此「有效去向」一律經 effectiveWithdrawType() 取得，禁止在別處直接讀 input('withdraw_type')。
 * - 積分型與活動錢包無關：獎勵公式／配置、餘額限制、稽核流水六格必須為空；積分數量填提款限制公式（必填），
 *   有效期與上限填提款限制配置 {"expire": 小時, "limit": 上限}。「參加即領獎」強制勾選由 Controller 寫入端處理。
 * - 積分型不開放每日區間儲值／每週累積儲值／每日儲值組合：以 event_v2.handler_class 對
 *   EventV2WithdrawTypeEnum::SCORE_UNSUPPORTED_HANDLERS 判定，禁止寫死 event_v2.id。
 * - 積分數量來源二擇一：提款限制公式，或 withdraw_config.tiers 階梯（[{"condition","score"}]，由上往下第一個成立者）；
 *   兩者都填或都沒填皆擋。
 * - withdraw_config.expire（小時）只驗形狀：正整數或缺省（永久）；limit 若有須為數字；withdraw_config 對各類型皆為物件。
 * - 主錢包型的 withdraw_config 不吃 expire／limit／tiers：帶了就擋，避免運營以為有效。
 * - 領取去向建立後鎖定：Update 送來的值與資料庫現值不同即擋（withdraw_type_locked）；沒送＝沿用，不受影響。
 */
trait ValidatesWithdrawType
{
    private ?EventV2WithdrawTypeEnum $effectiveWithdrawType = null;

    /**
     * 這次請求「沒送 withdraw_type」時該視為哪個去向：Create 是預設主錢包，Update 是資料庫現值。
     */
    abstract protected function fallbackWithdrawType(): EventV2WithdrawTypeEnum;

    /**
     * 已存在活動的領取去向（Update 覆寫回資料庫現值）；Create 回 null 表示沒有鎖。
     */
    protected function lockedWithdrawType(): ?EventV2WithdrawTypeEnum
    {
        return null;
    }

    /**
     * 這次請求的有效領取去向。Controller 寫入與 join_withdraw 強制都用這個，不各自判斷。
     */
    public function effectiveWithdrawType(): EventV2WithdrawTypeEnum
    {
        if ($this->effectiveWithdrawType !== null) {
            return $this->effectiveWithdrawType;
        }

        $raw = $this->input('withdraw_type');
        $type = ($raw === null || $raw === '')
            ? $this->fallbackWithdrawType()
            : (EventV2WithdrawTypeEnum::tryFrom((int) $raw) ?? $this->fallbackWithdrawType());

        return $this->effectiveWithdrawType = $type;
    }

    /**
     * withdraw_type 欄位規則。Create 用 nullable（舊後台不送）、Update 用 sometimes（沒送不動原值）。
     *
     * @return array<int, mixed>
     */
    protected function withdrawTypeRule(string $presence): array
    {
        return [$presence, 'integer', Rule::enum(EventV2WithdrawTypeEnum::class)];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // 領取去向一經建立不可異動（Max 2026-09-23）：已參加者的紀錄有凍結值保護，這裡再擋住活動設定本身
            $locked = $this->lockedWithdrawType();
            $raw = $this->input('withdraw_type');
            if ($locked !== null && $raw !== null && $raw !== '' && (int) $raw !== $locked->value) {
                $validator->errors()->add('withdraw_type', __('event_v2.validation.withdraw_type_locked'));
            }

            // Create／Update 的 event_v2_id 皆 required，沒送或找不到類型交給 Controller 回「活動類型不存在」
            $handlerClass = EventV2::query()->find((int) $this->input('event_v2_id'))?->handler_class;
            if (!$this->effectiveWithdrawType()->supportsHandler($handlerClass)) {
                $validator->errors()->add('withdraw_type', __('event_v2.validation.score_type_not_supported'));
            }

            if ($this->effectiveWithdrawType()->isScore()) {
                $this->validateScoreFields($validator);
            } elseif (is_array($this->input('withdraw_config'))) {
                foreach (['expire', 'limit', 'tiers'] as $key) {
                    if (array_key_exists($key, $this->input('withdraw_config'))) {
                        $validator->errors()->add('withdraw_config.' . $key, __('event_v2.validation.main_wallet_config_keys_not_allowed'));

                        break;
                    }
                }
            }

            $config = $this->input('withdraw_config');
            if (!is_array($config) || !array_key_exists('expire', $config)) {
                return;
            }

            $expire = $config['expire'];
            if ($expire === null || $expire === '') {
                return;
            }

            $isPositiveInt = (is_int($expire) && $expire >= 1)
                || (is_string($expire) && ctype_digit($expire) && (int) $expire >= 1);

            if (!$isPositiveInt) {
                $validator->errors()->add('withdraw_config.expire', __('event_v2.validation.score_expire_invalid'));
            }
        });
    }

    /**
     * 積分型專屬形狀：六格活動錢包欄位必空（沒有活動錢包可管）、提款限制公式＝積分數量必填、
     * 提款限制配置的 limit（積分上限）若有須為數字。
     */
    private function validateScoreFields(Validator $validator): void
    {
        foreach (['reward_formula', 'reward_config', 'balance_formula', 'balance_config', 'flow_formula', 'flow_config'] as $field) {
            if ($this->filled($field)) {
                $validator->errors()->add($field, __('event_v2.validation.score_fields_not_allowed'));

                break;
            }
        }

        $tiers = $this->input('withdraw_config.tiers');
        $hasTiers = $tiers !== null && $tiers !== '' && $tiers !== [];
        if ($hasTiers && $this->filled('withdraw_formula')) {
            $validator->errors()->add('withdraw_formula', __('event_v2.validation.score_amount_source_conflict'));
        } elseif (!$hasTiers && !$this->filled('withdraw_formula')) {
            $validator->errors()->add('withdraw_formula', __('event_v2.validation.score_formula_required'));
        } elseif ($hasTiers && !self::isValidScoreTiers($tiers)) {
            $validator->errors()->add('withdraw_config.tiers', __('event_v2.validation.score_tiers_invalid'));
        }

        $limit = $this->input('withdraw_config.limit');
        if ($limit !== null && $limit !== '' && !is_numeric($limit)) {
            $validator->errors()->add('withdraw_config.limit', __('event_v2.validation.score_limit_invalid'));
        }
    }

    /**
     * tiers 必須是非空 list，每組都有非空字串 condition 與 score（數字或公式字串）。
     */
    private static function isValidScoreTiers(mixed $tiers): bool
    {
        if (!is_array($tiers) || !array_is_list($tiers)) {
            return false;
        }

        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                return false;
            }
            $condition = $tier['condition'] ?? null;
            $score = $tier['score'] ?? null;
            if (!is_string($condition) || trim($condition) === '') {
                return false;
            }
            if (!(is_numeric($score) || (is_string($score) && trim($score) !== ''))) {
                return false;
            }
        }

        return true;
    }
}
