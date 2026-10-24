<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Video;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tiktokUrl = trim($this['tiktok_url'], '/');
        $sessionId = $this['session_id'];

        return [
            'username' => $this['username'],
            'password' => $this['password'],
            'ios_download' => "$tiktokUrl/session/$sessionId",
            'ios_open' => "$tiktokUrl/session/$sessionId",
            'android_download' => "$tiktokUrl/session/$sessionId",
            'android_open' => "$tiktokUrl/session/$sessionId",
            'app_icon' => '',
        ];
    }
}
