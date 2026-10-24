<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberMail;

use App\Http\Requests\AbstractRequest;

/**
 * 站內信詳情 工單4152
 */
class ViewRequest extends AbstractRequest
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
