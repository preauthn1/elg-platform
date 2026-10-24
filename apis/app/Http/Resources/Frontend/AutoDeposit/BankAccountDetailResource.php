<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\AutoDeposit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'order_type_id' => $this->order_type_id,

            /** @var int */
            'bank_id' => $this->bank_id,

            /** @var string */
            'bank_name' => $this->bank_name,

            /** @var string */
            'bank_code' => $this->bank_code,

            /** @var int */
            'currency_id' => $this->currency_id,

            /** @var string */
            'account_name' => $this->account_name,

            /** @var string */
            'account_no' => $this->account_no,

            /** @var string|null */
            'qrcode' => $this->qrcode,

            /** @var string|null */
            'bank_icon' => $this->bank_icon,

            /** @var int */
            'agent_cash_flow_id' => $this->agent_cash_flow_id,

            /** @var string */
            'rate' => $this->rate,

            /** @var int */
            'min_amount' => $this->min_amount,

            /** @var int */
            'max_amount' => $this->max_amount,

        ];
    }
}
