<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Withdrawal;

use App\Http\Requests\AbstractRequest;

class CheckBankAccountRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /** 銀行類別, 提款類別 */
            'type' => 'nullable|integer',
        ];
    }
}
