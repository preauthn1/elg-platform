<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberMail;

use App\Http\Requests\AbstractRequest;

/**
 * 刪除站內信 工單4152
 *
 * 僅發布前可刪；已到發布時間由 Service 擋下。
 */
class DeleteRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 站內信編號
             *
             * @example 1
             */
            'id' => 'required|integer|min:1',
        ];
    }
}
