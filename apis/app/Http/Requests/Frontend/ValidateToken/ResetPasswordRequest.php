<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ValidateToken;

use App\Http\Requests\AbstractRequest;

class ResetPasswordRequest extends AbstractRequest
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
             * 會員帳號
             *
             * @example vivian
             */
            'username' => 'required|string',
            // 'username' => 'required|r_alpha_num|between:4,10|unique:members|unique:agents',
            /**
             * 驗證碼 (六位數字)
             *
             * @example 123456
             */
            'token' => 'required|digits:6',
            /**
             * 新密碼；字數與字元規則依代理的註冊欄位設定，於 controller 檢查
             *
             * @example abc123
             */
            'new_password' => 'required|string|confirmed',
        ];
    }
}
