<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Wallet;

use App\Http\Requests\AbstractRequest;

class WalletTransferRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 轉移類型
             *
             * 1=主錢包轉到遊戲錢包
             * 2=遊戲錢包轉到主錢包
             *
             * @example 1
             */
            'type' => 'nullable|integer',
            /**
             * 轉移金額
             *
             * @example 100
             */
            'amount' => 'nullable|decimal:0,4|min:0.0001',
        ];
    }
}
