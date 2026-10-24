<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->vendor_image_name ?? $this->vendor_name,
            'type' => ($this->type > 2) ? ($this->type - 2) : $this->type,
            'point' => $this->point,
            'vendor_point' => $this->vendor_point,
            'balance' => $this->balance,
            'vendor_balance' => $this->vendor_balance,
            'rate' => $this->rate,
            'status' => $this->status,
            'result' => $this->result,
            'created_at' => $this->created_at->toFrontendTime()->toDateTimeString(),
            'updated_at' => $this->updated_at->toFrontendTime()->toDateTimeString(),
        ];
    }
}
