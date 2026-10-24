<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Report;

use App\Services\Agent\AgentDashboardDetailService;
use Illuminate\Validation\Rule;

/**
 * 代理數據看板明細下鑽（target/tickets#4180 第二階段）。
 *
 * `group` 是下鑽對象：一組指標一張明細，同組的指標格子點下去開同一張表。
 * `retention_day` 只有留存組需要（矩陣的哪一格）。其餘條件與一階完全相同。
 */
class AgentDashboardDetailRequest extends AgentDashboardRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'group' => ['required', Rule::in(AgentDashboardDetailService::groups())],
            'retention_day' => 'required_if:group,retention|integer|in:1,7,30',
            'username' => 'nullable|alpha_dash|between:1,50',
            'page' => 'nullable|integer|min:1',
            // 畫面分頁用；匯出走 detail-export（不分頁、不算總筆數）
            'per_page' => 'nullable|integer|between:1,100',
        ]);
    }
}
