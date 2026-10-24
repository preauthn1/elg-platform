<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\MemberMail;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 站內信（後台） 工單4152
 */
class MemberMailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $languages = $this->whenLoaded('languages', fn () => $this->languages, collect());

        return [
            /** @var int 站內信編號 */
            'id' => (int) $this->id,
            /** @var int 發送類型 1:指定會員 2:會員群組 3:全平臺(組織) 4:代理及子代理 */
            'send_type' => (int) $this->send_type,
            /** @var int 發信者代理編號 */
            'agent_id' => (int) $this->agent_id,
            /** @var int 指定代理編號(send_type=4 時有值) */
            'hierarchy_agent_id' => (int) $this->hierarchy_agent_id,
            /** @var string 指定代理帳號(send_type=4 時有值，供列表顯示發送對象) */
            'hierarchy_agent_username' => (string) ($this->hierarchy_agent_username ?? ''),
            /** @var int 組織編號(send_type=3 時有值) */
            'organization_id' => (int) $this->organization_id,
            /** @var string 組織名稱(send_type=3 時有值，供列表顯示發送對象) */
            'organization_name' => (string) ($this->organization_name ?? ''),
            /**
             * send_type=2 時有值，供編輯回填「當初選了哪些群組」。
             * 收件人已於存檔時展開，此清單僅供顯示。
             *
             * @var array<int> 會員群組編號清單
             */
            'member_group_ids' => $this->member_group_ids ?? [],
            /** @var string 發布時間（前端時區；到點後整封信鎖定不可異動） */
            'publish_time' => Carbon::parse($this->publish_time)->toFrontendTime()->format('Y-m-d H:i:s'),
            /** @var bool 是否已發布（已發布僅能查看） */
            'is_published' => $this->isPublished(),
            /** @var int 狀態 1:啟用 0:停用 */
            'status' => (int) $this->status,
            /** @var string|null 備註（僅後台可見） */
            'memo' => $this->memo,
            /** @var int 應收人數（觸達率分母，存檔時計算） */
            'recipient_count' => (int) $this->recipient_count,
            /** @var int 已讀人數（觸達率分子） */
            'read_count' => (int) ($this->reads_count ?? 0),
            /**
             * 多語系內容，供列表顯示與編輯回填。
             *
             * @var array<array{language_id: int, title: string, content: string}> 多語系內容
             */
            'languages' => $languages->map(fn ($row) => [
                'language_id' => (int) $row->language_id,
                'title' => (string) $row->title,
                'content' => (string) $row->content,
            ])->values()->all(),
            /**
             * send_type=1 時有值，供編輯回填收件會員
             * （下拉為遠端搜尋，不重查就只剩 id）。
             *
             * @var array<array{id: int, username: string}> 收件會員
             */
            'members' => $this->whenLoaded('recipients', fn () => $this->recipients
                ->map(fn ($row) => [
                    'id' => (int) $row->member_id,
                    'username' => (string) ($row->member->username ?? ''),
                ])
                ->values()
                ->all(), []),
            /** @var string 建立日期（前端時區） */
            'created_at' => Carbon::parse($this->created_at)->toFrontendTime()->format('Y-m-d H:i:s'),
            /** @var string 更新日期（前端時區） */
            'updated_at' => Carbon::parse($this->updated_at)->toFrontendTime()->format('Y-m-d H:i:s'),
        ];
    }
}
