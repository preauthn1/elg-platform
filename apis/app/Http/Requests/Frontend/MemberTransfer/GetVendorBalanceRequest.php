<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\MemberTransfer;

use App\Http\Requests\AbstractRequest;

class GetVendorBalanceRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'vendor_id' => 'required|int',
        ];
    }
}
