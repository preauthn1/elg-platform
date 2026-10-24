<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Vendor;

use App\Http\Requests\AbstractRequest;

class RecentlyVendorIdsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'member_id' => 'required|integer',
        ];
    }
}
