<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\AutoDeposit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 1: 可用銀行列表, 2: 用戶轉帳截圖尚未上傳 */
            'type' => $this->type,
            /** @var BankDetailResource[] */
            'bank' => BankAccountDetailResource::collection($this->bank),
        ];
    }
}
