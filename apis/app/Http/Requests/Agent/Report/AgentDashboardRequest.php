<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Report;

use App\Http\Requests\AbstractRequest;

/**
 * 代理數據看板共通查詢條件（target/tickets#4180）。
 *
 * `start_time` / `end_time` 是**使用者所選時區的牆鐘**，時區由 `tz_offset` 指定（GMT−12〜GMT+12
 * 整數時區，即新後台的時區選項）。後端以這一個偏移做兩件事：把區間轉成 UTC 查詢、決定「換日」
 * 的日界（留存的註冊批次與 D 日）。整支端點只有一套時區規則，前端不需要自行平移時間。
 *
 * 只收 datetime 不收純日期：純日期無法表達時區，且前端既有的平移工具對長度 < 16 的字串不處理，
 * 只收日期會讓非 +8 時區的使用者整頁日界錯 8 小時。
 *
 * @see docs/代理數據看板/api-plan.md §2
 */
class AgentDashboardRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'start_time' => 'required|date_format:Y-m-d H:i:s',
            'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            'tz_offset' => 'required|integer|between:-12,12',
            // 代理帳號可含 - 與 _（如 T-POWER），與明細頁的 username 規則一致
            'agent_username' => 'nullable|alpha_dash|between:1,30',
            'scope' => 'nullable|in:line,self',
        ];
    }
}
