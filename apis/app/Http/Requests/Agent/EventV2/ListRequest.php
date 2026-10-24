<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2;

use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

class ListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 代理帳號
             *
             * @example "frank"
             */
            'agent_username' => 'nullable|string',
            /**
             * @var string 活動名稱
             *
             * @example "Promo"
             */
            'name' => 'nullable|string',
            /**
             * @var int 活動狀態
             *
             * @example 1
             */
            'status' => 'nullable|integer',
            /**
             * @var int|null 領取去向(1:主錢包,2:積分)，不帶＝全部；見 EventV2WithdrawTypeEnum
             *
             * @example 2
             */
            'withdraw_type' => ['nullable', 'integer', Rule::enum(EventV2WithdrawTypeEnum::class)],
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer',
            /**
             * @var int 每頁筆數
             *
             * @example 10
             */
            'per_page' => 'required|integer',
        ];
    }
}
