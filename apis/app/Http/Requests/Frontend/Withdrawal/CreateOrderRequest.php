<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Withdrawal;

use App\Http\Requests\AbstractRequest;

class CreateOrderRequest extends AbstractRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('account_no'))) {
            $this->merge([
                'account_no' => trim($this->input('account_no')),
            ]);
        }
    }

    public function rules(): array
    {
        // {
        //     "amount": "51",
        //     "account_name": "tim",
        //     "account_no": "000000",
        //     "bank_id": 64,
        //     "bank_name": "(SG) - DBS Bank Limited\t",
        //     "bank_code": "FRSG01"
        // }
        return [
            /** 提款金額 */
            'amount' => 'required|numeric|min:1',
            /** 真實姓名 */
            'account_name' => 'nullable|string|max:255',
            /** 銀行帳號 */
            'account_no' => 'required|string|max:255',
            /** 銀行ID */
            'bank_id' => 'required|integer',
            /** 銀行名稱 */
            'bank_name' => 'required|string|max:255',
            /** 銀行代碼 */
            'bank_code' => 'required|string|max:255',
        ];
    }
}
