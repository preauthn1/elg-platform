<?php

declare(strict_types=1);

namespace App\Http\Requests\External\LivechatBot;

use App\Enums\LivechatBotTransactionTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InquiryDepositWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]+$/', 'max:50'],
            'transaction_type' => ['required', 'string', Rule::enum(LivechatBotTransactionTypeEnum::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => (string) $this->route('username'),
            'transaction_type' => (string) $this->route('transaction_type'),
        ]);
    }

    public function username(): string
    {
        return (string) $this->input('username');
    }

    public function transactionType(): LivechatBotTransactionTypeEnum
    {
        return LivechatBotTransactionTypeEnum::from((string) $this->input('transaction_type'));
    }
}
