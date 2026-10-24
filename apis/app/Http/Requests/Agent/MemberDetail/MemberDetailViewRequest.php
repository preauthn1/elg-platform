<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberDetail;

use App\Http\Requests\AbstractRequest;

class MemberDetailViewRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 會員系統編號(與 username 擇一)
             *
             * @example 12345
             */
            'member_id' => 'nullable|required_without:username|integer|min:1',
            /**
             * @var string 會員帳號(與 member_id 擇一；供只有帳號字串的入口，如聊天表頭)
             *
             * @example "frank01"
             */
            'username' => 'nullable|required_without:member_id|string|max:191',
        ];
    }
}
