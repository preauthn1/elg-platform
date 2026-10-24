<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Report;

/**
 * 代理明細列表：共通條件＋分頁與逐層展開。
 *
 * `parent_agent_id` 空＝列出查詢對象的直屬下級（第一層）；帶值＝列出該代理的下一層。
 * 樹狀採逐層懶載，一次只回一層。
 */
class AgentDashboardListRequest extends AgentDashboardRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'parent_agent_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1',
            // 上限放到 5000 是給 Excel 匯出一次撈完；畫面分頁仍只用 25 / 10
            'per_page' => 'nullable|integer|between:1,5000',
        ]);
    }
}
