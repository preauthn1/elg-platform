<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\CashFlow;

use App\Http\Requests\AbstractRequest;

/**
 * @knowledge rules/agent-self-deposit
 */
class DepositRequest extends AbstractRequest
{
    /**
     * 代理後台送單時一定會帶、但不屬於三方欄位的鍵：
     * return_url／order_type_id／amount 是建單本身用的，
     * action 與 cash_flow_id 是新代理後台決定打哪支 API 用的分流參數。
     */
    private const BASE_FIELDS = ['return_url', 'order_type_id', 'amount', 'action', 'cash_flow_id'];

    public function rules(): array
    {
        return [
            'return_url' => 'required|string',
            'order_type_id' => 'required|digits_between:0,10',
            'amount' => 'nullable|numeric|min:0.1',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'bank_number' => 'nullable|string',
            // 其他欄位視為動態的 extra fields，由前端依 example.invalid 的設定提交（例如 TngPin 的 pin）
        ];
    }

    /**
     * 取得要寫進 orders.extra_content 的動態欄位（基本欄位以外的全部），
     * 與會員端 Frontend\Deposit\CreateOrderRequest::getExtraFields() 同一套規則；
     * TngPin 等金流商的代收類別從 extra_content 取三方需要的值。
     */
    public function getExtraFields(): array
    {
        return collect($this->all())
            ->except(self::BASE_FIELDS)
            ->toArray();
    }
}
