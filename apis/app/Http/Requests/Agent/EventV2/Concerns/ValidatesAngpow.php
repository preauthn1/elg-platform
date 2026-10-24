<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2\Concerns;

use App\Enums\EventV2\EventV2PeriodEnum;
use App\Models\EventV2;
use App\Services\EventV2\AngpowService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 活動 V2 紅包（target/tickets#4234）與兩個週期（member_period／total_period）的後台驗證，Create／Update 共用，規則只寫這一份。
 *
 * 必須：
 * - member_period（可重複參加）／total_period（包括期間）只有紅包能設非 0；其他類型的週期由 event_v2.schedule 決定，
 *   送了非 0 擋下（period_not_supported）。
 * - 舊後台永遠不送這兩欄：Create 落 NONE、Update 沿用資料庫現值，一律經 effectivePeriod($field) 取得。
 * - 兩個週期建立後鎖定（比照 withdraw_type）：Update 送來與現值不同即擋（period_locked），實作在 UpdateRequest::rejectChangedPeriods()。
 * - 紅包的條件配置必須有 score（正數；第 3 位小數起由 AngpowService::scoreCost() 無條件捨去，截完為 0 視同沒設）；獎勵公式必填（沒填＝抽 0 元照扣分）；max_amount／member_amount 正數、member_times／interval 正整數、
 *   time_window 兩個 "HH:MM"（後台時區，Controller 寫入前轉 UTC）——有填才驗，沒填＝不限制。
 * - score 只有紅包能設：其他類型帶了擋下（angpow_score_only），避免運營以為有扣積分。
 * - 紅包只發主錢包由 ValidatesWithdrawType 經 SCORE_UNSUPPORTED_HANDLERS 擋，這裡不重複。
 */
trait ValidatesAngpow
{
    /** @var array<string, EventV2PeriodEnum> 依欄位快取（member_period／total_period） */
    private array $effectivePeriods = [];

    /**
     * 這次請求「沒送該週期欄位」時該視為哪個週期：Create 是 NONE，Update 是資料庫現值。
     *
     * @param  string  $field  EventV2PeriodEnum::MEMBER_FIELD／TOTAL_FIELD
     */
    abstract protected function fallbackPeriod(string $field): EventV2PeriodEnum;

    /**
     * 有效週期，兩個欄位共用同一套規則；禁止在別處直接讀 input('member_period')／input('total_period')。
     *
     * @param  string  $field  EventV2PeriodEnum::MEMBER_FIELD／TOTAL_FIELD
     */
    public function effectivePeriod(string $field): EventV2PeriodEnum
    {
        if (isset($this->effectivePeriods[$field])) {
            return $this->effectivePeriods[$field];
        }

        $raw = $this->input($field);
        $period = ($raw === null || $raw === '')
            ? $this->fallbackPeriod($field)
            : (EventV2PeriodEnum::tryFrom((int) $raw) ?? $this->fallbackPeriod($field));

        return $this->effectivePeriods[$field] = $period;
    }

    /**
     * 兩個週期欄位（member_period／total_period）的規則。Create 用 nullable（舊後台不送）、Update 用 sometimes（沒送不動原值）。
     *
     * @return array<string, array<int, mixed>>
     */
    protected function periodRules(string $presence): array
    {
        $rule = [$presence, 'integer', Rule::enum(EventV2PeriodEnum::class)];

        return array_fill_keys(EventV2PeriodEnum::FIELDS, $rule);
    }

    protected function validateAngpow(Validator $validator): void
    {
        // event_v2_id 找不到類型交給 Controller 回「活動類型不存在」
        $handlerClass = EventV2::query()->find((int) $this->input('event_v2_id'))?->handler_class;
        $config = $this->input('condition_config');
        $config = is_array($config) ? $config : [];

        if ($handlerClass !== class_basename(AngpowService::class)) {
            foreach (EventV2PeriodEnum::FIELDS as $field) {
                if (!$this->effectivePeriod($field)->isNone()) {
                    $validator->errors()->add($field, __('event_v2.validation.period_not_supported'));
                }
            }
            if (array_key_exists(AngpowService::KEY_SCORE, $config)) {
                $validator->errors()->add('condition_config.' . AngpowService::KEY_SCORE, __('event_v2.validation.angpow_score_only'));
            }

            return;
        }

        if (AngpowService::scoreCost($config) === null) {
            $validator->errors()->add('condition_config.' . AngpowService::KEY_SCORE, __('event_v2.validation.angpow_score_required'));
        }

        // 沒有獎勵公式＝每包抽到 0 元、積分照扣，玩家白扣分
        if (!$this->filled('reward_formula')) {
            $validator->errors()->add('reward_formula', __('event_v2.validation.angpow_reward_formula_required'));
        }

        $invalid = function (string $key) use ($validator): void {
            $validator->errors()->add('condition_config.' . $key, __('event_v2.validation.angpow_config_invalid', ['key' => $key]));
        };

        foreach ([AngpowService::KEY_MAX_AMOUNT, AngpowService::KEY_MEMBER_AMOUNT] as $key) {
            if (self::isSet($config, $key) && !(is_numeric($config[$key]) && (float) $config[$key] > 0)) {
                $invalid($key);
            }
        }

        foreach ([AngpowService::KEY_MEMBER_TIMES, AngpowService::KEY_INTERVAL] as $key) {
            if (self::isSet($config, $key) && !self::isPositiveInt($config[$key])) {
                $invalid($key);
            }
        }

        if (self::isSet($config, AngpowService::KEY_TIME_WINDOW) && !AngpowService::isTimeWindow($config[AngpowService::KEY_TIME_WINDOW])) {
            $invalid(AngpowService::KEY_TIME_WINDOW);
        }
    }

    /** key 存在且不是 null／空字串才算「有填」 */
    private static function isSet(array $config, string $key): bool
    {
        return array_key_exists($key, $config) && $config[$key] !== null && $config[$key] !== '';
    }

    private static function isPositiveInt(mixed $value): bool
    {
        return (is_int($value) && $value >= 1)
            || (is_string($value) && ctype_digit($value) && (int) $value >= 1);
    }
}
