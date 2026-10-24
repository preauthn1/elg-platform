<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\MemberMail;

use App\Http\Requests\AbstractRequest;

/**
 * 站內信標記已讀（前台） 工單4152
 */
class ReadRequest extends AbstractRequest
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
