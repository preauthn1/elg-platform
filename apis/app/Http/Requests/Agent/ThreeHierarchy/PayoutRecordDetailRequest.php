<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\ThreeHierarchy;

use App\Http\Requests\AbstractRequest;

/**
 * 三層代派發紀錄——下鑽明細 工單4199
 *
 * 主表一列（受益人 × 結算日）展開為源頭粒度：一列＝ three_hierarchy_bonus_report 的一列
 * （來源會員 × 遊戲商 × 遊戲類別 × 反水類別），與前台會員版 achievements() 同粒度。
 */
class PayoutRecordDetailRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 主表列身分：member=會員、agent=代理
             *
             * @example "member"
             */
            'identity' => 'required|string|in:member,agent',
            /**
             * @var int 受益人 id（member_id 或 agent_id，依 identity）
             *
             * @example 661824
             */
            'id' => 'required|integer|min:1',
            /**
             * @var string 結算期間（主表列的 summary_time，顯示到日）
             *
             * @example "2026-08-30"
             */
            'summary_time' => 'required|date_format:Y-m-d',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'nullable|integer|min:1',
            /**
             * @var int 每頁筆數（比照業績明細）
             *
             * @example 10
             */
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
