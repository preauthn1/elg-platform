<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Wallet;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type;

        $point = match ($type) {
            TransactionEnum::TO_VENDOR->value,
            TransactionEnum::WITHDRAWAL->value,
            TransactionEnum::RECYCLE_LOGIN_ACTIVITY_REWARDS->value,
            TransactionEnum::TO_GAME_WALLET->value => $this->point * -1,
            TransactionEnum::SEND_RED_ENVELOPE->value => ($this->point + $this->tax) * -1,
            default => ($this->point + $this->tax),
        };

        $typeString = __('param.transaction_type.' . $type);

        $statusString = __('param.transaction_log_status.' . $this->status);
        if ($this->status == TransactionStatusEnum::STATUS_GAMING->value) {
            $statusString = __('param.transaction_log_status.' . TransactionStatusEnum::STATUS_GAMING->value);
        }

        return [
            'id' => $this->id,
            'created_at' => $this->created_at->toFrontendTime()->toDateTimeString(),
            'before_balance' => $this->before_balance,
            'point' => $point,
            'balance' => $this->balance,
            'type' => $typeString,
            'status' => $statusString,
        ];
    }
}
