<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\IpRestriction;

use App\Enums\IpRestrictionBlockItemEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateRequest extends AbstractRequest
{
    /**
     * IP 可改（本功能無 delete，打錯的 IP 需能修正）；repeat_count 為系統累計，不可入參。
     */
    public function rules(): array
    {
        return [
            /**
             * @var int 規則編號
             *
             * @example 1
             */
            'id' => 'required|integer',
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
