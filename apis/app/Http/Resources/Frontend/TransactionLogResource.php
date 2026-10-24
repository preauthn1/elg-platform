<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'is_rolling_chip' => $this->is_rolling_chip, // 是否為禮碼, 1:禮碼, 0:現金
            'created_at' => $this->created_at,
            'point' => $this->point,
            'type' => $this->type,
            'currency' => $this->currency,
        ];
    }
}
