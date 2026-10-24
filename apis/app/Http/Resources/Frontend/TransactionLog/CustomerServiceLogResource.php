<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\TransactionLog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerServiceLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_no' => $this->transaction_no,
            'point' => $this->point,
            'type' => $this->type == 5 ? 2 : 1,
            // UTC +8
            'created_at' => $this->created_at->toFrontendTime()->toDateTimeString(),
        ];
    }
}
