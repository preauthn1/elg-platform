<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\TransactionLog;

use App\Enums\Transaction\TransactionEnum;
use App\Models\WalletTransactionLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 轉換 TransactionLog
 * 若代理是否多錢包, 則轉換 WalletTransactionLog
 */
class GameTransferLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type;
        if ($this->resource instanceof WalletTransactionLog) {
            $type = ($this->type === TransactionEnum::ORDER_DEPOSIT->value) ? 1 : 2;
        }

        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->vendor->vendorImage->name ?? $this->vendor->name ?? '',
            'type' => $type, // 前端1:轉入遊戲商 2:轉出遊戲商
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
