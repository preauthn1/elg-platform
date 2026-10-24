<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend;

use App\Http\Requests\AbstractRequest;
use App\Rules\Username;

class RegisterRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 代理的邀請碼, 可為空
             *
             * @example 123456
             */
            'invitation_code' => ['nullable', 'string', 'between:4,10'],
            /**
             * 代理推薦碼類型
             *
             * @example 1
             */
            'invitation_code_type' => ['nullable', 'integer'],
            /**
             * 帳號 (不可重覆)
             *
             * @example 123456
             */
            'username' => ['required', 'string', 'between:4,10', 'unique:members', new Username],
            /**
             * 密碼
             */
            'password' => ['required', 'string', 'confirmed', 'between:6,12'],
            /**
             * 電子信箱 (不可重覆)
             */
            'email' => ['nullable', 'email', 'unique:members'],
            /**
             * 暱稱 (不可重覆)
             */
            'nickname' => ['nullable', 'string', 'between:0,60', 'unique:members'],
            /**
             * 名字
             */
            'first_name' => ['nullable', 'string', 'between:0,60'],
            /**
             * 姓氏
             */
            'last_name' => ['nullable', 'string', 'alpha_dash:ascii', 'between:0,20'],
            /**
             * 生日
             *
             * @example 1989-06-04
             */
            'birthday' => ['nullable', 'string', 'date_format:Y-m-d', 'before:-18 years'],
            /**
             * 手機號 (不同代理可重覆)
             *
             * @example 0912345678
             */
            'mobile' => ['nullable', 'string', 'digits_between:0,20'],
            /**
             * 手機驗證碼
             *
             * @example 5566
             */
            'mobile_token' => ['nullable', 'string'],
            /**
             * 時區系統編號
             *
             * @example 1
             */
            'timezone_id' => ['nullable', 'string', 'between:0,35'],
            /**
             * 國家編號
             *
             * @example 1
             */
            'country_id' => ['nullable', 'string', 'between:0,35'],
            /**
             * 郵遞區號
             *
             * @example 426
             */
            'zip_code' => ['nullable', 'string', 'between:0,35'],
            /**
             * 地區
             *
             * @example 新社區
             */
            'district' => ['nullable', 'string', 'between:0,35'],
            /**
             * 城市
             *
             * @example 台中市
             */
            'city' => ['nullable', 'string', 'between:0,35'],
            /**
             * 地址
             *
             * @example 興社街一段國校巷13號
             */
            'address' => ['nullable', 'string', 'between:0,60'],

            /**
             * 註冊網址
             *
             * @example example.invalid
             */
            'domain' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            // 'birthday.before' => __('error_message.1008'),
            'birthday.before' => 'The user must be at least 18 years old to register.',
            'username.unique' => __('validation.attribute_taken'),
        ];
    }

    public function attributes(): array
    {
        return [
            'username' => __('validation.attributes.player_account'),
        ];
    }
}
