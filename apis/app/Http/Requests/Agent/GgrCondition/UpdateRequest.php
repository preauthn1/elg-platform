<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrCondition;

/**
 * 推廣代理 GGR 條件設置 修改 工單4124
 *
 * 規則同 StoreRequest（階梯全量替換），僅多 id；遞增驗證繼承 withValidator。
 */
class UpdateRequest extends StoreRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 條件編號
             *
             * @example 1
             */
            'id' => 'required|integer',
            ...parent::rules(),
        ];
    }
}
