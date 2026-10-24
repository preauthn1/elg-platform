<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Report;

use App\Http\Requests\AbstractRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * SR28 代理營運彙總表查詢條件（target/tickets#4362）。
 *
 * `start_time`／`end_time` 是使用者所選時區的牆鐘，時區由 `tz_offset` 指定，與代理數據看板、SR29 同一套規則。
 *
 * 🔴 期間一律對齊整點（使用者 2026-10-07 定案）：SR28 定位為非即時報表，主要資料源是整點彙整表
 * （member_hourly_statistics、summary_bet_records），起訖落在小時中間會跟彙整桶對不齊。
 * 驗證前把起始校正為 `HH:00:00`、結束校正為 `HH:59:59`；前端日期元件（`hour-only`）已限制只能選整點，
 * 這裡是給直接打 API 的保險，不擋請求、只對齊。
 */
class AgentOperationSummaryRequest extends AbstractRequest
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'start_time' => $this->alignToHour($this->input('start_time'), atEnd: false),
            'end_time' => $this->alignToHour($this->input('end_time'), atEnd: true),
        ], fn ($value) => $value !== null));
    }

    /** 格式不符的原值不動（回 null 不覆寫），交給 rules 的 date_format 擋 */
    private function alignToHour(mixed $value, bool $atEnd): ?string
    {
        if (!is_string($value) || !Carbon::canBeCreatedFromFormat($value, self::TIME_FORMAT)) {
            return null;
        }

        $time = Carbon::createFromFormat(self::TIME_FORMAT, $value);
        // 溢位值（如 25:00:00）會被 Carbon 進位成隔天，原值不動交給 date_format 擋
        if ($time === null || $time->format(self::TIME_FORMAT) !== $value) {
            return null;
        }

        return ($atEnd ? $time->endOfHour() : $time->startOfHour())->format(self::TIME_FORMAT);
    }

    public function rules(): array
    {
        return [
            'start_time' => 'required|date_format:Y-m-d H:i:s',
            'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            'tz_offset' => 'required|integer|between:-12,12',
            // 代理帳號可含 - 與 _（如 T-POWER）
            'agent_username' => 'nullable|alpha_dash|between:1,30',
            'page' => 'nullable|integer|min:1',
            // 畫面給 20/50/100；上限放寬到 100000 是給匯出一次取回全部
            'per_page' => 'nullable|integer|between:1,100000',
        ];
    }

    /**
     * 期間上限 3 個月，與 SR27／SR29 同一套規則：用 `addMonthsNoOverflow()`（避免 1/31 加三個月溢位到 5/1），
     * 比對取當日零時（前端迄日固定補 23:59，直接比時刻會擋掉剛好三個月的區間）。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $start = $this->input('start_time');
            $end = $this->input('end_time');
            if (!is_string($start) || !is_string($end)) {
                return;
            }

            $limit = Carbon::parse($start)->startOfDay()->addMonthsNoOverflow(3);
            if (Carbon::parse($end)->startOfDay()->greaterThan($limit)) {
                $validator->errors()->add('end_time', 'period must not exceed 3 months');
            }
        });
    }
}
