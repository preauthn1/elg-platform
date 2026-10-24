<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Event;

use App\Enums\Agent\EventListSortFieldEnum;
use App\Enums\SortDirectionEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

/**
 * 後台活動管理（V1）列表：後端分頁＋排序（target/tickets#4320）。
 *
 * 對應舊系統 API_Agent 的 `EventController@list`。舊系統的前端一次帶 per_page 1000 全取，再於本地排序；
 * VWorld 啟用中的活動已超過 1000 筆，會被截斷。本接口採後端分頁，排序欄位白名單見 EventListSortFieldEnum。
 */
class ListRequest extends AbstractRequest
{
    /** 每頁筆數上限：擋住「一次全取」的用法 */
    public const MAX_PER_PAGE = 100;

    public function rules(): array
    {
        return [
            /**
             * @var int 活動 ID（精確比對；供「帶入既有活動設定」下拉搜尋使用，target/tickets#2983）
             *
             * @example 123
             */
            'id' => 'nullable|integer',
            /**
             * @var string 代理帳號（精確比對）
             *
             * @example "frank"
             */
            'username' => 'nullable|string',
            /**
             * @var string 活動名稱（模糊比對）
             *
             * @example "首充"
             */
            'title' => 'nullable|string',
            /**
             * @var int 活動狀態 1=啟用 0=停用；不傳=全部
             *
             * @example 1
             */
            'status' => 'nullable|integer|in:0,1',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer|min:1',
            /**
             * @var int 每頁筆數（上限 100）
             *
             * @example 20
             */
            'per_page' => 'required|integer|min:1|max:' . self::MAX_PER_PAGE,
            /**
             * @var string 排序欄位：id / agent_username / title / condition / start_time / end_time / prize_type_id / prize_is_repeatedly / prize_expire_date / status；不傳=建立時間新到舊
             *
             * @example "start_time"
             */
            'sort_field' => ['nullable', Rule::enum(EventListSortFieldEnum::class)],
            /**
             * @var string 排序方向 asc / desc（預設 asc）
             *
             * @example "desc"
             */
            'sort_direction' => ['nullable', Rule::enum(SortDirectionEnum::class)],
        ];
    }
}
