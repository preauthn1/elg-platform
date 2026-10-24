<?php

declare(strict_types=1);

namespace App\Http\Requests\External\MotivationSW;

use App\Http\Requests\AbstractRequest;

class RefundRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'account' => 'required|string|max:36',
            'mtcode' => 'required|string|max:70',
        ];
    }
}
