<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrVendorSetting;

/**
 * 推廣代理 GGR 遊戲商設置 修改 工單4123
 *
 * 規則同 StoreRequest（明細全量替換），僅多 id；階梯遞增等結構驗證繼承 withValidator。
 */
class UpdateRequest extends StoreRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 設置編號
             *
             * @example 1
             */
            'id' => 'required|integer',
            ...parent::rules(),
        ];
    }
}
