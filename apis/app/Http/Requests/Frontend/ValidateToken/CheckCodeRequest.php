<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ValidateToken;

use App\Enums\ValidationCodeTypeEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rules\Enum;

class CheckCodeRequest extends AbstractRequest
{
    /**
     * 工單 4540：驗證碼前後多帶的空白先去掉再比對；中間的空白不動，交給 digits:6 擋。
     *
     * 全域 TrimStrings 幫不上忙：DecryptRequest 在它之後才把解密的參數放進 request。
     * 非字串（例如數字或陣列）不處理，交給驗證規則判斷。
     */
    protected function prepareForValidation(): void
    {
        $token = [REDACTED]'token');

        if (is_string($token)) {
            $this->merge(['token' => trim($token)]);
        }
    }

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
             * 手機號碼
             *
             * @example 1111111111
             */
            'mobile' => 'required_without:email|digits_between:1,20',
            /**
             * 電子信箱
             *
             * @example user@example.invalid
             */
            'email' => 'required_without:mobile|email|between:1,60',
            /**
             * 驗證碼 (六位數字)
             *
             * @example 123456
             */
            'token' => 'required|digits:6',

            /**
             * 驗證類型：參考 ValidationCodeTypeEnum
             */
            'type' => ['nullable', new Enum(ValidationCodeTypeEnum::class)],

            /**
             * 遊客 Token, (找回密碼時判斷輸入的手機號碼跟之前帳密錯誤時是否為同個帳號)
             *
             * @example 123e4567-e89b-12d3-a456-426614174000
             */
            'guest_validate_token' => 'nullable|string',
        ];
    }
}
