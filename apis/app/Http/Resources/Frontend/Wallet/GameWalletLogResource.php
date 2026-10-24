<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Wallet;

use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 轉換 TransactionLog
 * 若代理是否多錢包, 則轉換 WalletTransactionLog
 */
class GameWalletLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type;

        $point = match ($type) {
            WalletTransactionEnum::TO_MAIN_WALLET->value,
            WalletTransactionEnum::GAME_WALLET_TO_VENDOR->value,
            WalletTransactionEnum::GAME_WALLET_BACKEND_DEDUCT->value,
            WalletTransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value => $this->point * -1,
            default => ($this->point + $this->tax),
        };

        $typeString = __('param.wallet_transaction_type.' . $type);
        if (empty($typeString)) {
            $typeString = __('param.transaction_type.' . $type);
        }

        $statusString = __('param.transaction_log_status.' . TransactionStatusEnum::STATUS_SUCCESS->value);
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
