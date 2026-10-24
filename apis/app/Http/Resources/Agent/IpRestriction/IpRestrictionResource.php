<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\IpRestriction;

use App\Enums\IpRestrictionBlockItemEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IpRestrictionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 規則編號 */
            'id' => $this->id,
            /** @var string IP 位址 */
            'ip' => $this->ip,
            /** @var array<int, string> 阻擋項目 register=註冊, login=登入, sms=簡訊驗證 */
            'block_items' => IpRestrictionBlockItemEnum::fromModel($this->resource),
            /** @var int 被擋嘗試累計次數 */
            'repeat_count' => $this->repeat_count,
            /** @var string|null 內部備註 */
            'remark' => $this->remark,
            /** @var int 規則狀態 1:啟用 0:關閉 */
            'status' => $this->status,
            /** @var string|null 更新者帳號（未經人工編輯為 null，前端顯示 -） */
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->username),
            /** @var string|null 更新時間 */
            'updated_at' => $this->updated_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
