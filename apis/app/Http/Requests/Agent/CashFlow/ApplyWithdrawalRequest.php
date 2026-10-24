<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\CashFlow;

use App\Http\Requests\AbstractRequest;

class ApplyWithdrawalRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'bank_id' => 'required|integer|min:1',
            'real_name' => 'required|string',
            'amount' => 'required|numeric|min:0.01|regex:/^\d+(\.\d{1,2})?$/', // 兩位小數
            'bsb_code' => 'nullable|string', // BSB Code 是澳洲銀行使用的分行識別碼，通常是6位數字
            'account_no' => 'required|string',
        ];
    }
}
