<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\GgrVendorSetting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GgrVendorSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 設置編號 */
            'id' => $this->id,
            /** @var string 設置名稱 */
            'name' => $this->name,
            /** @var int 狀態 1:啟用 0:停用 */
            'status' => $this->status,
            /** @var string|null 建立人員帳號 */
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->username),
            /** @var string|null 更新者帳號 */
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->username),
            /** @var string|null 建立時間 */
            'created_at' => $this->created_at?->toBackendTime()->toDateTimeString(),
            /** @var string|null 更新時間 */
            'updated_at' => $this->updated_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
