<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrSettlement;

use App\Http\Requests\AbstractRequest;

/**
 * 推廣代理 GGR 派發紀錄 詳情 工單4125
 */
class ViewRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 紀錄編號
             *
             * @example 1
             */
            'id' => 'required|integer',
        ];
    }
}
