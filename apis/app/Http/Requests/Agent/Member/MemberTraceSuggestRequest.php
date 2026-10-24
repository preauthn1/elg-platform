<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Member;

use App\Http\Requests\AbstractRequest;

class MemberTraceSuggestRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 帳號關鍵字(前綴比對)
             *
             * @example "a"
             */
            'keyword' => 'required|string|min:1|max:191',
        ];
    }
}
