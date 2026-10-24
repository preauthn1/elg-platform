<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Deposit;

use App\Http\Requests\AbstractRequest;

class CreateOrderRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'domain' => 'required|string',
            'order_type_id' => 'required|digits_between:0,10',
            'amount' => 'nullable|integer|min:1',
            // 不定義固定的自定義欄位：其他欄位都視為動態的 extra fields，
            // 由前端依 example.invalid 的設定動態提交
        ];
    }

    /**
     * 獲取所有額外欄位（除了基本必填欄位外的所有欄位）
     */
    public function getExtraFields(): array
    {
        $baseFields = ['domain', 'order_type_id', 'amount'];

        return collect($this->all())
            ->except($baseFields)
            ->toArray();
    }
}
