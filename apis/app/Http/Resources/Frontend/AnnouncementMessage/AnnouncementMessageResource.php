<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\AnnouncementMessage;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int ID */
            'announcement_message_id' => $this->id,
            /** @var string 標題 */
            'title' => $this->title,
            /** @var string 訊息 */
            'message' => $this->message,
            /** @var string 建立時間 */
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
