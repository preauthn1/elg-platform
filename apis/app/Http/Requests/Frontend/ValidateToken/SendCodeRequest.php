<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ValidateToken;

use App\Enums\ValidationCodeTypeEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rules\Enum;

class SendCodeRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 域名
             *
             * @example example.invalid
             */
            'domain' => 'required|string',
            /**
             * 驗證類型：參考 ValidationCodeTypeEnum
             *
             * @example 1
             */
            'type' => ['required', new Enum(ValidationCodeTypeEnum::class)],
            /**
             * 會員編號, type=1 資料驗證時為必填
             *
             * @example 1
             */
            // 'member_id' => 'required_if:type,1,5,6,7,9|integer',
            /**
             * 會員帳號, 由營運商設定是否必填
             *
             * @example test1234
             */
            'username' => 'nullable|alpha_num|between:4,30',
            /**
             * 驗證方式：1=手機, 2=電子信箱
             */
            'verify_by' => 'required|integer|in:1,2',
            /**
             * 手機號碼
             *
             * @example 1111111111
             */
            'mobile' => 'required_if:verify_by,1|digits_between:1,20',
            /**
             * 電子信箱
             *
             * @example user@example.invalid
             */
            'email' => 'required_if:verify_by,2|email|between:1,200',
            /**
             * 支付密碼
             *
             * @example ********
             */
            'payment_password' => 'required_if:type,5|string|between:6,12|confirmed',
            /**
             * 強制指定簡訊商配置 ID（選填，僅限 verify_by=1）
             *
             * @example 34
             */
            'sms_gateway_info_id' => 'nullable|integer|exists:sms_gateway_infos,id',

            /**
             * 是否改以 WhatsApp 管道發送驗證碼（選填，僅限 verify_by=1；未傳視為 false 走一般簡訊）
             *
             * @example false
             */
            'send_by_whatsapp' => 'nullable|boolean',

            /**
             * 遊客 Token, type=3的時候必填 (找回密碼時判斷輸入的手機號碼跟之前帳密錯誤時是否為同個帳號)
             *
             * @example 123e4567-e89b-12d3-a456-426614174000
             */
            'guest_validate_token' => 'nullable|string',

            /**
             * 滑塊驗證通過的一次性憑證，代理開啟 send_code_captcha_enabled 時必填（4188）
             *
             * @example 8Xf2kQ...
             */
            'captcha_token' => 'nullable|string|max:64',
        ];
    }
}
