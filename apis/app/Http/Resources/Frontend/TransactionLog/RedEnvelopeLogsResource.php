<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\TransactionLog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RedEnvelopeLogsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'point' => $this->point,
            // UTC +8
            'created_at' => $this->created_at->toFrontendTime()->toDateTimeString(),
            'nickname' => $this->nickname,
        ];
    }
}
