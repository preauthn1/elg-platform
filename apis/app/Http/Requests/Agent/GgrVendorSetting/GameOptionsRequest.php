<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrVendorSetting;

use App\Http\Requests\AbstractRequest;

/**
 * 排除遊戲下拉資料源 工單4123
 *
 * 彈窗明細列展開時逐列 lazy load（最大組合上千款遊戲，不隨 vendor-options 全量回傳）。
 */
class GameOptionsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 遊戲商編號 vendors.id
             *
             * @example 61
             */
            'vendor_id' => 'required|integer',
            /**
             * @var int 遊戲類型編號 game_types.id
             *
             * @example 1
             */
            'game_type_id' => 'required|integer',
        ];
    }
}
