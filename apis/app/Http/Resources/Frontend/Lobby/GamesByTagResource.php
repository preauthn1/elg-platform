<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Lobby;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GamesByTagResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor->id,
            'vendor_name' => $this->vendor->vendorCustomizedItems->first()->name ?? $this->vendor->name,
            'type_id' => $this->type_id,
            'rtp' => $this->rtp,
            'name' => $this->gameNames->first()->name ?? $this->name,
            'lottery_pool' => $this->lottery_pool,
            'image' => $this->image ?: $this->vendor->image,
            'is_new' => $this->vendorInfoGames->first()->is_new ?? 0,
            'is_hot' => $this->vendorInfoGames->first()->is_hot ?? 0,
            'disable' => $this->disable,
            // 工單4120：單一遊戲維護。遊戲仍留在列表，由前台依此標記為維護中
            'is_maintenance' => $this->is_maintenance ?? 0,
            // DB 存 UTC，輸出前轉前端時區，與遊戲商維護（VendorListResource）同慣例
            'maintenance_end_time' => $this->when(
                condition: !empty($this->maintenance_end_time),
                value: fn () => \Carbon\Carbon::parse($this->maintenance_end_time)->toFrontendTime()->format('Y-m-d H:i:s'),
                default: null
            ),
        ];
    }
}
