<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\ThreeHierarchy;

use App\Http\Requests\AbstractRequest;

/**
 * 三層代用戶查詢（target/tickets#4190）
 *
 * 對應舊系統 API_Agent 的 `api/three_hierarchy/user_search`，比舊系統多了：
 * identity 身分篩選、sort/direction 遠端排序與統計卡（summary）。
 */
class UserSearchRequest extends AbstractRequest
{
    /** 可排序欄位白名單（union 子查詢的輸出欄位名） */
    public const SORTABLE = [
        'rebate_amount',
        'down_rebate_amount',
        'down2_rebate_amount',
        'bonus_amount',
        'withdraw_able',
        'withdraw_amount',
        'created_at',
    ];

    public function rules(): array
    {
        return [
            /**
             * @var string 代理帳號（精確比對）
             *
             * @example "vwtest"
             */
            'agent' => 'nullable|string|max:64',
            /**
             * @var string 會員帳號（精確比對；指定時僅回會員列）
             *
             * @example "vwuser001"
             */
            'member' => 'nullable|string|max:64',
            /**
             * @var string 身分篩選：member=會員、agent=代理、空=全部
             *
             * @example "member"
             */
            'identity' => 'nullable|string|in:member,agent',
            /**
             * @var string 排序欄位（白名單外拒絕；可提現/已提現排序時自動限會員身分）
             *
             * @example "bonus_amount"
             */
            'sort' => 'nullable|string|in:' . implode(',', self::SORTABLE),
            /**
             * @var string 排序方向
             *
             * @example "desc"
             */
            'direction' => 'nullable|string|in:asc,desc',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'nullable|integer|min:1',
            /**
             * @var int 每頁筆數（匯出時前端會帶大值，設天花板防護）
             *
             * @example 20
             */
            'per_page' => 'nullable|integer|min:1|max:100000',
        ];
    }
}
