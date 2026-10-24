<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2;

use App\Http\Requests\AbstractRequest;

class UpdateWalletBalanceRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 錢包ID
             *
             * @example 1
             */
            'wallet_id' => 'required|integer',
            /**
             * @var float 錢包優改餘額
             *
             * @example 10
             */
            'balance' => 'required|numeric',
        ];
    }
}
