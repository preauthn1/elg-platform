<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\AutoDeposit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_id' => $this['order_id'],
            'order_no' => $this['order_no'],
            'bank_name' => $this['bank_name'],
            'bank_code' => $this['bank_code'],
            'account_name' => $this['account_name'],
            'account_no' => $this['account_no'],
            'amount' => $this['amount'],
            'status' => $this['status'],
        ] + parent::toArray($request);
    }
}
