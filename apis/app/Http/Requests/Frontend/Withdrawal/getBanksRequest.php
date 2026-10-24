<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Withdrawal;

use App\Http\Requests\AbstractRequest;

class getBanksRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /** 銀行類別id, 同代付方式id */
            'type' => 'nullable|integer',
        ];
    }
}
