<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Event;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 後台活動管理（V1）列表單列。欄位名與舊系統 API_Agent 的接口相同，前端型別 EventManageRow 不必改。
 */
class EventListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 活動 ID
             *
             * @example 1435
             */
            'id' => $this->id,
            /**
             * @var string 活動名稱（依語系）
             *
             * @example "測試 - 登錄送"
             */
            'title' => $this->title,
            /**
             * @var string 活動內容（依語系）
             */
            'content' => $this->content,
            /**
             * @var int 活動類型（EventTypeEnum）
             *
             * @example 1
             */
            'type_id' => $this->type_id,
            /**
             * @var int 排序值
             */
            'sort' => $this->sort,
            /**
             * @var int 狀態 1=啟用 0=停用
             *
             * @example 1
             */
            'status' => $this->status,
            /**
             * @var int 建立活動的代理 ID
             */
            'agent_id' => $this->agent_id,
            /**
             * @var string 建立活動的代理帳號
             *
             * @example "frank"
             */
            'agent_username' => $this->agent_username,
            /**
             * @var int 獎勵 ID
             */
            'prize_id' => $this->prize_id,
            /**
             * @var int 獎勵類型（PrizeTypeEnum）
             *
             * @example 3
             */
            'prize_type_id' => $this->prize_type_id,
            /**
             * @var int 獎勵數量
             */
            'prize_number' => $this->prize_number,
            /**
             * @var int 可否重複領取 1=可 2=限時 其他=不可
             *
             * @example 1
             */
            'prize_is_repeatedly' => $this->prize_is_repeatedly,
            /**
             * @var int 取消提款時是否收回獎勵
             */
            'prize_withdrawal_cancel_reward' => $this->prize_withdrawal_cancel_reward,
            /**
             * @var string 獎勵到期日（後台時區）
             *
             * @example "2026-10-02 23:59:59"
             */
            'prize_expire_date' => $this->backendTime($this->prize_expire_date),
            /**
             * @var string 開獎日（後台時區）
             */
            'prize_draw_date' => $this->backendTime($this->prize_draw_date),
            /**
             * @var array<int, array{action_id:int, point:mixed, start_time:?string, end_time:?string}> 條件明細（見 EventListService::attachDetails）
             */
            'detail' => $this->detail ?? [],
        ];
    }

    private function backendTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toBackendTime()->toDateTimeString();
    }
}
