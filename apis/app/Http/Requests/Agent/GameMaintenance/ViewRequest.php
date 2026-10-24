<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GameMaintenance;

use App\Http\Requests\AbstractRequest;

/**
 * 遊戲維護單筆詳情 工單4120
 */
class ViewRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 維護單編號
             *
             * @example 1
             */
            'id' => 'required|integer',
        ];
    }
}
