<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\MemberTransfer;

use App\Http\Requests\AbstractRequest;

class TransferBackRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 準備進入的遊戲商ID
             *
             * @example 144
             */
            'vendor_id' => 'nullable|integer',
        ];
    }
}
