<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\RefreshRanking;

use App\Http\Requests\AbstractRequest;

class VendorIdRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 遊戲商編號
             *
             * @example 1
             */
            'vendor_id' => 'required|integer|min:1',
        ];
    }
}
