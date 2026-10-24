<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EmailGateway;

use App\Enums\EmailGatewaySchemeEnum;
use App\Enums\StatusEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

class EmailGatewayUpdateRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 電子郵件提供商ID
             *
             * @example 1
             */
            'id' => 'required|integer',
            /**
             * @var string 寄件信箱, 同時作為 SMTP 帳號
             *
             * @example "user@example.invalid"
             */
            'email' => 'required|email|max:191',
            /**
             * @var string SMTP 密碼, 留空表示不變更
             *
             * @example "app-password"
             */
            'password' => 'nullable|string|max:191',
            /**
             * @var string SMTP 主機
             *
             * @example "example.invalid"
             */
            'host' => 'required|string|max:191',
            /**
             * @var int SMTP 連接埠
             *
             * @example 587
             */
            'port' => 'required|integer|min:1|max:65535',
            /**
             * @var string 連線協定(smtp:STARTTLS, smtps:SSL)
             *
             * @example "smtp"
             */
            'scheme' => ['required', 'string', Rule::enum(EmailGatewaySchemeEnum::class)],
            /**
             * @var string 寄件人顯示名稱, 未填時取系統設定
             *
             * @example "Example"
             */
            'from_name' => 'nullable|string|max:191',
            /**
             * @var int 排序, 同營運商多筆啟用時由小到大挑選
             *
             * @example 0
             */
            'sort' => 'nullable|integer',
            /**
             * @var int 狀態(0:停用, 1:啟用)
             *
             * @example 1
             */
            'status' => ['required', 'integer', Rule::enum(StatusEnum::class)],
        ];
    }
}
