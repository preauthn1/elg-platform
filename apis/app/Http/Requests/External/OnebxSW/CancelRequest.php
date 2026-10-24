<?php

declare(strict_types=1);

namespace App\Http\Requests\External\OnebxSW;

use App\Http\Requests\AbstractRequest;

class CancelRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'item' => 'required|json',

            // 文件（Postman ONE-BX Group Doc V.2）的 cancel body 沒有頂層 timestamp，每筆 item.data 自帶
            'timestamp' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    return is_string($value) && is_numeric($value);
                },
            ],
            'signature' => 'required|string',
        ];
    }
}
