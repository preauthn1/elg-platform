<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2RejectDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int id
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var string 拒絕理由
             *
             * @example "IP已重複"
             **/
            'reject_reason' => $this->validate_code->translate(),
            /**
             * @var string IP
             *
             * @example "0.0.0.0"
             **/
            'ip' => $this->ip,
            /**
             * @var string 拒絕參加時間
             *
             * @example "2025-06-18 17:38:25"
             **/
            'reject_time' => \Carbon\Carbon::parse($this->updated_at)->toBackendTime()->toDateTimeString(),
        ];
    }
}
