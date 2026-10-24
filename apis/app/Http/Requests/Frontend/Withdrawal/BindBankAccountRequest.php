<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Withdrawal;

use App\Http\Requests\AbstractRequest;

class BindBankAccountRequest extends AbstractRequest
{
    protected function prepareForValidation(): void
    {
        $accounts = $this->input('account');

        if (is_array($accounts)) {
            foreach ($accounts as $index => $account) {
                if (isset($account['account_no']) && is_string($account['account_no'])) {
                    $accounts[$index]['account_no'] = trim($account['account_no']);
                }
            }

            $this->merge(['account' => $accounts]);
        }
    }

    public function rules(): array
    {
        return [
            'account' => 'required|array',
            /** 銀行ID */
            'account.*.bank_id' => 'required|integer',
            /** 真實姓名 */
            'account.*.account_name' => 'nullable|string|max:255',
            /** 銀行帳號 */
            'account.*.account_no' => ['required', 'string', 'max:255', 'not_regex:/\s/'],
        ];
    }
}
