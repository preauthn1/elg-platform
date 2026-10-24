<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\IpRestriction;

use App\Enums\IpRestrictionBlockItemEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rules\Enum;

class StoreRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string IP 位址（IPv4/IPv6）
             *
             * @example "0.0.0.0"
             */
            'ip' => 'required|string|ip|max:45',
            /**
             * @var array 阻擋項目（多選，至少一項）：register=註冊, login=登入, sms=簡訊驗證
             *
             * @example ["register", "login"]
             */
            'block_items' => 'required|array|min:1',
            /**
             * @var string 阻擋項目值
             *
             * @example "login"
             */
            'block_items.*' => ['required', 'string', new Enum(IpRestrictionBlockItemEnum::class)],
            /**
             * @var string 內部備註
             *
             * @example "DDoS Suspect"
             */
            'remark' => 'nullable|string|max:191',
            /**
             * @var int 規則狀態 1:啟用 0:關閉
             *
             * @example 1
             */
            'status' => 'required|in:0,1',
        ];
    }
}
