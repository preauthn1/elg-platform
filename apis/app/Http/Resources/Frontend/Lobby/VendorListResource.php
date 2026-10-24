<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Lobby;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'item_type' => $this->item_type,
            'id' => $this->id,
            'game_id' => $this->game_id,
            'type_id' => $this->type_id,
            'path' => $this->path,
            'name' => $this->name,
            'image' => $this->image,
            'front_page_image' => $this->front_page_image,
            'app_icon' => $this->app_icon,
            'is_new' => $this->is_new,
            'is_hot' => $this->is_hot,
            'is_event' => $this->is_event,
            'only_one_game' => $this->only_one_game,
            'need_login' => $this->need_login,
            'giftcode' => $this->giftcode,
            'status' => $this->status,
            'currency_name' => $this->currency_name,
            'rate' => $this->rate,

            'maintenance_end_time' => $this->when(
                condition: !empty($this->maintenance_end_time),
                value: fn () => \Carbon\Carbon::parse($this->maintenance_end_time)->toFrontendTime()->format('Y-m-d H:i:s'),
                default: null
            ),
        ];
    }
}
