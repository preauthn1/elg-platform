<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EmailGateway;

use App\Enums\EmailTextTypeEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

class EmailGroupSettingTextSaveRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 電子郵件設置組別ID(agent_groups.id, agent_group_type=7)
             *
             * @example 4249
             */
            'group_id' => 'required|integer',
            /**
             * @var array 該組別的全部樣板(整組覆寫); 8 個類型每個都必須含 language_id=0 的全語系列
             */
            'email_text' => 'required|array|min:1',
            /**
             * @var int 樣板類型 1-8, 見 EmailTextTypeEnum
             *
             * @example 8
             */
            'email_text.*.type_id' => ['required', 'integer', Rule::enum(EmailTextTypeEnum::class)],
            /**
             * @var int 語系ID(languages.id); 0=全語系
             *
             * @example 0
             */
            'email_text.*.language_id' => 'required|integer|min:0',
            /**
             * @var string 郵件主旨(純文字, 可含 {code} {email})
             *
             * @example "Verification Code"
             */
            'email_text.*.subject' => 'required|string|max:200',
            /**
             * @var string 郵件內文(純文字, 可含 {code} {email})
             *
             * @example "The PIN for {email} is: 【{code}】"
             */
            'email_text.*.content' => 'required|string|max:5000',
        ];
    }
}
