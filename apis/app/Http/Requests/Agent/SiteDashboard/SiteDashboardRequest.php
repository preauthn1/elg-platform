<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\SiteDashboard;

use App\Http\Requests\AbstractRequest;

/**
 * 站點儀表板查詢 工單4155
 *
 * `start_time` / `end_time` 是使用者所選時區的牆鐘，時區由 `tz_offset` 指定
 * （沿 4180 代理數據看板的參數慣例）。迄時由 Service 以 D-1 封頂，
 * 區間上限 32 天（快捷鈕最大為「上個月」整月；上限同時擋任意長區間掃表，
 * 實測 38M 列的 summary_bet_records 在 31 天內走索引、超過會退化全表掃）。
 */
class SiteDashboardRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 站點（總代 id；admin 傳 0 表示 ALL 全站）
             *
             * @example 4249
             */
            'site' => 'required|integer|min:0',
            /**
             * @var string 起始（前端時區牆鐘）
             *
             * @example "2026-08-17 00:00:00"
             */
            'start_time' => 'required|date_format:Y-m-d H:i:s',
            /**
             * @var string 截止（前端時區牆鐘；超過昨日由後端封頂）
             *
             * @example "2026-08-23 23:59:59"
             */
            'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time|before:start_time +32 days',
            /**
             * @var int 時區偏移小時
             *
             * @example 8
             */
            'tz_offset' => 'required|integer|between:-12,12',
        ];
    }
}
