<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Information;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InformationListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 資訊ID */
            'id' => $this->id,
            /** @var int 圖片連結類型, 0:無連結, 1:連結至遊戲類型, 2:連結至遊戲, 3.連結至遊戲商, 4:外部連結, 5:內部連結 */
            'type' => $this->type,
            /** @var int|null 遊戲類型ID */
            'game_type_id' => $this->game_type_id ? (int) $this->game_type_id : null,
            /** @var int|null 遊戲ID */
            'game_id' => $this->game_id ? (int) $this->game_id : null,
            /** @var int|null 廠商ID */
            'vendor_id' => $this->vendor_id ? (int) $this->vendor_id : null,
            /** @var string|null 連結 */
            'url' => $this->url,
            /** @var string 圖片 */
            'image' => $this->image,
            /** @var string 圖片PC */
            'image_pc' => $this->image_pc,
            /** @var string 描述 */
            'description' => $this->description,
            /** @var int 是否開新視窗 */
            'open_window' => $this->open_window,
        ];
    }
}
